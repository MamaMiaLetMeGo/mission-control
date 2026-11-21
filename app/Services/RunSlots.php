<?php

namespace App\Services;

use App\Models\AgentRun;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class RunSlots
{
    public function __construct(private MissionSettings $settings) {}

    /**
     * Claim the first free numbered execution slot, or null when every slot is taken.
     */
    public function acquire(int $seconds): ?Lock
    {
        for ($slot = 1; $slot <= $this->limit(); $slot++) {
            $lock = Cache::lock('mission-execution:'.$slot, $seconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    public function limit(): int
    {
        return max(1, (int) $this->settings->all()['max_parallel_runs']);
    }

    public function busy(): int
    {
        return AgentRun::query()->where('status', 'running')->count();
    }
}
