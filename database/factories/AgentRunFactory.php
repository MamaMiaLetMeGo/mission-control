<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\MissionTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentRun> */
class AgentRunFactory extends Factory
{
    public function definition(): array
    {
        return ['mission_task_id' => MissionTask::factory(), 'role' => 'engineering', 'status' => 'queued', 'settings' => [], 'events' => []];
    }
}
