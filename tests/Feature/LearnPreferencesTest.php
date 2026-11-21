<?php

namespace Tests\Feature;

use App\Jobs\LearnPreferences;
use App\Models\BrainRun;
use App\Models\Decision;
use App\Models\Preference;
use App\Services\Brain;
use App\Services\CodexProcess;
use App\Services\RunSlots;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LearnPreferencesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_successful_result_is_applied_and_completes_the_run(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $run = $this->queuedRun();
        $result = ['type' => 'learn', 'success' => true, 'summary' => 'Learned one thing.', 'usage' => ['input_tokens' => 30, 'output_tokens' => 12], 'changes' => [
            ['op' => 'add', 'text' => 'Keep onboarding short.', 'lead' => null, 'evidence' => [$run->decision_ids[0]]],
        ]];
        $worker = $this->mock(CodexProcess::class, function ($mock) use ($result): void {
            $mock->shouldReceive('learn')->once()->andReturnUsing(function ($run, $callback) use ($result) {
                $callback(['type' => 'phase', 'message' => 'The Brain is reading recent decisions.']);
                $callback(['type' => 'usage', 'usage' => ['input_tokens' => 10]]);

                return $result;
            });
            $mock->shouldReceive('redact')->andReturnUsing(fn (string $message): string => $message);
        });

        (new LearnPreferences($run->id))->handle($worker, app(Brain::class));

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('Learned one thing.', $run->summary);
        $this->assertSame(['input_tokens' => 30, 'output_tokens' => 12], $run->usage);
        $this->assertSame([['type' => 'phase', 'message' => 'The Brain is reading recent decisions.'], ['type' => 'usage', 'usage' => ['input_tokens' => 10]]], $run->events);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('learned', Preference::query()->where('text', 'Keep onboarding short.')->sole()->source);
        $this->assertSlotIsFree();
    }

    public function test_an_unsuccessful_worker_result_fails_the_run_with_its_error(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $run = $this->queuedRun();

        (new LearnPreferences($run->id))->handle($this->workerReturning(['type' => 'learn', 'success' => false, 'summary' => '', 'changes' => [], 'error' => 'The Brain ran out of time.', 'usage' => ['input_tokens' => 9]]), app(Brain::class));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('The Brain ran out of time.', $run->error);
        $this->assertSame(['input_tokens' => 9], $run->usage);
        $this->assertNotNull($run->finished_at);
        $this->assertSlotIsFree();
    }

    public function test_an_unusable_result_fails_the_run_and_changes_nothing(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $run = $this->queuedRun();
        $preference = Preference::factory()->learned()->create(['text' => 'Keep it.']);
        $result = ['type' => 'learn', 'success' => true, 'summary' => 'Changed.', 'usage' => ['input_tokens' => 5], 'changes' => [
            ['op' => 'retire', 'id' => $preference->id, 'reason' => 'Contradicted.'],
            ['op' => 'add', 'text' => "Two\nlines.", 'lead' => null, 'evidence' => [$run->decision_ids[0]]],
        ]];

        (new LearnPreferences($run->id))->handle($this->workerReturning($result), app(Brain::class));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('The Brain returned changes that could not be used. Nothing was changed.', $run->error);
        $this->assertSame(['input_tokens' => 5], $run->usage);
        $this->assertSame('active', $preference->refresh()->status);
        $this->assertSame(1, Preference::query()->count());
        $this->assertCount(2, app(Brain::class)->pendingDecisions());
        $this->assertSlotIsFree();
    }

    public function test_a_busy_preference_lock_fails_the_run_with_a_plain_message(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $run = $this->queuedRun();
        $result = ['type' => 'learn', 'success' => true, 'summary' => 'Learned one thing.', 'usage' => ['input_tokens' => 5], 'changes' => [
            ['op' => 'add', 'text' => 'Keep onboarding short.', 'lead' => null, 'evidence' => [$run->decision_ids[0]]],
        ]];
        $held = Cache::lock('mission-preferences', 30);
        $this->assertTrue($held->get());

        (new LearnPreferences($run->id))->handle($this->workerReturning($result), app(Brain::class));
        $held->release();

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Preferences were being changed at the same time. Try learning again in a moment.', $run->error);
        $this->assertDatabaseCount('preferences', 0);
        $this->assertSlotIsFree();
    }

    public function test_a_blank_preference_text_fails_the_run_and_changes_nothing(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $run = $this->queuedRun();
        $preference = Preference::factory()->learned()->create(['text' => 'Keep it.']);
        $run->update(['settings' => [...$run->settings, 'sent_preferences' => [$preference->id => 'Keep it.']]]);
        $result = ['type' => 'learn', 'success' => true, 'summary' => 'Changed.', 'usage' => ['input_tokens' => 5], 'changes' => [
            ['op' => 'update', 'id' => $preference->id, 'text' => "\u{00A0}\u{00A0}", 'evidence' => [$run->decision_ids[0]]],
        ]];

        (new LearnPreferences($run->id))->handle($this->workerReturning($result), app(Brain::class));

        $this->assertSame(['failed', 'The Brain returned changes that could not be used. Nothing was changed.'], [$run->refresh()->status, $run->error]);
        $this->assertSame('Keep it.', $preference->refresh()->text);
    }

    public function test_a_run_waits_when_every_slot_is_busy(): void
    {
        config(['mission.openai_api_key' => 'test-key', 'mission.max_parallel_runs' => 1]);
        $held = app(RunSlots::class)->acquire(60);
        $run = $this->queuedRun();
        $worker = $this->mock(CodexProcess::class, fn ($mock) => $mock->shouldNotReceive('learn'));
        $job = (new LearnPreferences($run->id))->withFakeQueueInteractions();

        $job->handle($worker, app(Brain::class));

        $job->assertReleased(15);
        $job->assertNotFailed();
        $this->assertSame('queued', $run->refresh()->status);
        $held->release();
    }

    public function test_learning_does_not_start_once_the_daily_token_limit_is_reached(): void
    {
        $this->freezeTime();
        config(['mission.openai_api_key' => 'test-key', 'mission.daily_token_limit' => 10000]);
        BrainRun::factory()->create(['usage' => ['input_tokens' => 8000, 'output_tokens' => 2000]]);
        $run = $this->queuedRun();
        $worker = $this->mock(CodexProcess::class, function ($mock): void {
            $mock->shouldNotReceive('learn');
            $mock->shouldReceive('redact')->andReturnUsing(fn (string $message): string => $message);
        });

        (new LearnPreferences($run->id))->handle($worker, app(Brain::class));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('The daily token limit was reached before the Brain could start. Try again tomorrow or raise the limit in Settings.', $run->error);
        $this->assertSlotIsFree();
    }

    public function test_a_run_that_is_not_queued_is_left_alone(): void
    {
        $run = BrainRun::factory()->create();
        $worker = $this->mock(CodexProcess::class, fn ($mock) => $mock->shouldNotReceive('learn'));

        (new LearnPreferences($run->id))->handle($worker, app(Brain::class));

        $this->assertSame('completed', $run->refresh()->status);
    }

    public function test_a_run_still_waiting_when_the_retry_window_ends_is_queued_again(): void
    {
        Queue::fake();
        $run = BrainRun::factory()->queued()->create();

        (new LearnPreferences($run->id))->failed(new MaxAttemptsExceededException('attempted too many times.'));

        $this->assertSame('queued', $run->refresh()->status);
        Queue::assertPushed(LearnPreferences::class, fn (LearnPreferences $job): bool => $job->runId === $run->id);
    }

    public function test_a_learning_run_that_exceeds_its_attempts_is_failed_with_a_redacted_message(): void
    {
        config(['mission.openai_api_key' => 'private-test-credential']);
        Queue::fake();
        $run = BrainRun::factory()->learning()->create();

        (new LearnPreferences($run->id))->failed(new MaxAttemptsExceededException('Failed: private-test-credential'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Failed: [redacted]', $run->error);
        $this->assertNotNull($run->finished_at);
        Queue::assertNothingPushed();
    }

    private function queuedRun(): BrainRun
    {
        $decisions = Decision::factory()->count(2)->create();

        return BrainRun::factory()->queued()->create([
            'settings' => ['max_runtime_seconds' => 60, 'max_tokens' => 1000],
            'decision_ids' => $decisions->pluck('id')->all(),
            'last_decision_id' => $decisions->max('id'),
        ]);
    }

    /** @param array<string, mixed> $result */
    private function workerReturning(array $result): CodexProcess
    {
        return $this->mock(CodexProcess::class, function ($mock) use ($result): void {
            $mock->shouldReceive('learn')->once()->andReturn($result);
            $mock->shouldReceive('redact')->andReturnUsing(fn (string $message): string => $message);
        });
    }

    private function assertSlotIsFree(): void
    {
        $slot = app(RunSlots::class)->acquire(60);
        $this->assertNotNull($slot);
        $slot->release();
    }
}
