<?php

namespace Tests\Feature;

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use App\Models\Goal;
use App\Models\MissionTask;
use App\Services\ChiefOfStaff;
use App\Services\CodexProcess;
use App\Services\MissionWorkflow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChiefOfStaffDispatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var list<int> */
    private array $queued = [];

    public function test_it_starts_the_highest_priority_eligible_task_and_respects_capacity(): void
    {
        config(['mission.max_parallel_runs' => 2]);
        $this->recordingWorkflow();
        AgentRun::factory()->create(['status' => 'running']);
        $goal = Goal::factory()->create();
        $low = MissionTask::factory()->forGoal($goal, 1)->create(['priority' => 'low']);
        $urgent = MissionTask::factory()->forGoal($goal, 2)->create(['priority' => 'urgent']);
        MissionTask::factory()->forGoal($goal, 3)->create(['priority' => 'high']);

        $this->assertSame(1, app(ChiefOfStaff::class)->dispatch());

        $this->assertSame([$urgent->id], $this->queued);
        $this->assertSame('backlog', $low->refresh()->status);
    }

    public function test_it_orders_by_priority_then_goal_then_position(): void
    {
        config(['mission.max_parallel_runs' => 4]);
        $this->recordingWorkflow();
        $first = Goal::factory()->create();
        $second = Goal::factory()->create();
        $b = MissionTask::factory()->forGoal($second, 1)->create();
        $c = MissionTask::factory()->forGoal($first, 2)->create();
        $a = MissionTask::factory()->forGoal($first, 1)->create();
        $high = MissionTask::factory()->forGoal($second, 2)->create(['priority' => 'high']);

        $this->assertSame(4, app(ChiefOfStaff::class)->dispatch());

        $this->assertSame([$high->id, $a->id, $c->id, $b->id], $this->queued);
    }

    public function test_queued_goals_use_capacity_and_capacity_never_goes_below_zero(): void
    {
        config(['mission.max_parallel_runs' => 1]);
        $this->recordingWorkflow();
        Goal::factory()->planning()->create();
        Goal::factory()->queued()->create();
        MissionTask::factory()->forGoal(Goal::factory()->create())->create();

        $this->assertSame(0, app(ChiefOfStaff::class)->dispatch());
        $this->assertSame([], $this->queued);
    }

    public function test_hand_made_backlog_tasks_are_skipped(): void
    {
        $this->recordingWorkflow();
        MissionTask::factory()->create();

        $this->assertSame(0, app(ChiefOfStaff::class)->dispatch());
        $this->assertSame([], $this->queued);
    }

    public function test_a_task_waits_for_its_dependency_to_be_merged(): void
    {
        $this->recordingWorkflow();
        $goal = Goal::factory()->create();
        $dependency = MissionTask::factory()->forGoal($goal, 1)->create(['status' => 'review']);
        $task = MissionTask::factory()->forGoal($goal, 2)->create(['depends_on' => [$dependency->id]]);

        $this->assertSame(0, app(ChiefOfStaff::class)->dispatch());

        $dependency->update(['status' => 'merged']);
        $this->assertSame(1, app(ChiefOfStaff::class)->dispatch());
        $this->assertSame([$task->id], $this->queued);
    }

    public function test_a_rejected_dependency_never_lets_its_task_start(): void
    {
        $this->recordingWorkflow();
        $goal = Goal::factory()->create();
        $dependency = MissionTask::factory()->forGoal($goal, 1)->create(['status' => 'rejected']);
        MissionTask::factory()->forGoal($goal, 2)->create(['depends_on' => [$dependency->id]]);

        $this->assertSame(0, app(ChiefOfStaff::class)->dispatch());
        $this->assertSame([], $this->queued);
    }

    public function test_it_does_nothing_when_auto_start_is_off(): void
    {
        config(['mission.auto_start' => false]);
        $this->recordingWorkflow();
        MissionTask::factory()->forGoal(Goal::factory()->create())->create();

        $this->assertSame(0, app(ChiefOfStaff::class)->dispatch());
        $this->assertSame([], $this->queued);
    }

    public function test_it_does_nothing_while_another_dispatch_holds_the_lock(): void
    {
        $this->recordingWorkflow();
        MissionTask::factory()->forGoal(Goal::factory()->create())->create();
        $lock = Cache::lock('mission-dispatch', 60);
        $lock->get();

        try {
            $this->assertSame(0, app(ChiefOfStaff::class)->dispatch());
        } finally {
            $lock->release();
        }
        $this->assertSame([], $this->queued);
        $this->assertSame(1, app(ChiefOfStaff::class)->dispatch());
    }

    public function test_the_daily_run_limit_leaves_the_task_in_the_backlog_without_an_error(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        config(['mission.daily_run_limit' => 1]);
        AgentRun::factory()->create(['status' => 'completed']);
        $task = MissionTask::factory()->forGoal(Goal::factory()->create())->create();

        $this->assertSame(0, app(ChiefOfStaff::class)->dispatch());

        $this->assertSame('backlog', $task->refresh()->status);
        $this->assertNull($task->error);
        Queue::assertNothingPushed();
    }

    public function test_the_token_limit_leaves_the_task_in_the_backlog_without_an_error(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        config(['mission.daily_token_limit' => 10000]);
        AgentRun::factory()->create(['status' => 'completed', 'usage' => ['input_tokens' => 8000, 'output_tokens' => 2000]]);
        $task = MissionTask::factory()->forGoal(Goal::factory()->create())->create();

        $this->assertSame(0, app(ChiefOfStaff::class)->dispatch());

        $this->assertSame('backlog', $task->refresh()->status);
        $this->assertNull($task->error);
    }

    public function test_failed_tasks_are_not_restarted(): void
    {
        $this->recordingWorkflow();
        MissionTask::factory()->forGoal(Goal::factory()->create())->create(['status' => 'failed']);

        $this->assertSame(0, app(ChiefOfStaff::class)->dispatch());
        $this->assertSame([], $this->queued);
    }

    public function test_an_unexpected_error_is_reported_and_stops_the_loop(): void
    {
        config(['mission.max_parallel_runs' => 3]);
        $this->mock(MissionWorkflow::class, function ($mock): void {
            $mock->shouldReceive('queue')->once()->andThrow(new \RuntimeException('git exploded'));
        });
        $goal = Goal::factory()->create();
        MissionTask::factory()->forGoal($goal, 1)->create();
        MissionTask::factory()->forGoal($goal, 2)->create();
        Exceptions::fake();

        $this->assertSame(0, app(ChiefOfStaff::class)->dispatch());
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === 'git exploded');
    }

    public function test_a_finished_run_starts_the_next_goal_task(): void
    {
        config(['mission.openai_api_key' => 'test-key', 'mission.max_parallel_runs' => 1]);
        $this->recordingWorkflow();
        $goal = Goal::factory()->create();
        $run = AgentRun::factory()->for(MissionTask::factory()->forGoal($goal, 1)->state(['status' => 'queued']), 'task')->create();
        $next = MissionTask::factory()->forGoal($goal, 2)->create();
        $worker = $this->mock(CodexProcess::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturn(['success' => true, 'tests' => ['exitCode' => 0], 'review' => ['verdict' => 'pass'], 'security' => ['verdict' => 'pass', 'summary' => 'No security problems found.', 'findings' => []], 'revision' => str_repeat('a', 40)]);
        });

        (new RunMissionTask($run->id))->handle($worker);

        $this->assertSame([$next->id], $this->queued);
    }

    public function test_a_run_released_back_to_the_queue_does_not_trigger_a_dispatch(): void
    {
        config(['mission.max_parallel_runs' => 1]);
        $this->recordingWorkflow();
        $slot = Cache::lock('mission-execution:1', 60);
        $slot->get();
        MissionTask::factory()->forGoal(Goal::factory()->create())->create();
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create(['status' => 'queued']);
        $worker = $this->mock(CodexProcess::class);

        try {
            $job = new RunMissionTask($run->id);
            $job->setJob(\Mockery::mock(Job::class)->shouldReceive('release')->once()->getMock());
            $job->handle($worker);
        } finally {
            $slot->release();
        }

        $this->assertSame([], $this->queued);
    }

    public function test_merging_a_task_triggers_a_dispatch(): void
    {
        $this->recordingWorkflow(mergeFake: true);
        $goal = Goal::factory()->create();
        $merging = MissionTask::factory()->forGoal($goal, 1)->readyForReview()->create(['status' => 'approved']);
        $next = MissionTask::factory()->forGoal($goal, 2)->create();

        $this->post(route('tasks.merge', $merging), ['revision' => str_repeat('a', 40)])->assertRedirect(route('tasks.show', $merging));

        $this->assertSame([$next->id], $this->queued);
    }

    public function test_the_dispatch_command_prints_the_count(): void
    {
        $this->recordingWorkflow();
        MissionTask::factory()->forGoal(Goal::factory()->create())->create();

        $this->artisan('mission:dispatch')->expectsOutput('Started 1 goal task(s).')->assertExitCode(0);
    }

    public function test_the_dispatch_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'mission:dispatch'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function recordingWorkflow(bool $mergeFake = false): void
    {
        $this->mock(MissionWorkflow::class, function ($mock) use ($mergeFake): void {
            $mock->shouldReceive('queue')->andReturnUsing(function (MissionTask $task): void {
                $this->queued[] = $task->id;
                $task->update(['status' => 'queued']);
            });
            if ($mergeFake) {
                $mock->shouldReceive('merge')->once()->andReturnUsing(fn (MissionTask $task) => $task->update(['status' => 'merged']));
            }
        });
    }

    private function readyEnvironment(): void
    {
        config(['mission.openai_api_key' => 'test-key-not-a-real-secret', 'mission.repository_path' => base_path(), 'mission.base_branch' => 'main', 'mission.worker_path' => base_path('artisan'), 'mission.test_command' => ['php', 'artisan', 'test']]);
    }
}
