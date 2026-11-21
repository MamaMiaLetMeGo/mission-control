<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\BrainRun;
use App\Models\Goal;

class TokenBudget
{
    public function __construct(private MissionSettings $settings) {}

    /**
     * Input plus output tokens reported by every run, goal plan and Brain run created since the start of today.
     */
    public function usedToday(): int
    {
        $count = fn (mixed $usage): int => is_array($usage) ? (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0) : 0;
        $since = now()->startOfDay();

        return (int) AgentRun::query()->where('created_at', '>=', $since)->whereNotNull('usage')->pluck('usage')->sum($count)
            + (int) Goal::query()->where('created_at', '>=', $since)->whereNotNull('usage')->pluck('usage')->sum($count)
            + (int) BrainRun::query()->where('created_at', '>=', $since)->whereNotNull('usage')->pluck('usage')->sum($count);
    }

    public function limit(): int
    {
        return min(50000000, max(10000, (int) $this->settings->all()['daily_token_limit']));
    }

    public function exhausted(): bool
    {
        return $this->usedToday() >= $this->limit();
    }
}
