<?php

namespace Database\Seeders;

use App\Models\Goal;
use Illuminate\Database\Seeder;

class GoalSeeder extends Seeder
{
    public function run(): void
    {
        Goal::query()->firstOrCreate(
            ['request' => 'Add a contact form to the marketing site and email submissions to the team.'],
            ['status' => 'planned', 'settings' => []],
        );
    }
}
