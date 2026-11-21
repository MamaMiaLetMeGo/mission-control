<?php

namespace Tests\Feature;

use App\Jobs\LearnPreferences;
use App\Models\BrainRun;
use App\Models\Decision;
use App\Models\Lead;
use App\Models\MissionTask;
use App\Models\Preference;
use App\Services\Brain;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_an_owner_edit_that_keeps_the_text_still_wins_over_an_in_flight_run(): void
    {
        $lead = Lead::factory()->create();
        $decision = Decision::factory()->create();
        $leadOnly = Preference::factory()->learned()->create(['text' => 'Keep it short.']);
        $offThenOn = Preference::factory()->learned()->create(['text' => 'Use tabs.']);
        $deleted = Preference::factory()->learned()->create(['text' => 'Name branches well.']);
        $untouched = Preference::factory()->learned()->create(['text' => 'Write small commits.']);
        $sent = collect([$leadOnly, $offThenOn, $deleted, $untouched])->pluck('text', 'id')->all();
        $run = BrainRun::factory()->learning()->create(['settings' => ['sent_preferences' => $sent], 'decision_ids' => [$decision->id], 'last_decision_id' => $decision->id]);
        $queued = BrainRun::factory()->queued()->create(['settings' => ['sent_preferences' => $sent]]);
        $finished = BrainRun::factory()->create(['settings' => ['sent_preferences' => $sent]]);

        $this->post(route('brain.update', $leadOnly), ['text' => 'Keep it short.', 'lead' => $lead->key, 'status' => 'active'])->assertSessionHasNoErrors();
        $this->post(route('brain.update', $offThenOn), ['status' => 'off'])->assertSessionHasNoErrors();
        $this->post(route('brain.update', $offThenOn), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->post(route('brain.destroy', $deleted))->assertSessionHasNoErrors();

        $remaining = [$untouched->id => 'Write small commits.'];
        $this->assertSame($remaining, $run->refresh()->settings['sent_preferences']);
        $this->assertSame($remaining, $queued->refresh()->settings['sent_preferences']);
        $this->assertSame($sent, $finished->refresh()->settings['sent_preferences']);

        app(Brain::class)->apply($run, ['summary' => 'Adjusted.', 'changes' => [
            ['op' => 'update', 'id' => $leadOnly->id, 'text' => 'Keep it very short.', 'evidence' => [$decision->id]],
            ['op' => 'retire', 'id' => $offThenOn->id, 'reason' => 'Contradicted.'],
            ['op' => 'retire', 'id' => $untouched->id, 'reason' => 'Contradicted.'],
        ]]);

        $this->assertSame(['Keep it short.', $lead->id], [$leadOnly->refresh()->text, $leadOnly->lead_id]);
        $this->assertSame('active', $offThenOn->refresh()->status);
        $this->assertSame('off', $untouched->refresh()->status);
        $this->assertSame('completed', $run->refresh()->status);
    }

    public function test_deleting_a_preference_waits_on_the_shared_lock(): void
    {
        $preference = Preference::factory()->create();
        $held = Cache::lock('mission-preferences', 30);
        $this->assertTrue($held->get());

        $this->from(route('brain.index'))->post(route('brain.destroy', $preference))->assertSessionHasErrors('workflow');
        $held->release();

        $this->assertDatabaseCount('preferences', 1);
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

    public function test_a_failed_edit_shows_its_error_and_input_only_on_that_row(): void
    {
        $first = Preference::factory()->create(['text' => 'First preference.']);
        $second = Preference::factory()->create(['text' => 'Second preference.']);

        $response = $this->followingRedirects()->from(route('brain.index'))->post(route('brain.update', $first), ['text' => 'Broken lead text.', 'lead' => 'nobody', 'status' => 'active', 'brain_form' => (string) $first->id]);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'Broken lead text.'));
        $this->assertSame(1, substr_count($html, 'role="alert">The selected lead is invalid.'));
        $this->assertStringContainsString('value="Second preference."', $html);
        $this->assertStringContainsString('id="preference-text-'.$first->id.'" name="text" value="Broken lead text."', $html);
        $this->assertStringContainsString('id="new-preference-text" name="text" value=""', $html);
    }

    public function test_a_status_only_switch_off_leaves_text_and_lead_unchanged(): void
    {
        $lead = Lead::factory()->create();
        $preference = Preference::factory()->forLead($lead)->create(['text' => 'Keep me.']);

        $this->post(route('brain.update', $preference), ['status' => 'off'])->assertSessionHasNoErrors();

        $preference->refresh();
        $this->assertSame('off', $preference->status);
        $this->assertSame('Keep me.', $preference->text);
        $this->assertSame($lead->id, $preference->lead_id);
    }

    public function test_a_failed_switch_on_shows_the_cap_message_next_to_that_row(): void
    {
        Preference::factory()->count(Preference::MAX_ACTIVE)->create();
        $off = Preference::factory()->off()->create(['text' => 'Dormant.']);

        $response = $this->followingRedirects()->from(route('brain.index'))->post(route('brain.update', $off), ['status' => 'active', 'brain_form' => 'toggle-'.$off->id]);

        $this->assertSame(1, substr_count($response->getContent(), 'role="alert">20 preferences are already active. Switch one off first.'));
        $this->assertSame('off', $off->fresh()->status);
    }

    public function test_changes_that_could_raise_the_active_count_wait_on_the_shared_lock(): void
    {
        $off = Preference::factory()->off()->create();
        $held = Cache::lock('mission-preferences', 30);
        $this->assertTrue($held->get());

        $this->from(route('brain.index'))->post(route('brain.store'), ['text' => 'Blocked.'])->assertRedirect(route('brain.index'))->assertSessionHasErrors('workflow');
        $this->from(route('brain.index'))->post(route('brain.update', $off), ['status' => 'active'])->assertSessionHasErrors('workflow');
        $held->release();

        $this->assertDatabaseCount('preferences', 1);
        $this->assertSame('off', $off->fresh()->status);
    }

    public function test_the_cap_check_never_locks_an_aggregate_query(): void
    {
        DB::enableQueryLog();

        $this->post(route('brain.store'), ['text' => 'Counted.'])->assertSessionHasNoErrors();

        $counts = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'count('));
        $this->assertNotEmpty($counts);
        $counts->each(fn (string $sql) => $this->assertStringNotContainsString('for update', strtolower($sql)));
    }

    public function test_the_journal_points_to_the_brain(): void
    {
        $this->get(route('profile'))->assertOk()
            ->assertSee('Feedback is preserved with the task and reviewed revision. The <a class="text-link" href="'.route('brain.index').'">Brain</a> turns these decisions into preferences you can see and edit.', false)
            ->assertDontSee('no preferences are inferred automatically');
    }
}
