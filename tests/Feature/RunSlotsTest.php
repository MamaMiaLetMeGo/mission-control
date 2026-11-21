<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\MissionSetting;
use App\Services\RunSlots;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RunSlotsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_slots_are_limited_to_the_configured_number_of_parallel_runs(): void
    {
        config(['mission.max_parallel_runs' => 2]);
        $slots = app(RunSlots::class);

        $first = $slots->acquire(60);
        $second = $slots->acquire(60);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNull($slots->acquire(60));

        $first->release();
        $third = $slots->acquire(60);

        $this->assertNotNull($third);
        $second->release();
        $third->release();
    }

    public function test_the_limit_reads_a_saved_setting(): void
    {
        config(['mission.max_parallel_runs' => 3]);
        MissionSetting::query()->create(['key' => 'max_parallel_runs', 'value' => 1]);

        $this->assertSame(1, app(RunSlots::class)->limit());
    }

    public function test_the_limit_is_clamped_to_the_allowed_range(): void
    {
        config(['mission.max_parallel_runs' => 10]);
        $this->assertSame(4, app(RunSlots::class)->limit());

        config(['mission.max_parallel_runs' => 0]);
        $this->assertSame(1, app(RunSlots::class)->limit());
    }

    public function test_busy_counts_running_agent_runs(): void
    {
        AgentRun::factory()->count(2)->create(['status' => 'running']);
        AgentRun::factory()->create(['status' => 'queued']);

        $this->assertSame(2, app(RunSlots::class)->busy());
    }
}
