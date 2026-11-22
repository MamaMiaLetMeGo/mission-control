<?php

namespace Tests\Feature;

use App\Jobs\PlanGoal;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\MissionTask;
use App\Services\ChiefOfStaff;
use App\Services\CodexProcess;
use App\Services\RunSlots;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlanGoalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_successful_plan_creates_the_lead_tasks_and_marks_the_goal_planned(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        [$first, $second] = Lead::query()->active()->limit(2)->pluck('key')->all();
        $goal = Goal::factory()->queued()->create();
        $plan = ['type' => 'plan', 'success' => true, 'overview' => 'Two steps.', 'usage' => ['input_tokens' => 30, 'output_tokens' => 12], 'briefs' => [
            ['lead' => $first, 'title' => 'One', 'request' => 'Do one.', 'acceptanceCriteria' => 'One works.', 'priority' => 'high', 'after' => []],
            ['lead' => $second, 'title' => 'Two', 'request' => 'Do two.', 'acceptanceCriteria' => 'Two works.', 'priority' => 'normal', 'after' => [1]],
        ]];
        $worker = $this->mock(CodexProcess::class, function ($mock) use ($plan): void {
            $mock->shouldReceive('plan')->once()->andReturnUsing(function ($goal, $callback) use ($plan) {
                $callback(['type' => 'message', 'message' => 'Reading the repository.']);

                return $plan;
            });
            $mock->shouldReceive('redact')->andReturnUsing(fn (string $message): string => $message);
        });

        (new PlanGoal($goal->id))->handle($worker, app(ChiefOfStaff::class));

        $goal->refresh();
        $this->assertSame('planned', $goal->status);
        $this->assertSame('Two steps.', $goal->overview);
        $this->assertSame(['input_tokens' => 30, 'output_tokens' => 12], $goal->usage);
        $this->assertSame([['type' => 'message', 'message' => 'Reading the repository.']], $goal->events);
        $this->assertNotNull($goal->started_at);
        $this->assertNotNull($goal->finished_at);
        $this->assertCount(2, $goal->tasks);
        $this->assertSame([$goal->tasks[0]->id], $goal->tasks[1]->depends_on);
        $this->assertSlotIsFree();
    }

    public function test_a_plan_naming_an_unknown_lead_fails_the_goal_without_tasks(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $goal = Goal::factory()->queued()->create();
        $plan = ['type' => 'plan', 'success' => true, 'overview' => 'Plan.', 'usage' => ['input_tokens' => 5], 'briefs' => [
            ['lead' => 'nobody', 'title' => 'One', 'request' => 'Do one.', 'acceptanceCriteria' => 'One works.', 'priority' => 'high', 'after' => []],
        ]];

        (new PlanGoal($goal->id))->handle($this->workerReturning($plan), app(ChiefOfStaff::class));

        $goal->refresh();
        $this->assertSame('failed', $goal->status);
        $this->assertSame('The Chief of Staff returned a plan that could not be used. Give the goal again.', $goal->error);
        $this->assertSame(['input_tokens' => 5], $goal->usage);
        $this->assertNotNull($goal->finished_at);
        $this->assertSame(0, MissionTask::query()->where('goal_id', $goal->id)->count());
        $this->assertSlotIsFree();
    }

    public function test_an_unsuccessful_worker_result_fails_the_goal_with_its_error(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $goal = Goal::factory()->queued()->create();

        (new PlanGoal($goal->id))->handle($this->workerReturning(['type' => 'plan', 'success' => false, 'error' => 'The planner ran out of time.', 'usage' => ['input_tokens' => 9]]), app(ChiefOfStaff::class));

        $goal->refresh();
        $this->assertSame('failed', $goal->status);
        $this->assertSame('The planner ran out of time.', $goal->error);
        $this->assertSame(['input_tokens' => 9], $goal->usage);
        $this->assertSlotIsFree();
    }

    public function test_an_unusable_ideas_plan_fails_with_the_ideas_message(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $goal = Goal::factory()->queued()->ideas()->create();
        $plan = ['type' => 'plan', 'success' => true, 'overview' => 'Looked.', 'briefs' => [
            ['lead' => 'nobody', 'title' => 'One', 'request' => 'Why: it helps.', 'acceptanceCriteria' => 'One works.', 'priority' => 'normal', 'after' => []],
        ]];

        (new PlanGoal($goal->id))->handle($this->workerReturning($plan), app(ChiefOfStaff::class));

        $goal->refresh();
        $this->assertSame('failed', $goal->status);
        $this->assertSame('The leads returned ideas that could not be used. Press "Get ideas now" to try again.', $goal->error);
        $this->assertSlotIsFree();
    }

    public function test_an_ideas_run_without_an_error_from_the_worker_fails_with_the_ideas_message(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $goal = Goal::factory()->queued()->ideas()->create();

        (new PlanGoal($goal->id))->handle($this->workerReturning(['type' => 'plan', 'success' => false]), app(ChiefOfStaff::class));

        $goal->refresh();
        $this->assertSame('failed', $goal->status);
        $this->assertSame('The leads could not come up with ideas this time.', $goal->error);
    }

    public function test_an_owner_goal_without_an_error_from_the_worker_keeps_the_planning_message(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $goal = Goal::factory()->queued()->create();

        (new PlanGoal($goal->id))->handle($this->workerReturning(['type' => 'plan', 'success' => false]), app(ChiefOfStaff::class));

        $this->assertSame('The Chief of Staff could not plan this goal.', $goal->refresh()->error);
    }

    public function test_an_ideas_run_does_not_start_once_the_daily_token_limit_is_reached(): void
    {
        $this->freezeTime();
        config(['mission.openai_api_key' => 'test-key', 'mission.daily_token_limit' => 10000]);
        Goal::factory()->create(['usage' => ['input_tokens' => 8000, 'output_tokens' => 2000]]);
        $goal = Goal::factory()->queued()->ideas()->create();
        $worker = $this->mock(CodexProcess::class, function ($mock): void {
            $mock->shouldNotReceive('plan');
            $mock->shouldReceive('redact')->andReturnUsing(fn (string $message): string => $message);
        });

        (new PlanGoal($goal->id))->handle($worker, app(ChiefOfStaff::class));

        $goal->refresh();
        $this->assertSame('failed', $goal->status);
        $this->assertSame('The daily token limit was reached before the leads could look for ideas. Try again tomorrow or raise the limit in Settings.', $goal->error);
    }

    public function test_a_goal_waits_when_every_slot_is_busy(): void
    {
        config(['mission.openai_api_key' => 'test-key', 'mission.max_parallel_runs' => 1]);
        $held = app(RunSlots::class)->acquire(60);
        $goal = Goal::factory()->queued()->create();
        $worker = $this->mock(CodexProcess::class, fn ($mock) => $mock->shouldNotReceive('plan'));
        $job = (new PlanGoal($goal->id))->withFakeQueueInteractions();

        $job->handle($worker, app(ChiefOfStaff::class));

        $job->assertReleased(15);
        $job->assertNotFailed();
        $this->assertSame('queued', $goal->refresh()->status);
        $held->release();
    }

    public function test_planning_does_not_start_once_the_daily_token_limit_is_reached(): void
    {
        $this->freezeTime();
        config(['mission.openai_api_key' => 'test-key', 'mission.daily_token_limit' => 10000]);
        Goal::factory()->create(['usage' => ['input_tokens' => 8000, 'output_tokens' => 2000]]);
        $goal = Goal::factory()->queued()->create();
        $worker = $this->mock(CodexProcess::class, function ($mock): void {
            $mock->shouldNotReceive('plan');
            $mock->shouldReceive('redact')->andReturnUsing(fn (string $message): string => $message);
        });

        (new PlanGoal($goal->id))->handle($worker, app(ChiefOfStaff::class));

        $goal->refresh();
        $this->assertSame('failed', $goal->status);
        $this->assertSame('The daily token limit was reached before planning could start. Give the goal again tomorrow or raise the limit in Settings.', $goal->error);
        $this->assertSlotIsFree();
    }

    public function test_a_goal_that_is_not_queued_is_left_alone(): void
    {
        $goal = Goal::factory()->create();
        $worker = $this->mock(CodexProcess::class, fn ($mock) => $mock->shouldNotReceive('plan'));

        (new PlanGoal($goal->id))->handle($worker, app(ChiefOfStaff::class));

        $this->assertSame('planned', $goal->refresh()->status);
    }

    public function test_a_goal_still_waiting_when_the_retry_window_ends_is_queued_again(): void
    {
        Queue::fake();
        $goal = Goal::factory()->queued()->create();

        (new PlanGoal($goal->id))->failed(new MaxAttemptsExceededException('attempted too many times.'));

        $this->assertSame('queued', $goal->refresh()->status);
        Queue::assertPushed(PlanGoal::class, fn (PlanGoal $job): bool => $job->goalId === $goal->id);
    }

    public function test_a_planning_goal_that_exceeds_its_attempts_is_failed_with_a_redacted_message(): void
    {
        config(['mission.openai_api_key' => 'private-test-credential']);
        Queue::fake();
        $goal = Goal::factory()->planning()->create();

        (new PlanGoal($goal->id))->failed(new MaxAttemptsExceededException('Failed: private-test-credential'));

        $goal->refresh();
        $this->assertSame('failed', $goal->status);
        $this->assertSame('Failed: [redacted]', $goal->error);
        Queue::assertNothingPushed();
    }

    /** @param array<string, mixed> $plan */
    private function workerReturning(array $plan): CodexProcess
    {
        return $this->mock(CodexProcess::class, function ($mock) use ($plan): void {
            $mock->shouldReceive('plan')->once()->andReturn($plan);
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
