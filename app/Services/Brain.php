<?php

namespace App\Services;

use App\Models\BrainRun;
use App\Models\Decision;
use App\Models\Lead;
use App\Models\Preference;
use Illuminate\Database\Eloquent\Collection;

class Brain
{
    public const MAX_DECISIONS = 50;

    /**
     * Make a preference safe to place in a prompt: one line, no control characters, at most 300 characters.
     */
    public function clean(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

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
            $found = $found->concat($batch->filter(fn (Decision $decision): bool => trim((string) $decision->feedback) !== ''));
        }

        return $found->take(self::MAX_DECISIONS)->values();
    }
}
