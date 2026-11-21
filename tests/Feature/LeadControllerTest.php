<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MissionTask;
use Database\Seeders\LeadSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LeadControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_new_lead_can_be_added(): void
    {
        $this->post(route('leads.store'), ['name' => 'Growth Lead', 'instructions' => 'Own acquisition experiments.'])->assertRedirect(route('settings.edit'));

        $lead = Lead::query()->where('key', 'growth-lead')->sole();
        $this->assertTrue($lead->is_active);
        $this->assertSame(5, $lead->position);
    }

    public function test_a_duplicate_name_is_refused(): void
    {
        $this->post(route('leads.store'), ['name' => 'Design', 'instructions' => 'x'])->assertSessionHasErrors('name');
    }

    public function test_a_deactivated_lead_keeps_its_tasks_runnable_and_named(): void
    {
        $lead = Lead::query()->where('key', 'design')->sole();
        $task = MissionTask::factory()->for($lead)->create();

        $this->post(route('leads.update', $lead), ['name' => 'Design Lead', 'instructions' => 'Updated.', 'is_active' => '0'])->assertRedirect();

        $this->assertFalse($lead->refresh()->is_active);
        $this->get(route('tasks.show', $task))->assertOk()->assertSee('Design Lead');
    }

    public function test_editing_a_lead_never_changes_its_key(): void
    {
        $lead = Lead::query()->where('key', 'design')->sole();

        $this->post(route('leads.update', $lead), ['name' => 'Visual Lead', 'instructions' => 'Updated.', 'is_active' => '1'])->assertRedirect(route('settings.edit'));

        $this->assertSame('design', $lead->refresh()->key);
        $this->assertSame('Visual Lead', $lead->name);
    }

    public function test_settings_lists_the_leads(): void
    {
        $this->get(route('settings.edit'))->assertOk()->assertSee('Operations Lead')->assertSee('Add a lead');
    }

    public function test_rerunning_the_seeder_keeps_a_users_edits(): void
    {
        Lead::query()->where('key', 'design')->update(['name' => 'Visual Lead', 'is_active' => false]);

        $this->seed(LeadSeeder::class);

        $lead = Lead::query()->where('key', 'design')->sole();
        $this->assertSame('Visual Lead', $lead->name);
        $this->assertFalse($lead->is_active);
    }

    public function test_the_queue_filter_accepts_a_deactivated_leads_key(): void
    {
        $lead = Lead::query()->where('key', 'design')->sole();
        $lead->update(['is_active' => false]);
        $task = MissionTask::factory()->for($lead)->create(['title' => 'Polish the empty state']);

        $this->get(route('dashboard', ['lead' => 'design']))->assertOk()->assertSee('Polish the empty state');
    }
}
