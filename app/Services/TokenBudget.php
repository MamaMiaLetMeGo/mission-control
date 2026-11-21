<?php

namespace App\Services;

use App\Models\AgentRun;

class TokenBudget
{
    public function __construct(private MissionSettings $settings) {}

    /**
     * Input plus output tokens reported by every run created since the start of today.
     */
    public function usedToday(): int
    {
        return (int) AgentRun::query()->where('created_at', '>=', now()->startOfDay())->whereNotNull('usage')->pluck('usage')
            ->sum(fn (mixed $usage): int => is_array($usage) ? (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0) : 0);
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
