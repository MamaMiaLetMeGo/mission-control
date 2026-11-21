<?php

namespace Tests\Feature;

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use App\Models\Decision;
use App\Models\Lead;
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
        $payload = MissionTask::factory()->raw(['title' => 'Improve lesson controls', 'status' => 'approved']) + ['lead' => 'engineering'];

        $response = $this->post(route('tasks.store'), $payload);

        $task = MissionTask::query()->sole();
        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('backlog', $task->status);
        $this->assertSame('Improve lesson controls', $task->title);
        Queue::assertNothingPushed();
    }

    public function test_a_task_is_created_for_the_chosen_lead(): void
    {
        $this->post(route('tasks.store'), ['title' => 'T', 'request' => 'R', 'acceptance_criteria' => 'A', 'lead' => 'design', 'priority' => 'normal'])->assertRedirect();
        $this->assertSame('design', MissionTask::query()->sole()->lead->key);
    }

    public function test_a_deactivated_lead_cannot_receive_new_tasks(): void
    {
        Lead::query()->where('key', 'design')->update(['is_active' => false]);
        $this->post(route('tasks.store'), ['title' => 'T', 'request' => 'R', 'acceptance_criteria' => 'A', 'lead' => 'design', 'priority' => 'normal'])->assertSessionHasErrors('lead');
    }

    public function test_requires_a_request_and_acceptance_criteria(): void
    {
        $response = $this->post(route('tasks.store'), []);

        $response->assertSessionHasErrors(['title', 'request', 'acceptance_criteria', 'lead', 'priority']);
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

    public function test_the_run_is_recorded_under_the_tasks_lead(): void
    {
        Queue::fake([RunMissionTask::class]);
        $this->readyEnvironment();
        $this->mock(GitRepository::class, function ($mock): void {
            $mock->shouldReceive('command')->andReturn(base_path(), str_repeat('a', 40));
            $mock->shouldReceive('remoteSnapshot')->andReturn(['ref' => 'refs/remotes/origin/main', 'revision' => str_repeat('a', 40)]);
        });
        $task = MissionTask::factory()->for(Lead::query()->where('key', 'design')->firstOrFail())->create();

        $this->post(route('tasks.run', $task))->assertRedirect(route('tasks.show', $task));

        $this->assertSame('design', $task->runs()->sole()->role);
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

    public function test_a_second_task_can_start_while_another_is_running(): void
    {
        Queue::fake([RunMissionTask::class]);
        $this->readyEnvironment();
        $this->fakeRepository();
        $active = MissionTask::factory()->create(['status' => 'running']);
        $task = MissionTask::factory()->create();

        $response = $this->post(route('tasks.run', $task));

        $response->assertRedirect(route('tasks.show', $task));
        $response->assertSessionHasNoErrors();
        $this->assertSame('running', $active->refresh()->status);
        $this->assertSame('queued', $task->refresh()->status);
        Queue::assertPushed(RunMissionTask::class, 1);
    }

    public function test_an_exhausted_daily_token_limit_blocks_another_run(): void
    {
        Queue::fake();
        $this->freezeTime();
        $this->readyEnvironment();
        config(['mission.daily_token_limit' => 10000]);
        AgentRun::factory()->create(['status' => 'completed', 'usage' => ['input_tokens' => 8000, 'output_tokens' => 2000]]);
        $task = MissionTask::factory()->create();

        $response = $this->post(route('tasks.run', $task));

        $response->assertSessionHasErrors(['task' => 'The daily token limit of 10000 has been reached. Raise it in Settings or wait until tomorrow.']);
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

    public function test_the_task_page_says_how_many_times_reviewer_findings_were_fixed(): void
    {
        $fixed = MissionTask::factory()->readyForReview()->create(['review_data' => ['success' => true, 'reviewRounds' => 2, 'tests' => ['exitCode' => 0], 'review' => ['verdict' => 'pass', 'summary' => 'Good.', 'findings' => []]]]);
        $direct = MissionTask::factory()->readyForReview()->create(['review_data' => ['success' => true, 'reviewRounds' => 0, 'tests' => ['exitCode' => 0], 'review' => ['verdict' => 'pass', 'summary' => 'Good.', 'findings' => []]]]);

        $this->get(route('tasks.show', $fixed))->assertOk()->assertSee('The sub-agent fixed reviewer findings 2 time(s) before this review.');
        $this->get(route('tasks.show', $direct))->assertOk()->assertDontSee('fixed reviewer findings');
    }

    public function test_the_fix_count_shows_for_a_final_rejection_but_not_when_the_last_review_never_completed(): void
    {
        $rejected = MissionTask::factory()->readyForReview()->create(['review_data' => ['success' => false, 'reviewRounds' => 1, 'error' => 'The independent reviewer requested changes.', 'tests' => ['exitCode' => 0], 'review' => ['verdict' => 'changes_requested', 'summary' => 'Still wrong.', 'findings' => ['Wrong.']]]]);
        $unfinished = MissionTask::factory()->readyForReview()->create(['review_data' => ['success' => false, 'reviewRounds' => 1, 'error' => 'The configured tests failed (exit 3).', 'tests' => ['exitCode' => 3], 'review' => ['verdict' => 'changes_requested', 'summary' => 'Independent review has not completed.', 'findings' => []]]]);

        $this->get(route('tasks.show', $rejected))->assertOk()->assertSee('The sub-agent fixed reviewer findings 1 time(s) before this review.');
        $this->get(route('tasks.show', $unfinished))->assertOk()->assertDontSee('fixed reviewer findings');
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

    private function fakeRepository(): void
    {
        $this->mock(GitRepository::class, function ($mock): void {
            $mock->shouldReceive('command')->andReturn(base_path(), str_repeat('a', 40));
            $mock->shouldReceive('remoteSnapshot')->andReturn(['ref' => 'refs/remotes/origin/main', 'revision' => str_repeat('a', 40)]);
        });
    }

    private function readyEnvironment(): void
    {
        config(['mission.openai_api_key' => 'test-key-not-a-real-secret', 'mission.repository_path' => base_path(), 'mission.base_branch' => 'main', 'mission.worker_path' => base_path('artisan'), 'mission.test_command' => ['php', 'artisan', 'test']]);
    }
}
