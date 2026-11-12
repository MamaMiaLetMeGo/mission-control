<?php

namespace Database\Factories;

use App\Models\Decision;
use App\Models\MissionTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Decision> */
class DecisionFactory extends Factory
{
    public function definition(): array
    {
        return ['mission_task_id' => MissionTask::factory(), 'action' => 'request_changes', 'feedback' => 'Keep the onboarding steps short.'];
    }
}
