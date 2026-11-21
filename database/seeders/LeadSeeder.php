<?php

namespace Database\Seeders;

use App\Models\Lead;
use Illuminate\Database\Seeder;

class LeadSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['key' => 'product', 'name' => 'Product Lead', 'instructions' => 'You own what gets built and why. Clarify the user problem, shape the scope and acceptance criteria, and keep copy and content clear and on-message.', 'position' => 1],
            ['key' => 'engineering', 'name' => 'Engineering Lead', 'instructions' => 'You own how it gets built. Implement changes correctly and simply, follow the existing code conventions, and prove the work with focused tests.', 'position' => 2],
            ['key' => 'design', 'name' => 'Design Lead', 'instructions' => 'You own how it looks and feels. Keep the interface consistent, accessible, and responsive, and reuse existing components and styles before inventing new ones.', 'position' => 3],
            ['key' => 'operations', 'name' => 'Operations Lead', 'instructions' => 'You own how it runs and ships. Keep deployments, configuration, and data migrations safe and reversible, and watch for anything that could break a live install.', 'position' => 4],
        ] as $lead) {
            Lead::query()->firstOrCreate(['key' => $lead['key']], $lead + ['is_active' => true]);
        }
    }
}
