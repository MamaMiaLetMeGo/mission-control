<?php

namespace Database\Factories;

use App\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Goal> */
class GoalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'request' => fake()->sentence(),
            'status' => 'planned',
            'settings' => [],
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => ['status' => 'queued']);
    }

    public function planning(): static
    {
        return $this->state(fn () => ['status' => 'planning']);
    }

    public function ideas(): static
    {
        return $this->state(fn () => ['source' => 'ideas', 'request' => 'Ideas from the leads']);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed']);
    }
}
