<?php

namespace Tests\Feature;

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use App\Models\Decision;
use App\Models\MissionTask;
use App\Services\GitRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MissionTaskControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_backlog_task_without_accepting_a_client_status(): void
    {
        Queue::fake();
        $payload = MissionTask::factory()->raw(['title' => 'Improve lesson controls', 'status' => 'approved']);

        $response = $this->post(route('tasks.store'), $payload);

        $task = MissionTask::query()->sole();
        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('backlog', $task->status);
        $this->assertSame('Improve lesson controls', $task->title);
        Queue::assertNothingPushed();
    }

    public function test_requires_a_request_and_acceptance_criteria(): void
    {
        $response = $this->post(route('tasks.store'), []);

        $response->assertSessionHasErrors(['title', 'request', 'acceptance_criteria', 'area', 'priority']);
        $this->assertDatabaseCount('mission_tasks', 0);
    }

    public function test_queues_one_run_and_preserves_settings_for_the_run(): void
    {
        Queue::fake([RunMissionTask::class]);
        $this->readyEnvironment();
        $this->mock(GitRepository::class, function ($mock): void {
            $mock->shouldReceive('command')->once()->with(base_path(), ['rev-parse', '--show-toplevel'])->andReturn(base_path());
            $mock->shouldReceive('command')->once()->with(base_path(), ['rev-parse', '--verify', 'refs/heads/main'])->andReturn(str_repeat('a', 40));
            $mock->shouldReceive('remoteSnapshot')->once()->with(base_path(), 'main')->andReturn(['ref' => 'refs/remotes/origin/main', 'revision' => str_repeat('a', 40)]);
        });
        $task = MissionTask::factory()->create();

        $response = $this->post(route('tasks.run', $task));

        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('queued', $task->refresh()->status);
        $run = $task->runs()->sole();
        $this->assertSame(base_path(), $run->settings['repository_path']);
        $this->assertSame(['ref' => 'refs/remotes/origin/main', 'revision' => str_repeat('a', 40)], $run->settings['remote_snapshot']);
        Queue::assertPushed(RunMissionTask::class, fn (RunMissionTask $job): bool => $job->runId === $run->id);
    }

    public function test_missing_credentials_do_not_queue_a_task(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        config(['mission.openai_api_key' => '']);
        $task = MissionTask::factory()->create();

        $response = $this->post(route('tasks.run', $task));

        $response->assertSessionHasErrors('task');
        $this->assertSame('backlog', $task->refresh()->status);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_an_active_task_blocks_another_run(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        MissionTask::factory()->create(['status' => 'running']);
        $task = MissionTask::factory()->create();

        $response = $this->post(route('tasks.run', $task));

        $response->assertSessionHasErrors(['task' => 'One task is already active. Wait for it to finish before starting another.']);
        $this->assertSame('backlog', $task->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_daily_run_limit_blocks_spending_on_another_run(): void
    {
        Queue::fake();
        $this->freezeTime();
        $this->readyEnvironment();
        config(['mission.daily_run_limit' => 1]);
        AgentRun::factory()->create(['status' => 'completed']);
        $task = MissionTask::factory()->create();

        $response = $this->post(route('tasks.run', $task));

        $response->assertSessionHasErrors(['task' => 'The daily run limit has been reached. Increase it in Settings or wait until tomorrow.']);
        $this->assertSame('backlog', $task->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_approval_requires_passing_tests_and_independent_review(): void
    {
        $task = MissionTask::factory()->readyForReview()->create(['review_data' => ['success' => true, 'tests' => ['exitCode' => 1], 'review' => ['verdict' => 'pass']]]);

        $response = $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $task->revision]);

        $response->assertSessionHasErrors('task');
        $this->assertSame('review', $task->refresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_approval_is_bound_to_the_revision_shown_to_the_user(): void
    {
        $task = MissionTask::factory()->readyForReview()->create();

        $response = $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => str_repeat('c', 40)]);

        $response->assertSessionHasErrors('task');
        $this->assertSame('review', $task->refresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_requesting_changes_preserves_feedback_and_revision(): void
    {
        $task = MissionTask::factory()->readyForReview()->create();

        $response = $this->post(route('tasks.decide', $task), ['action' => 'request_changes', 'feedback' => 'Make the play button easier to reach.']);

        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('changes_requested', $task->refresh()->status);
        $decision = Decision::query()->sole();
        $this->assertSame('Make the play button easier to reach.', $decision->feedback);
        $this->assertSame($task->revision, $decision->revision);
    }

    public function test_requesting_changes_requires_a_reason(): void
    {
        $task = MissionTask::factory()->readyForReview()->create();

        $response = $this->post(route('tasks.decide', $task), ['action' => 'request_changes']);

        $response->assertSessionHasErrors('feedback');
        $this->assertSame('review', $task->refresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_task_pages_escape_user_supplied_content(): void
    {
        $task = MissionTask::factory()->create(['title' => '<script>alert(1)</script>', 'request' => '<img src=x onerror=alert(1)>']);

        $response = $this->get(route('tasks.show', $task));

        $response->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_a_missing_task_returns_404(): void
    {
        $this->get('/tasks/99999')->assertNotFound();
    }

    private function readyEnvironment(): void
    {
        config(['mission.openai_api_key' => 'test-key-not-a-real-secret', 'mission.repository_path' => base_path(), 'mission.base_branch' => 'main', 'mission.worker_path' => base_path('artisan'), 'mission.test_command' => ['php', 'artisan', 'test']]);
    }
}
