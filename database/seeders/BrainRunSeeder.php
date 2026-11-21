<?php

namespace Database\Seeders;

use App\Models\BrainRun;
use Illuminate\Database\Seeder;

class BrainRunSeeder extends Seeder
{
    public function run(): void
    {
        BrainRun::query()->firstOrCreate(
            ['summary' => 'No decisions to learn from yet.'],
            ['status' => 'completed', 'settings' => [], 'decision_ids' => [], 'last_decision_id' => 0],
        );
    }
}
