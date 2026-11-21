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
use Throwable;

class ChiefOfStaff
{
    public const MAX_BRIEFS = 8;

    public function __construct(private MissionSettings $settings, private TokenBudget $tokens) {}

    /**
     * Record a goal and queue its planning, unless the app is not ready to plan it.
     */
    public function start(string $request): Goal
    {
        return Cache::lock('mission-workflow', 120)->block(5, function () use ($request): Goal {
            $readiness = $this->settings->readiness();
            if (! $readiness['ready']) {
                $this->reject(implode(' ', $readiness['issues']));
            }
            if ($this->tokens->exhausted()) {
                $this->reject('The daily token limit of '.$this->tokens->limit().' has been reached. Raise it in Settings or wait until tomorrow.');
            }
            if (! Lead::query()->active()->exists()) {
                $this->reject('Add an active lead in Settings before giving a goal.');
            }
            $settings = $this->settings->all();
            $repository = realpath($settings['repository_path']);
            $settings['repository_path'] = $repository !== false ? $repository : $settings['repository_path'];

            return DB::transaction(function () use ($request, $settings): Goal {
                $goal = Goal::query()->create(['request' => $request, 'status' => 'queued', 'settings' => $settings, 'events' => []]);
                PlanGoal::dispatch($goal->id)->afterCommit();

                return $goal;
            });
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
            $candidates = MissionTask::query()->where('status', 'backlog')->whereNotNull('goal_id')
                ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
                ->orderBy('goal_id')->orderBy('goal_position')->orderBy('id')->get();
            foreach ($candidates as $task) {
                if ($started >= $capacity) {
                    break;
                }
                if ($task->waitingOn()->isNotEmpty()) {
                    continue;
                }
                try {
                    $workflow->queue($task);
                    $started++;
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
     *
     * @param  array<string, mixed>  $plan
     */
    public function createTasks(Goal $goal, array $plan): void
    {
        $briefs = $this->validated($plan);
        $leads = Lead::query()->active()->pluck('id', 'key');
        DB::transaction(function () use ($goal, $plan, $briefs, $leads): void {
            $ids = [];
            foreach ($briefs as $index => $brief) {
                $task = MissionTask::query()->create([
                    'goal_id' => $goal->id,
                    'goal_position' => $index + 1,
                    'lead_id' => $leads[$brief['lead']],
                    'title' => $brief['title'],
                    'request' => $brief['request'],
                    'acceptance_criteria' => $brief['acceptanceCriteria'],
                    'priority' => $brief['priority'],
                    'status' => 'backlog',
                    'depends_on' => $brief['after'] === [] ? null : array_map(fn (int $position): int => $ids[$position], $brief['after']),
                ]);
                $ids[$index + 1] = $task->id;
            }
            $goal->update(['status' => 'planned', 'overview' => $plan['overview'], 'error' => null, 'finished_at' => now()]);
        });
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array{lead: string, title: string, request: string, acceptanceCriteria: string, priority: string, after: list<int>}>
     */
    private function validated(array $plan): array
    {
        $overview = $plan['overview'] ?? null;
        $briefs = $plan['briefs'] ?? null;
        if (! is_string($overview) || ! $this->within($overview, 4000)) {
            $this->invalid('The overview must be 1 to 4000 characters.');
        }
        if (! is_array($briefs) || ! array_is_list($briefs) || count($briefs) < 1 || count($briefs) > self::MAX_BRIEFS) {
            $this->invalid('The plan must have 1 to '.self::MAX_BRIEFS.' briefs.');
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

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['goal' => $message]);
    }
}
