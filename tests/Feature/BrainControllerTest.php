<?php

namespace Tests\Feature;

use App\Jobs\LearnPreferences;
use App\Models\BrainRun;
use App\Models\Decision;
use App\Models\Lead;
use App\Models\MissionTask;
use App\Models\Preference;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrainControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_page_lists_active_and_switched_off_preferences_with_their_evidence(): void
    {
        $lead = Lead::factory()->create(['name' => 'Design Lead']);
        $task = MissionTask::factory()->create(['title' => 'Tidy the form']);
        $decision = Decision::factory()->create(['mission_task_id' => $task->id, 'action' => 'request_changes', 'feedback' => 'Keep it <b>short</b>.']);
        Preference::factory()->learned()->forLead($lead)->create(['text' => 'Prefer short forms.', 'evidence' => [$decision->id, 99999]]);
        Preference::factory()->create(['text' => 'Always add tests.']);
        Preference::factory()->off()->create(['text' => 'Old habit.', 'note' => 'Contradicted by newer feedback.']);

        $this->get(route('brain.index'))->assertOk()
            ->assertSee('What your team has learned about how you like things done.')
            ->assertSee('2 of 20')
            ->assertSeeInOrder(['Active', 'Prefer short forms.', 'Design Lead', 'Learned', 'Changes requested', 'MC-'.str_pad((string) $task->id, 3, '0', STR_PAD_LEFT)])
            ->assertSee('Keep it &lt;b&gt;short&lt;/b&gt;.', false)->assertDontSee('<b>short</b>', false)
            ->assertSee('Always add tests.')->assertSee('All leads')->assertSee('Yours')
            ->assertSeeInOrder(['Switched off', 'Old habit.', 'Contradicted by newer feedback.', 'Switch on']);
    }

    public function test_new_shows_once_and_the_nav_badge_counts_unseen_learned_preferences(): void
    {
        Preference::factory()->learned()->count(2)->create();
        Preference::factory()->learned()->off()->create();
        Preference::factory()->create();

        $this->get(route('profile'))->assertOk()->assertSee('<span class="nav-count" data-brain-count>2</span>', false);
        $this->get(route('brain.index'))->assertOk()->assertSee('tag-new', false);
        $this->assertSame(0, Preference::query()->active()->where('source', 'learned')->whereNull('seen_at')->count());
        $this->get(route('brain.index'))->assertOk()->assertDontSee('tag-new', false)->assertDontSee('data-brain-count', false);
    }

    public function test_adding_a_preference_stores_it_on_one_line_as_manual(): void
    {
        $lead = Lead::factory()->create();

        $this->post(route('brain.store'), ['text' => "Use plain words\nin copy.", 'lead' => $lead->key, 'brain_form' => 'new'])->assertRedirect(route('brain.index'));

        $preference = Preference::query()->firstOrFail();
        $this->assertSame('Use plain words in copy.', $preference->text);
        $this->assertSame('manual', $preference->source);
        $this->assertSame('active', $preference->status);
        $this->assertSame($lead->id, $preference->lead_id);
    }

    public function test_invalid_additions_are_rejected(): void
    {
        $this->post(route('brain.store'), ['text' => "  \n "])->assertSessionHasErrors('text');
        $this->post(route('brain.store'), ['text' => str_repeat('a', 301)])->assertSessionHasErrors('text');
        $this->post(route('brain.store'), ['text' => 'Fine.', 'lead' => 'nobody'])->assertSessionHasErrors('lead');
        $this->assertDatabaseCount('preferences', 0);
    }

    public function test_the_active_cap_blocks_adding_and_switching_on(): void
    {
        Preference::factory()->count(Preference::MAX_ACTIVE)->create();
        $off = Preference::factory()->off()->create(['text' => 'Dormant.']);

        $this->post(route('brain.store'), ['text' => 'One too many.'])->assertSessionHasErrors(['text' => '20 preferences are already active. Switch one off first.']);
        $this->post(route('brain.update', $off), ['text' => 'Dormant.', 'status' => 'active'])->assertSessionHasErrors(['text' => '20 preferences are already active. Switch one off first.']);

        $this->assertDatabaseCount('preferences', Preference::MAX_ACTIVE + 1);
        $this->assertSame('off', $off->fresh()->status);
        $this->post(route('brain.update', $off), ['text' => 'Still dormant.', 'status' => 'off'])->assertSessionHasNoErrors();
        $this->assertSame('Still dormant.', $off->fresh()->text);
    }

    public function test_editing_a_learned_preference_keeps_its_source_and_evidence_and_marks_it_seen(): void
    {
        $lead = Lead::factory()->create();
        $preference = Preference::factory()->learned()->create(['evidence' => [4, 5]]);

        $this->post(route('brain.update', $preference), ['text' => 'Reworded.', 'lead' => $lead->key, 'status' => 'active'])->assertRedirect(route('brain.index'));

        $preference->refresh();
        $this->assertSame('Reworded.', $preference->text);
        $this->assertSame('learned', $preference->source);
        $this->assertSame([4, 5], $preference->evidence);
        $this->assertSame($lead->id, $preference->lead_id);
        $this->assertNotNull($preference->seen_at);
    }

    public function test_a_preference_can_be_switched_off_and_on_and_deleted(): void
    {
        $preference = Preference::factory()->learned()->create();

        $this->post(route('brain.update', $preference), ['text' => $preference->text, 'status' => 'off'])->assertSessionHasNoErrors();
        $this->assertSame('off', $preference->fresh()->status);
        $this->assertSame('learned', $preference->fresh()->source);

        $this->post(route('brain.update', $preference), ['text' => $preference->text, 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('active', $preference->fresh()->status);

        $this->post(route('brain.destroy', $preference))->assertRedirect(route('brain.index'));
        $this->assertDatabaseCount('preferences', 0);
    }

    public function test_learn_now_queues_a_run_and_shows_why_when_nothing_is_pending(): void
    {
        Queue::fake();
        config(['mission.openai_api_key' => 'test-key-not-a-real-secret', 'mission.repository_path' => base_path(), 'mission.base_branch' => 'main', 'mission.worker_path' => base_path('artisan'), 'mission.test_command' => ['php', 'artisan', 'test']]);

        $this->from(route('brain.index'))->post(route('brain.learn'))->assertRedirect(route('brain.index'))->assertSessionHasErrors(['brain' => 'There are no new decisions with feedback to learn from.']);
        Queue::assertNotPushed(LearnPreferences::class);

        Decision::factory()->create();
        $this->post(route('brain.learn'))->assertRedirect(route('brain.index'))->assertSessionHas('status');
        Queue::assertPushed(LearnPreferences::class);
        $this->assertSame(1, BrainRun::query()->where('status', 'queued')->count());
    }

    public function test_the_page_shows_the_last_run_and_waiting_decisions(): void
    {
        BrainRun::factory()->create(['summary' => 'Learned <i>one</i> thing.', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 234], 'finished_at' => now()]);
        Decision::factory()->count(2)->create();

        $this->get(route('brain.index'))->assertOk()
            ->assertSee('Done')->assertSee('Learned &lt;i&gt;one&lt;/i&gt; thing.', false)->assertSee('1,234')
            ->assertSee('2 of 5 decisions before the next learning run')->assertSee('Learn now');
    }

    public function test_the_journal_points_to_the_brain(): void
    {
        $this->get(route('profile'))->assertOk()
            ->assertSee('Feedback is preserved with the task and reviewed revision. The <a class="text-link" href="'.route('brain.index').'">Brain</a> turns these decisions into preferences you can see and edit.', false)
            ->assertDontSee('no preferences are inferred automatically');
    }
}
