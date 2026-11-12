<?php

namespace Database\Factories;

use App\Models\MissionTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MissionTask> */
class MissionTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(5),
            'request' => fake()->paragraph(),
            'acceptance_criteria' => 'The requested behavior works and the relevant tests pass.',
            'area' => 'engineering',
            'priority' => 'normal',
            'status' => 'backlog',
        ];
    }

    public function readyForReview(): static
    {
        return $this->state(fn () => [
            'status' => 'review',
            'revision' => str_repeat('a', 40),
            'base_revision' => str_repeat('b', 40),
            'review_data' => ['success' => true, 'review' => ['verdict' => 'pass'], 'tests' => ['exitCode' => 0]],
        ]);
    }
}
