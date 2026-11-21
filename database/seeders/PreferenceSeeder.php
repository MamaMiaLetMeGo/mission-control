<?php

namespace Database\Seeders;

use App\Models\Preference;
use Illuminate\Database\Seeder;

class PreferenceSeeder extends Seeder
{
    public function run(): void
    {
        Preference::query()->firstOrCreate(
            ['text' => 'Keep onboarding steps short and plain.'],
            ['status' => 'active', 'source' => 'manual'],
        );
    }
}
