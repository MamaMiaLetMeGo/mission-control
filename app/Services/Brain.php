<?php

namespace App\Services;

use App\Jobs\LearnPreferences;
use App\Models\BrainRun;
use App\Models\Decision;
use App\Models\Lead;
use App\Models\Preference;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class Brain
{
    public const MAX_DECISIONS = 50;

    public const MAX_CHANGES = 10;

    public const MAX_EVIDENCE = 50;

    public function __construct(private MissionSettings $settings, private TokenBudget $tokens) {}

    /**
     * Make a preference safe to place in a prompt: one line, no control characters, at most 300 characters.
     */
    public function clean(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u', ' ', $text) ?? '';
        $text = preg_replace('/[\s\p{Z}]+/u', ' ', $text) ?? '';

        return trim(mb_substr(trim($text), 0, 300));
    }

    /**
     * Active preferences that apply to everyone or to the given lead, ready for an agent prompt.
     *
     * @return array<int, string>
     */
    public function forPrompt(?Lead $lead = null): array
    {
        return Preference::query()->active()
            ->where(function ($query) use ($lead) {
                $query->whereNull('lead_id');
                if ($lead !== null) {
                    $query->orWhere('lead_id', $lead->id);
                }
            })
            ->orderBy('id')->limit(Preference::MAX_ACTIVE)->pluck('text')
            ->map(fn (string $text): string => $this->clean($text))
            ->filter(fn (string $text): bool => $text !== '')
            ->values()->all();
    }

    /**
     * Every active preference with the key of the lead it applies to, for the planner.
     *
     * @return array<int, array{text: string, lead: ?string}>
     */
    public function forPlanner(): array
    {
        return Preference::query()->active()->with('lead')
            ->orderBy('id')->limit(Preference::MAX_ACTIVE)->get()
            ->map(fn (Preference $preference): array => [
                'text' => $this->clean($preference->text),
                'lead' => $preference->lead?->key,
            ])
            ->filter(fn (array $preference): bool => $preference['text'] !== '')
            ->values()->all();
    }

    /**
     * Human decisions with feedback that no completed Brain run has read yet, oldest first.
     *
     * @return Collection<int, Decision>
     */
    public function pendingDecisions(): Collection
    {
        $after = (int) BrainRun::query()->where('status', 'completed')->max('last_decision_id');
        $found = new Collection;
        $cursor = $after;

        while ($found->count() < self::MAX_DECISIONS) {
            $batch = Decision::query()
                ->whereIn('action', Decision::HUMAN_ACTIONS)
                ->whereNotNull('feedback')
                ->where('id', '>', $cursor)
                ->with('task.lead')
                ->orderBy('id')->limit(self::MAX_DECISIONS)->get();

            if ($batch->isEmpty()) {
                break;
            }

            $cursor = (int) $batch->last()->id;
            $found = $found->concat($batch->filter(fn (Decision $decision): bool => $this->clean((string) $decision->feedback) !== ''));
        }

        return $found->take(self::MAX_DECISIONS)->values();
    }

    /**
     * Queue a learning run when one is due. An automatic start waits for enough decisions with feedback and returns null
     * when learning cannot start; a manual start ignores both settings and throws a ValidationException with the reason.
     */
    public function start(bool $manual = false): ?BrainRun
    {
        return Cache::lock('mission-brain', 30)->block(5, function () use ($manual): ?BrainRun {
            if (BrainRun::query()->whereIn('status', ['queued', 'learning'])->exists()) {
                return $this->refuse($manual, 'The Brain is already learning.');
            }
            $pending = $this->pendingDecisions();
            if ($pending->isEmpty()) {
                return $this->refuse($manual, 'There are no new decisions with feedback to learn from.');
            }
            $settings = $this->settings->all();
            if (! $manual && (! $settings['brain_enabled'] || $pending->count() < (int) $settings['brain_learn_after'])) {
                return null;
            }
            $readiness = $this->settings->readiness();
            foreach (['api_key', 'worker'] as $check) {
                if (! $readiness[$check]) {
                    return $this->refuse($manual, MissionSettings::READINESS_MESSAGES[$check]);
                }
            }
            if ($this->tokens->exhausted()) {
                return $this->refuse($manual, 'The daily token limit of '.$this->tokens->limit().' has been reached. Raise it in Settings or wait until tomorrow.');
            }

            return DB::transaction(function () use ($pending, $settings): BrainRun {
                $run = BrainRun::query()->create([
                    'status' => 'queued',
                    'settings' => $settings,
                    'events' => [],
                    'decision_ids' => $pending->pluck('id')->all(),
                    'last_decision_id' => (int) $pending->max('id'),
                ]);
                LearnPreferences::dispatch($run->id)->afterCommit();

                return $run;
            });
        });
    }

    /**
     * Apply the Brain's changes: retirements, then updates, then adds up to the active cap, then complete the run.
     * Changes to preferences that were not sent to the Brain, or that the owner has since edited, switched off or deleted,
     * are skipped, as are updates and adds duplicating another active preference. A run no longer learning is left alone. Throws InvalidArgumentException, applying
     * nothing, when the result breaks any limit.
     *
     * @param  array<string, mixed>  $result
     */
    public function apply(BrainRun $run, array $result): void
    {
        [$summary, $changes] = $this->validated($run, $result);
        try {
            $this->withPreferenceLock(fn () => $this->applyChanges($run, $summary, $changes));
        } catch (LockTimeoutException) {
            throw new RuntimeException('Preferences were being changed at the same time. Try learning again in a moment.');
        }
    }

    /**
     * Run a change that can raise the number of active preferences while holding the one lock every such change takes.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withPreferenceLock(Closure $callback): mixed
    {
        return Cache::lock('mission-preferences', 30)->block(5, $callback);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function applyChanges(BrainRun $run, string $summary, array $changes): void
    {
        DB::transaction(function () use ($run, $summary, $changes): void {
            $current = BrainRun::query()->lockForUpdate()->find($run->id);
            if (! $current || $current->status !== 'learning') {
                return;
            }
            $sent = (array) ($current->settings['sent_preferences'] ?? []);
            $preferences = Preference::query()->lockForUpdate()->get()->keyBy('id');
            $target = function (int $id) use ($preferences, $sent): ?Preference {
                $preference = $preferences->get($id);
                $unchanged = array_key_exists($id, $sent) && $preference && $preference->text === $sent[$id];

                return $unchanged && $preference->status === 'active' && $preference->source === 'learned' ? $preference : null;
            };
            $duplicate = fn (string $text, ?int $except = null): bool => $preferences
                ->contains(fn (Preference $preference): bool => $preference->id !== $except && $preference->status === 'active'
                    && mb_strtolower($this->clean($preference->text)) === mb_strtolower($text));
            foreach (array_filter($changes, fn (array $change): bool => $change['op'] === 'retire') as $change) {
                $target($change['id'])?->update(['status' => 'off', 'note' => $change['reason']]);
            }
            foreach (array_filter($changes, fn (array $change): bool => $change['op'] === 'update') as $change) {
                $preference = $target($change['id']);
                if ($preference && ! $duplicate($change['text'], $preference->id)) {
                    $evidence = array_values(array_unique([...array_filter((array) $preference->evidence, 'is_int'), ...$change['evidence']]));
                    $preference->update(['text' => $change['text'], 'evidence' => array_slice($evidence, -self::MAX_EVIDENCE), 'seen_at' => null]);
                }
            }
            $count = $preferences->where('status', 'active')->count();
            $leads = Lead::query()->pluck('id', 'key');
            $capped = 0;
            foreach ($changes as $change) {
                if ($change['op'] !== 'add' || $duplicate($change['text'])) {
                    continue;
                }
                if ($count >= Preference::MAX_ACTIVE) {
                    $capped++;

                    continue;
                }
                $preferences->push(Preference::query()->create([
                    'text' => $change['text'],
                    'lead_id' => $change['lead'] === null ? null : $leads->get($change['lead']),
                    'status' => 'active',
                    'source' => 'learned',
                    'evidence' => $change['evidence'],
                    'seen_at' => null,
                ]));
                $count++;
            }
            $current->update([
                'status' => 'completed',
                'summary' => $summary.($capped > 0 ? " {$capped} preference(s) were not added because ".Preference::MAX_ACTIVE.' are already active.' : ''),
                'error' => null,
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: list<array{op: 'add', text: string, lead: ?string, evidence: list<int>}|array{op: 'update', id: int, text: string, evidence: list<int>}|array{op: 'retire', id: int, reason: string}>}
     */
    private function validated(BrainRun $run, array $result): array
    {
        $summary = $result['summary'] ?? null;
        if (! is_string($summary) || trim($summary) === '' || mb_strlen($summary) > 2000 || str_contains($summary, "\0") || ! mb_check_encoding($summary, 'UTF-8')) {
            $this->invalid('The summary must be 1 to 2000 characters.');
        }
        $changes = $result['changes'] ?? null;
        if (! is_array($changes) || ! array_is_list($changes) || count($changes) > self::MAX_CHANGES) {
            $this->invalid('There must be at most '.self::MAX_CHANGES.' changes.');
        }
        $decisionIds = array_map('intval', (array) $run->decision_ids);
        $leadKeys = Lead::query()->pluck('key')->all();
        $targeted = [];
        $checked = [];
        foreach ($changes as $index => $change) {
            $position = $index + 1;
            if (! is_array($change)) {
                $this->invalid("Change {$position} is not an object.");
            }
            $op = $change['op'] ?? null;
            if ($op === 'add') {
                $lead = $change['lead'] ?? null;
                if ($lead !== null && (! is_string($lead) || ! in_array($lead, $leadKeys, true))) {
                    $this->invalid("Change {$position} names a lead that does not exist.");
                }
                $checked[] = ['op' => 'add', 'text' => $this->line($change['text'] ?? null, $position), 'lead' => $lead, 'evidence' => $this->evidence($change['evidence'] ?? null, $decisionIds, $position)];

                continue;
            }
            if ($op !== 'update' && $op !== 'retire') {
                $this->invalid("Change {$position} has an unknown op.");
            }
            $id = $change['id'] ?? null;
            if (! is_int($id) || $id < 1 || in_array($id, $targeted, true)) {
                $this->invalid("Change {$position} must target a preference no other change targets.");
            }
            $targeted[] = $id;
            $checked[] = $op === 'update'
                ? ['op' => 'update', 'id' => $id, 'text' => $this->line($change['text'] ?? null, $position), 'evidence' => $this->evidence($change['evidence'] ?? null, $decisionIds, $position)]
                : ['op' => 'retire', 'id' => $id, 'reason' => $this->line($change['reason'] ?? null, $position)];
        }

        return [$summary, $checked];
    }

    private function line(mixed $value, int $position): string
    {
        $clean = is_string($value) ? $this->clean($value) : '';
        if ($clean === '' || mb_strlen($value) > 300 || preg_match('/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u', $value) !== 0) {
            $this->invalid("Change {$position} needs one line of 1 to 300 characters.");
        }

        return $clean;
    }

    /**
     * @param  list<int>  $decisionIds
     * @return list<int>
     */
    private function evidence(mixed $evidence, array $decisionIds, int $position): array
    {
        if (! is_array($evidence) || ! array_is_list($evidence) || count($evidence) < 1 || count($evidence) > 10
            || count($evidence) !== count(array_unique($evidence, SORT_REGULAR))) {
            $this->invalid("Change {$position} must cite 1 to 10 different decisions.");
        }
        foreach ($evidence as $id) {
            if (! is_int($id) || ! in_array($id, $decisionIds, true)) {
                $this->invalid("Change {$position} cites a decision the Brain was not given.");
            }
        }

        return $evidence;
    }

    private function invalid(string $message): never
    {
        throw new InvalidArgumentException($message);
    }

    private function refuse(bool $manual, string $message): null
    {
        if ($manual) {
            throw ValidationException::withMessages(['brain' => $message]);
        }

        return null;
    }
}
