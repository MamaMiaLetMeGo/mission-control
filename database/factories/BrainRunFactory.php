<?php

namespace Database\Factories;

use App\Models\BrainRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrainRun> */
class BrainRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'status' => 'completed',
            'settings' => [],
            'decision_ids' => [],
            'last_decision_id' => 0,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => ['status' => 'queued']);
    }

    public function learning(): static
    {
        return $this->state(fn () => ['status' => 'learning']);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed']);
    }
}
