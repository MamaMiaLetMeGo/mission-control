<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MissionTask;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_four_leads_exist_after_migration(): void
    {
        $this->assertSame(['product', 'engineering', 'design', 'operations'], Lead::query()->orderBy('position')->pluck('key')->all());
        $this->assertSame('Engineering Lead', Lead::query()->where('key', 'engineering')->value('name'));
    }

    public function test_a_task_belongs_to_a_lead(): void
    {
        $lead = Lead::query()->where('key', 'design')->firstOrFail();
        $task = MissionTask::factory()->for($lead)->create();

        $this->assertTrue($task->lead->is($lead));
        $this->assertFalse(Schema::hasColumn('mission_tasks', 'area'));
    }

    public function test_active_scope_hides_deactivated_leads_in_position_order(): void
    {
        Lead::query()->where('key', 'design')->update(['is_active' => false]);

        $this->assertSame(['product', 'engineering', 'operations'], Lead::query()->active()->pluck('key')->all());
    }
}
