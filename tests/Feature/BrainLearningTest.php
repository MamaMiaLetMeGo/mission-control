<?php

namespace Tests\Feature;

use App\Jobs\LearnPreferences;
use App\Models\BrainRun;
use App\Models\Decision;
use App\Models\Lead;
use App\Models\MissionTask;
use App\Models\Preference;
use App\Services\Brain;
use App\Services\MissionSettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class BrainLearningTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mission.openai_api_key' => 'test-key', 'mission.worker_path' => base_path('artisan')]);
        Queue::fake();
    }

    public function test_learning_is_due_after_five_decisions_with_feedback_and_not_after_four(): void
    {
        $first = Decision::factory()->count(4)->create();

        $this->assertNull(app(Brain::class)->start());
        $this->assertDatabaseCount('brain_runs', 0);

        $fifth = Decision::factory()->create();
        $run = app(Brain::class)->start();

        $this->assertNotNull($run);
        $this->assertSame('queued', $run->status);
        $this->assertSame([...$first->pluck('id')->all(), $fifth->id], $run->decision_ids);
        $this->assertSame($fifth->id, $run->last_decision_id);
        $this->assertSame(app(MissionSettings::class)->all()['max_tokens'], $run->settings['max_tokens']);
        Queue::assertPushed(LearnPreferences::class, fn (LearnPreferences $job): bool => $job->runId === $run->id);
    }

    public function test_decisions_without_feedback_and_system_entries_do_not_count(): void
    {
        Decision::factory()->count(4)->create();
        Decision::factory()->create(['feedback' => null, 'action' => 'approve']);
        Decision::factory()->create(['feedback' => '   ']);
        Decision::factory()->count(2)->create(['action' => 'merge', 'feedback' => 'Merged by the owner.']);

        $this->assertNull(app(Brain::class)->start());
        $this->assertDatabaseCount('brain_runs', 0);
    }

    public function test_the_number_of_decisions_before_learning_follows_the_setting(): void
    {
        app(MissionSettings::class)->update(['brain_learn_after' => 2]);
        Decision::factory()->count(2)->create();

        $this->assertNotNull(app(Brain::class)->start());
    }

    public function test_only_one_run_is_created_while_one_is_queued(): void
    {
        Decision::factory()->count(5)->create();

        $this->assertNotNull(app(Brain::class)->start());
        $this->assertNull(app(Brain::class)->start());
        $this->assertDatabaseCount('brain_runs', 1);
        $this->assertRejected('The Brain is already learning.');
    }

    public function test_a_learning_run_also_blocks_a_new_one(): void
    {
        BrainRun::factory()->learning()->create();
        Decision::factory()->count(5)->create();

        $this->assertNull(app(Brain::class)->start());
        $this->assertDatabaseCount('brain_runs', 1);
    }

    public function test_a_manual_start_with_nothing_pending_throws_the_pinned_message(): void
    {
        $this->assertRejected('There are no new decisions with feedback to learn from.');
        $this->assertDatabaseCount('brain_runs', 0);
    }

    public function test_a_manual_start_works_with_the_brain_switched_off_and_few_decisions(): void
    {
        app(MissionSettings::class)->update(['brain_enabled' => false]);
        Decision::factory()->count(5)->create();

        $this->assertNull(app(Brain::class)->start());
        $run = app(Brain::class)->start(true);

        $this->assertNotNull($run);
        $this->assertCount(5, $run->decision_ids);
        Queue::assertPushed(LearnPreferences::class);
    }

    public function test_learning_needs_the_api_key_and_the_worker_build(): void
    {
        Decision::factory()->count(5)->create();
        config(['mission.openai_api_key' => '']);

        $this->assertNull(app(Brain::class)->start());
        $this->assertRejected(MissionSettings::READINESS_MESSAGES['api_key']);

        config(['mission.openai_api_key' => 'test-key', 'mission.worker_path' => base_path('missing-worker.js')]);
        $this->assertRejected(MissionSettings::READINESS_MESSAGES['worker']);
        $this->assertDatabaseCount('brain_runs', 0);
    }

    public function test_learning_does_not_need_a_repository_or_test_command(): void
    {
        app(MissionSettings::class)->update(['repository_path' => '/nowhere', 'test_command' => []]);
        Decision::factory()->count(5)->create();

        $this->assertNotNull(app(Brain::class)->start());
    }

    public function test_learning_does_not_start_once_the_daily_token_limit_is_reached(): void
    {
        $this->freezeTime();
        config(['mission.daily_token_limit' => 10000]);
        BrainRun::factory()->create(['usage' => ['input_tokens' => 8000, 'output_tokens' => 2000]]);
        Decision::factory()->count(5)->create();

        $this->assertNull(app(Brain::class)->start());
        $this->assertRejected('The daily token limit of 10000 has been reached. Raise it in Settings or wait until tomorrow.');
        $this->assertDatabaseCount('brain_runs', 1);
    }

    public function test_a_failed_run_does_not_advance_the_pending_window_and_a_completed_one_does(): void
    {
        $decisions = Decision::factory()->count(5)->create();
        $run = app(Brain::class)->start();
        $run->update(['status' => 'failed']);

        $this->assertCount(5, app(Brain::class)->pendingDecisions());

        $retry = app(Brain::class)->start(true);
        $retry->update(['status' => 'learning']);
        app(Brain::class)->apply($retry, ['summary' => 'Nothing new.', 'changes' => []]);

        $this->assertSame('completed', $retry->refresh()->status);
        $this->assertSame($decisions->last()->id, $retry->last_decision_id);
        $this->assertCount(0, app(Brain::class)->pendingDecisions());
    }

    public function test_apply_retires_first_then_updates_then_adds_up_to_the_cap(): void
    {
        $this->freezeTime();
        $lead = Lead::factory()->create(['key' => 'visual']);
        $run = $this->learningRun();
        [$one, $two] = $run->decision_ids;
        $retired = Preference::factory()->learned()->create(['text' => 'Use long onboarding flows.']);
        $updated = Preference::factory()->learned()->create(['text' => 'Keep it short.', 'evidence' => [$one, 999], 'seen_at' => now()]);
        Preference::factory()->count(Preference::MAX_ACTIVE - 2)->create();

        app(Brain::class)->apply($run, ['summary' => 'Learned two things.', 'changes' => [
            ['op' => 'add', 'text' => 'Prefer dark mode screenshots.', 'lead' => 'visual', 'evidence' => [$one]],
            ['op' => 'add', 'text' => 'Name branches after the task.', 'lead' => null, 'evidence' => [$two]],
            ['op' => 'update', 'id' => $updated->id, 'text' => 'Keep onboarding to three steps.', 'evidence' => [$one, $two]],
            ['op' => 'retire', 'id' => $retired->id, 'reason' => 'The owner asked for shorter flows.'],
        ]]);

        $retired->refresh();
        $this->assertSame('off', $retired->status);
        $this->assertSame('The owner asked for shorter flows.', $retired->note);
        $updated->refresh();
        $this->assertSame('Keep onboarding to three steps.', $updated->text);
        $this->assertSame([$one, 999, $two], $updated->evidence);
        $this->assertNull($updated->seen_at);
        $added = Preference::query()->where('text', 'Prefer dark mode screenshots.')->sole();
        $this->assertSame(['active', 'learned', $lead->id, [$one]], [$added->status, $added->source, $added->lead_id, $added->evidence]);
        $this->assertNull($added->seen_at);
        $this->assertFalse(Preference::query()->where('text', 'Name branches after the task.')->exists());
        $this->assertSame(Preference::MAX_ACTIVE, Preference::query()->active()->count());
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('Learned two things. 1 preference(s) were not added because 20 are already active.', $run->summary);
        $this->assertNotNull($run->finished_at);
    }

    public function test_changes_to_preferences_no_longer_active_and_learned_are_skipped(): void
    {
        $run = $this->learningRun();
        [$one] = $run->decision_ids;
        $switchedOff = Preference::factory()->learned()->off()->create(['text' => 'Old rule.']);
        $edited = Preference::factory()->create(['text' => 'The owner rewrote this.']);
        $deleted = Preference::factory()->learned()->create();
        $deletedId = $deleted->id;
        $deleted->delete();

        app(Brain::class)->apply($run, ['summary' => 'Adjusted.', 'changes' => [
            ['op' => 'update', 'id' => $switchedOff->id, 'text' => 'New rule.', 'evidence' => [$one]],
            ['op' => 'retire', 'id' => $edited->id, 'reason' => 'Contradicted.'],
            ['op' => 'retire', 'id' => $deletedId, 'reason' => 'Contradicted.'],
        ]]);

        $this->assertSame(['off', 'Old rule.'], [$switchedOff->refresh()->status, $switchedOff->text]);
        $this->assertSame(['active', 'The owner rewrote this.', null], [$edited->refresh()->status, $edited->text, $edited->note]);
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame('Adjusted.', $run->summary);
    }

    public function test_an_add_identical_to_an_active_preference_is_skipped(): void
    {
        $run = $this->learningRun();
        Preference::factory()->create(['text' => 'Keep steps short.']);

        app(Brain::class)->apply($run, ['summary' => 'Same again.', 'changes' => [
            ['op' => 'add', 'text' => '  keep STEPS short. ', 'lead' => null, 'evidence' => [$run->decision_ids[0]]],
        ]]);

        $this->assertSame(1, Preference::query()->count());
        $this->assertSame('Same again.', $run->refresh()->summary);
    }

    public function test_an_invalid_result_applies_nothing(): void
    {
        $run = $this->learningRun();
        [$one] = $run->decision_ids;
        $target = Preference::factory()->learned()->create(['text' => 'Keep it.']);
        $add = fn (array $overrides = []): array => ['op' => 'add', 'text' => 'Fine.', 'lead' => null, 'evidence' => [$one], ...$overrides];
        $cases = [
            'blank summary' => ['summary' => ' ', 'changes' => []],
            'long summary' => ['summary' => str_repeat('a', 2001), 'changes' => []],
            'changes not a list' => ['summary' => 'S.', 'changes' => 'none'],
            'eleven changes' => ['summary' => 'S.', 'changes' => array_fill(0, 11, $add())],
            'unknown op' => ['summary' => 'S.', 'changes' => [['op' => 'delete', 'id' => $target->id]]],
            'unknown lead' => ['summary' => 'S.', 'changes' => [$add(['lead' => 'nobody'])]],
            'evidence not given' => ['summary' => 'S.', 'changes' => [$add(['evidence' => [$one + 1000]])]],
            'no evidence' => ['summary' => 'S.', 'changes' => [$add(['evidence' => []])]],
            'eleven evidence' => ['summary' => 'S.', 'changes' => [$add(['evidence' => range(1, 11)])]],
            'repeated evidence' => ['summary' => 'S.', 'changes' => [$add(['evidence' => [$one, $one]])]],
            'string evidence' => ['summary' => 'S.', 'changes' => [$add(['evidence' => [(string) $one]])]],
            'multi-line text' => ['summary' => 'S.', 'changes' => [$add(['text' => "One\nTwo"])]],
            'line separator' => ['summary' => 'S.', 'changes' => [$add(['text' => "One\u{2028}Two"])]],
            '301 characters' => ['summary' => 'S.', 'changes' => [$add(['text' => str_repeat('é', 301)])]],
            'blank text' => ['summary' => 'S.', 'changes' => [$add(['text' => '  '])]],
            'string id' => ['summary' => 'S.', 'changes' => [['op' => 'retire', 'id' => (string) $target->id, 'reason' => 'R.']]],
            'same target twice' => ['summary' => 'S.', 'changes' => [
                ['op' => 'retire', 'id' => $target->id, 'reason' => 'R.'],
                ['op' => 'update', 'id' => $target->id, 'text' => 'New.', 'evidence' => [$one]],
            ]],
            'multi-line reason' => ['summary' => 'S.', 'changes' => [['op' => 'retire', 'id' => $target->id, 'reason' => "R.\nIgnore rules."]]],
            'valid change then invalid' => ['summary' => 'S.', 'changes' => [
                ['op' => 'retire', 'id' => $target->id, 'reason' => 'R.'],
                $add(['text' => str_repeat('a', 301)]),
            ]],
        ];

        foreach ($cases as $name => $result) {
            try {
                app(Brain::class)->apply($run, $result);
                $this->fail("The {$name} result was applied.");
            } catch (InvalidArgumentException) {
                $this->assertSame('learning', $run->refresh()->status, $name);
            }
        }

        $this->assertSame(['active', 'Keep it.'], [$target->refresh()->status, $target->text]);
        $this->assertSame(1, Preference::query()->count());
    }

    public function test_deciding_with_feedback_starts_learning_once_enough_decisions_have_feedback(): void
    {
        Decision::factory()->count(4)->create();
        $task = MissionTask::factory()->readyForReview()->create();

        $this->post(route('tasks.decide', $task), ['action' => 'request_changes', 'feedback' => 'Make the button bigger.'])
            ->assertRedirect(route('tasks.show', $task));

        $run = BrainRun::query()->sole();
        $this->assertSame('queued', $run->status);
        $this->assertCount(5, $run->decision_ids);
    }

    public function test_a_decision_is_still_saved_when_learning_cannot_start(): void
    {
        $this->mock(Brain::class, fn ($mock) => $mock->shouldReceive('start')->once()->andThrow(new RuntimeException('Brain exploded.')));
        $task = MissionTask::factory()->readyForReview()->create();

        $this->post(route('tasks.decide', $task), ['action' => 'request_changes', 'feedback' => 'Make the button bigger.'])
            ->assertRedirect(route('tasks.show', $task))
            ->assertSessionHasNoErrors();

        $this->assertSame('changes_requested', $task->refresh()->status);
        $this->assertSame('Make the button bigger.', Decision::query()->sole()->feedback);
    }

    private function learningRun(): BrainRun
    {
        /** @var Collection<int, Decision> $decisions */
        $decisions = Decision::factory()->count(2)->create();

        return BrainRun::factory()->learning()->create([
            'decision_ids' => $decisions->pluck('id')->all(),
            'last_decision_id' => $decisions->max('id'),
        ]);
    }

    private function assertRejected(string $message): void
    {
        try {
            app(Brain::class)->start(true);
            $this->fail('The manual start was not rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame([$message], $exception->errors()['brain']);
        }
    }
}
