<?php

namespace App\Services;

use App\Jobs\PlanGoal;
use App\Models\AgentRun;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\MissionTask;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ChiefOfStaff
{
    public const MAX_BRIEFS = 8;

    public const MAX_OPEN_IDEAS = 10;

    public const MAX_IDEAS = 5;

    /**
     * The share of the daily token limit that ideas may use automatically, leaving the rest for the owner's goals.
     */
    public const IDEAS_TOKEN_SHARE = 0.5;

    /**
     * Minutes without a worker event after which a planning ideas run is treated as crashed.
     */
    public const STALE_IDEAS_MINUTES = 20;

    public function __construct(private MissionSettings $settings, private TokenBudget $tokens) {}

    /**
     * Record a goal and queue its planning, unless the app is not ready to plan it.
     */
    public function start(string $request): Goal
    {
        return Cache::lock('mission-workflow', 120)->block(5, function () use ($request): Goal {
            $refusal = $this->refusalToPlan('Add an active lead in Settings before giving a goal.');
            if ($refusal !== null) {
                $this->reject($refusal);
            }

            return $this->queueGoal(['request' => $request]);
        });
    }

    /**
     * Ask the leads for ideas. An automatic call returns null when no ideas run is due or one is refused; a manual
     * call skips the daily schedule and throws a ValidationException explaining any refusal.
     */
    public function propose(bool $manual = false): ?Goal
    {
        return Cache::lock('mission-workflow', 120)->block(5, function () use ($manual): ?Goal {
            $this->failStaleIdeasRuns();
            if (Goal::query()->where('source', 'ideas')->whereIn('status', ['queued', 'planning'])->exists()) {
                return $this->refuseIdeas($manual, 'The leads are already working on ideas.');
            }
            if (! $manual && (! $this->settings->all()['ideas_enabled'] || $this->ideasShareUsed() || Goal::query()->where('source', 'ideas')->where('created_at', '>', now()->subHours(23))->exists())) {
                return null;
            }
            if ($this->openIdeas() >= self::MAX_OPEN_IDEAS) {
                return $this->refuseIdeas($manual, self::MAX_OPEN_IDEAS.' ideas are still open. Review or dismiss some before asking for more.');
            }
            $refusal = $this->refusalToPlan('Add an active lead in Settings before asking for ideas.');
            if ($refusal !== null) {
                return $this->refuseIdeas($manual, $refusal);
            }

            return $this->queueGoal(['source' => 'ideas', 'request' => 'Ideas from the leads']);
        });
    }

    /**
     * Start the backlog tasks of planned goals while an agent is free. Returns how many were started. Refused starts
     * (daily or token limits, readiness) leave the task in the backlog for a later pass.
     */
    public function dispatch(): int
    {
        if (! $this->settings->all()['auto_start']) {
            return 0;
        }
        $lock = Cache::lock('mission-dispatch', 60);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $capacity = app(RunSlots::class)->limit()
                - AgentRun::query()->whereIn('status', ['queued', 'running'])->count()
                - Goal::query()->whereIn('status', ['queued', 'planning'])->count();
            $started = 0;
            if ($capacity < 1) {
                return 0;
            }
            $workflow = app(MissionWorkflow::class);
            $ideasShareUsed = $this->ideasShareUsed();
            $candidates = MissionTask::query()->with('goal')->where('status', 'backlog')->whereNotNull('goal_id')
                ->orderByRaw('case when goal_id in (select id from goals where source = ?) then 1 else 0 end', ['ideas'])
                ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
                ->orderBy('goal_id')->orderBy('goal_position')->orderBy('id')->get();
            foreach ($candidates as $task) {
                if ($started >= $capacity) {
                    break;
                }
                if ($task->waitingOn()->isNotEmpty() || ($ideasShareUsed && $task->goal?->source === 'ideas')) {
                    continue;
                }
                try {
                    if ($workflow->queueBacklog($task)) {
                        $started++;
                    }
                } catch (ValidationException|LockTimeoutException) {
                    break;
                } catch (Throwable $exception) {
                    report($exception);
                    break;
                }
            }

            return $started;
        } finally {
            $lock->release();
        }
    }

    /**
     * Turn a plan into backlog tasks and mark the goal planned. Throws InvalidArgumentException for an unusable plan.
     * For an ideas goal only the first `ideas_per_run` briefs are kept; briefs beyond them are ignored unchecked, and the
     * kept briefs' dependencies are dropped before validation because ideas never depend on each other.
     *
     * @param  array<string, mixed>  $plan
     */
    public function createTasks(Goal $goal, array $plan): void
    {
        $isIdeas = $goal->source === 'ideas';
        $ideasLimit = max(1, min(self::MAX_IDEAS, (int) ($goal->settings['ideas_per_run'] ?? 3)));
        if ($isIdeas && is_array($plan['briefs'] ?? null) && array_is_list($plan['briefs'])) {
            $plan['briefs'] = array_map(fn (mixed $brief): mixed => is_array($brief) ? [...$brief, 'after' => []] : $brief, array_slice($plan['briefs'], 0, $ideasLimit));
        }
        $briefs = $isIdeas ? $this->validated($plan, 0, $ideasLimit, 'ideas') : $this->validated($plan);
        $leads = Lead::query()->active()->pluck('id', 'key');
        DB::transaction(function () use ($goal, $plan, $briefs, $leads, $isIdeas): void {
            $ids = [];
            foreach ($briefs as $index => $brief) {
                $task = MissionTask::query()->create([
                    'goal_id' => $goal->id,
                    'goal_position' => $index + 1,
                    'lead_id' => $leads[$brief['lead']],
                    'title' => $brief['title'],
                    'request' => $brief['request'],
                    'acceptance_criteria' => $brief['acceptanceCriteria'],
                    'priority' => $isIdeas && $brief['priority'] === 'urgent' ? 'high' : $brief['priority'],
                    'status' => 'backlog',
                    'depends_on' => $isIdeas || $brief['after'] === [] ? null : array_map(fn (int $position): int => $ids[$position], $brief['after']),
                ]);
                $ids[$index + 1] = $task->id;
            }
            $goal->update(['status' => 'planned', 'overview' => $plan['overview'], 'error' => null, 'finished_at' => now()]);
        });
    }

    /**
     * Record a queued goal with a snapshot of the settings and queue its planning once the goal is saved.
     *
     * @param  array{request: string, source?: string}  $attributes
     */
    private function queueGoal(array $attributes): Goal
    {
        $settings = $this->settings->all();
        $repository = realpath($settings['repository_path']);
        $settings['repository_path'] = $repository !== false ? $repository : $settings['repository_path'];

        return DB::transaction(function () use ($attributes, $settings): Goal {
            $goal = Goal::query()->create([...$attributes, 'status' => 'queued', 'settings' => $settings, 'events' => []]);
            PlanGoal::dispatch($goal->id)->afterCommit();

            return $goal;
        });
    }

    /**
     * Whether today's token use has reached the share set aside for ideas.
     */
    private function ideasShareUsed(): bool
    {
        return $this->tokens->usedToday() >= $this->tokens->limit() * self::IDEAS_TOKEN_SHARE;
    }

    /**
     * Fail ideas runs whose planning worker has gone silent, so they stop blocking new runs and agent slots. The worker
     * updates the goal on every event, so updated_at shows whether it is still alive.
     */
    private function failStaleIdeasRuns(): void
    {
        $staleIds = Goal::query()->where('source', 'ideas')->where('status', 'planning')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_IDEAS_MINUTES))->pluck('id');
        foreach ($staleIds as $goalId) {
            (new PlanGoal($goalId))->failed(new RuntimeException(PlanGoal::INTERRUPTED_IDEAS));
        }
    }

    /**
     * Why the Chief of Staff cannot plan right now, or null when it can.
     */
    private function refusalToPlan(string $noLeadMessage): ?string
    {
        $readiness = $this->settings->readiness();
        if (! $readiness['ready']) {
            return implode(' ', $readiness['issues']);
        }
        if ($this->tokens->exhausted()) {
            return 'The daily token limit of '.$this->tokens->limit().' has been reached. Raise it in Settings or wait until tomorrow.';
        }
        if (! Lead::query()->active()->exists()) {
            return $noLeadMessage;
        }

        return null;
    }

    /**
     * Idea tasks still waiting on the owner or an agent: everything not merged or rejected.
     */
    private function openIdeas(): int
    {
        return MissionTask::query()->whereNotIn('status', ['merged', 'rejected'])
            ->whereHas('goal', fn ($query) => $query->where('source', 'ideas'))->count();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array{lead: string, title: string, request: string, acceptanceCriteria: string, priority: string, after: list<int>}>
     */
    private function validated(array $plan, int $minimumBriefs = 1, int $maximumBriefs = self::MAX_BRIEFS, string $noun = 'briefs'): array
    {
        $overview = $plan['overview'] ?? null;
        $briefs = $plan['briefs'] ?? null;
        if (! is_string($overview) || ! $this->within($overview, 4000)) {
            $this->invalid('The overview must be 1 to 4000 characters.');
        }
        if (! is_array($briefs) || ! array_is_list($briefs) || count($briefs) < $minimumBriefs || count($briefs) > $maximumBriefs) {
            $this->invalid('The plan must have '.$minimumBriefs.' to '.$maximumBriefs.' '.$noun.'.');
        }
        $keys = Lead::query()->active()->pluck('key')->all();
        $checked = [];
        foreach ($briefs as $index => $brief) {
            $position = $index + 1;
            if (! is_array($brief)) {
                $this->invalid("Brief {$position} is not an object.");
            }
            if (! is_string($brief['lead'] ?? null) || ! in_array($brief['lead'], $keys, true)) {
                $this->invalid("Brief {$position} names a lead that is not active.");
            }
            foreach (['title' => 200, 'request' => 20000, 'acceptanceCriteria' => 10000] as $field => $limit) {
                if (! is_string($brief[$field] ?? null) || ! $this->within($brief[$field], $limit)) {
                    $this->invalid("Brief {$position} has an invalid {$field}.");
                }
            }
            if (! is_string($brief['priority'] ?? null) || ! in_array($brief['priority'], MissionTask::PRIORITIES, true)) {
                $this->invalid("Brief {$position} has an invalid priority.");
            }
            $after = $brief['after'] ?? [];
            if (! is_array($after) || ! array_is_list($after) || count($after) !== count(array_unique($after))) {
                $this->invalid("Brief {$position} has invalid dependencies.");
            }
            foreach ($after as $dependency) {
                if (! is_int($dependency) || $dependency < 1 || $dependency >= $position) {
                    $this->invalid("Brief {$position} may only follow earlier briefs.");
                }
            }
            $checked[] = ['lead' => $brief['lead'], 'title' => $brief['title'], 'request' => $brief['request'], 'acceptanceCriteria' => $brief['acceptanceCriteria'], 'priority' => $brief['priority'], 'after' => $after];
        }

        return $checked;
    }

    private function within(string $value, int $limit): bool
    {
        $length = mb_strlen($value);

        return $length >= 1 && $length <= $limit;
    }

    private function invalid(string $message): never
    {
        throw new InvalidArgumentException($message);
    }

    /**
     * An automatic ideas call gives up quietly; a manual one explains why.
     */
    private function refuseIdeas(bool $manual, string $message): null
    {
        if ($manual) {
            throw ValidationException::withMessages(['ideas' => $message]);
        }

        return null;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['goal' => $message]);
    }
}
