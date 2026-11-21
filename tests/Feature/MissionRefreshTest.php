<?php

namespace Tests\Feature;

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use App\Models\MissionTask;
use App\Services\CodexProcess;
use App\Services\MissionWorkflow;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MissionRefreshTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_refresh_is_flagged_not_queued_when_the_token_limit_is_reached(): void
    {
        Queue::fake();
        $this->freezeTime();
        $this->readyEnvironment();
        config(['mission.daily_token_limit' => 10000]);
        AgentRun::factory()->create(['status' => 'completed', 'usage' => ['input_tokens' => 8000, 'output_tokens' => 2000]]);
        $task = MissionTask::factory()->readyForReview()->create(['status' => 'approved']);

        $queued = app(MissionWorkflow::class)->refresh($task);

        $this->assertFalse($queued);
        $task->refresh();
        $this->assertSame('approved', $task->status);
        $this->assertSame(str_repeat('a', 40), $task->revision);
        $this->assertSame('The base moved and the daily token limit is reached. Refresh this task tomorrow or raise the limit.', $task->error);
        $this->assertSame(0, $task->runs()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_refreshed_task_needs_a_new_approval(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        config(['mission.openai_api_key' => 'test-key-not-a-real-secret']);
        $task = MissionTask::factory()->readyForReview()->create(['status' => 'approved', 'recommendation' => 'Improved the lesson.']);
        $oldRevision = $task->revision;
        $task->decisions()->create(['action' => 'approve', 'revision' => $oldRevision]);

        $this->assertTrue(app(MissionWorkflow::class)->refresh($task));

        $run = $task->runs()->first();
        $this->assertSame('queued', $task->refresh()->status);
        $result = ['type' => 'result', 'success' => true, 'summary' => 'Improved the lesson.', 'revision' => str_repeat('c', 40), 'baseRevision' => str_repeat('d', 40), 'review' => ['verdict' => 'pass'], 'tests' => ['exitCode' => 0]];
        $worker = $this->mock(CodexProcess::class, function ($mock) use ($result, $run): void {
            $mock->shouldReceive('run')->once()->withArgs(fn ($given) => $given->id === $run->id)->andReturn($result);
        });
        (new RunMissionTask($run->id))->handle($worker);

        $task->refresh();
        $this->assertSame('review', $task->status);
        $this->assertSame(str_repeat('c', 40), $task->revision);
        $this->post(route('tasks.merge', $task), ['revision' => $oldRevision])->assertSessionHasErrors(['task' => 'Only the currently approved revision can be merged.']);
        $this->post(route('tasks.merge', $task), ['revision' => $task->revision])->assertSessionHasErrors(['task' => 'Only the currently approved revision can be merged.']);
        $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $oldRevision])->assertSessionHasErrors(['task' => 'Reload the current review before approving its exact revision.']);
        $this->assertSame('review', $task->refresh()->status);
        $this->assertSame(1, $task->decisions()->where('action', 'approve')->count());
    }

    public function test_refresh_runs_do_not_count_against_the_daily_run_limit(): void
    {
        Queue::fake();
        $this->freezeTime();
        $this->readyEnvironment();
        config(['mission.daily_run_limit' => 1]);
        AgentRun::factory()->create(['status' => 'completed', 'settings' => ['mode' => 'refresh']]);
        $task = MissionTask::factory()->create();

        $this->post(route('tasks.run', $task))->assertSessionHasNoErrors();

        $this->assertSame('queued', $task->refresh()->status);
        $reviewed = MissionTask::factory()->readyForReview()->create();
        $this->assertTrue(app(MissionWorkflow::class)->refresh($reviewed));
        $this->assertSame('queued', $reviewed->refresh()->status);
        $this->post(route('tasks.run', MissionTask::factory()->create()))->assertSessionHasErrors(['task' => 'The daily run limit has been reached. Increase it in Settings or wait until tomorrow.']);
        Queue::assertPushed(RunMissionTask::class, 2);
    }

    public function test_a_normal_run_after_a_failed_refresh_does_not_reuse_refresh_settings(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        $task = MissionTask::factory()->create(['status' => 'failed', 'error' => 'This change no longer applies to the current base. Run the task again.']);
        AgentRun::factory()->for($task, 'task')->create(['status' => 'failed', 'settings' => ['mode' => 'refresh', 'source_revision' => str_repeat('a', 40), 'previous_summary' => 'Earlier work.']]);

        $this->post(route('tasks.run', $task))->assertSessionHasNoErrors();

        $settings = $task->runs()->first()->settings;
        $this->assertSame(2, $task->runs()->count());
        $this->assertArrayNotHasKey('mode', $settings);
        $this->assertArrayNotHasKey('source_revision', $settings);
        $this->assertArrayNotHasKey('previous_summary', $settings);
    }

    public function test_the_refresh_route_retries_a_refresh_by_hand(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        $task = MissionTask::factory()->readyForReview()->create(['error' => 'The base moved and the daily token limit is reached. Refresh this task tomorrow or raise the limit.']);
        $revision = $task->revision;

        $response = $this->post(route('tasks.refresh', $task));

        $response->assertRedirect(route('tasks.show', $task));
        $response->assertSessionHasNoErrors();
        $task->refresh();
        $this->assertSame('queued', $task->status);
        $this->assertNull($task->error);
        $this->assertSame($revision, $task->runs()->first()->settings['source_revision']);
        Queue::assertPushed(RunMissionTask::class, 1);
    }

    public function test_refresh_refuses_a_task_without_a_reviewed_revision(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        $task = MissionTask::factory()->create(['status' => 'review']);
        $backlog = MissionTask::factory()->create();

        $this->post(route('tasks.refresh', $task))->assertSessionHasErrors(['task' => 'Only reviewed or approved tasks with a revision can be refreshed.']);
        $this->post(route('tasks.refresh', $backlog))->assertSessionHasErrors('task');

        $this->assertSame('review', $task->refresh()->status);
        $this->assertSame('backlog', $backlog->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_the_previous_summary_falls_back_to_the_title_and_is_bounded(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        $untitled = MissionTask::factory()->readyForReview()->create(['title' => 'Tidy the lesson page', 'recommendation' => null]);
        $long = MissionTask::factory()->readyForReview()->create(['recommendation' => str_repeat('é', 25000)]);

        app(MissionWorkflow::class)->refresh($untitled);
        app(MissionWorkflow::class)->refresh($long);

        $this->assertSame('Tidy the lesson page', $untitled->runs()->first()->settings['previous_summary']);
        $this->assertSame(str_repeat('é', 20000), $long->runs()->first()->settings['previous_summary']);
    }

    public function test_a_refresh_that_failed_at_start_can_be_retried_with_the_original_reviewed_revision(): void
    {
        Queue::fake();
        $this->freezeTime();
        $this->readyEnvironment();
        config(['mission.daily_token_limit' => 10000]);
        $task = MissionTask::factory()->readyForReview()->create(['status' => 'approved', 'recommendation' => 'Improved the lesson.']);
        $reviewedRevision = $task->revision;
        $this->assertTrue(app(MissionWorkflow::class)->refresh($task));
        $spent = AgentRun::factory()->create(['status' => 'completed', 'usage' => ['input_tokens' => 8000, 'output_tokens' => 2000]]);
        $worker = $this->mock(CodexProcess::class, function ($mock): void {
            $mock->shouldNotReceive('run');
            $mock->shouldReceive('redact')->andReturnUsing(fn (string $text) => $text);
        });

        (new RunMissionTask($task->runs()->first()->id))->handle($worker);

        $task->refresh();
        $this->assertSame('failed', $task->status);
        $this->assertNull($task->revision);
        $this->assertFalse(app(MissionWorkflow::class)->refresh($task));
        $this->assertSame('failed', $task->refresh()->status);
        $this->assertSame('The base moved and the daily token limit is reached. Refresh this task tomorrow or raise the limit.', $task->error);
        $this->assertSame(1, $task->runs()->count());
        $spent->update(['usage' => null]);

        $this->assertTrue(app(MissionWorkflow::class)->refresh($task));

        $task->refresh();
        $this->assertSame('queued', $task->status);
        $settings = $task->runs()->first()->settings;
        $this->assertSame(2, $task->runs()->count());
        $this->assertSame('refresh', $settings['mode']);
        $this->assertSame($reviewedRevision, $settings['source_revision']);
        $this->assertSame('Improved the lesson.', $settings['previous_summary']);
    }

    public function test_a_failed_normal_run_cannot_be_refreshed(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        $task = MissionTask::factory()->create(['status' => 'failed', 'error' => 'Verification did not pass.']);
        AgentRun::factory()->for($task, 'task')->create(['status' => 'failed', 'settings' => ['repository_path' => base_path(), 'base_branch' => 'main']]);

        try {
            app(MissionWorkflow::class)->refresh($task);
            $this->fail('A failed normal run must not be refreshable.');
        } catch (ValidationException $exception) {
            $this->assertSame('Only reviewed or approved tasks with a revision can be refreshed.', $exception->errors()['task'][0]);
        }

        $this->assertSame('failed', $task->refresh()->status);
        $this->assertSame(1, $task->runs()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_task_page_explains_a_refresh(): void
    {
        $this->readyEnvironment();
        $refreshing = MissionTask::factory()->create(['status' => 'queued']);
        AgentRun::factory()->for($refreshing, 'task')->create(['settings' => ['mode' => 'refresh', 'source_revision' => str_repeat('a', 40), 'previous_summary' => 'Earlier work.']]);
        $flagged = MissionTask::factory()->readyForReview()->create(['error' => 'The base moved and the daily token limit is reached. Refresh this task tomorrow or raise the limit.']);
        $failedRefresh = MissionTask::factory()->create(['status' => 'failed', 'error' => 'This change no longer applies to the current base. Run the task again.']);
        AgentRun::factory()->for($failedRefresh, 'task')->create(['status' => 'failed', 'settings' => ['mode' => 'refresh', 'source_revision' => str_repeat('a', 40), 'previous_summary' => 'Earlier work.']]);
        $failedNormal = MissionTask::factory()->create(['status' => 'failed', 'error' => 'Verification did not pass.']);
        AgentRun::factory()->for($failedNormal, 'task')->create(['status' => 'failed']);
        $otherError = MissionTask::factory()->readyForReview()->create(['error' => 'Build the Codex worker before starting a task.']);
        $ordinary = MissionTask::factory()->readyForReview()->create();

        $this->get(route('tasks.show', $refreshing))->assertOk()->assertSee('Re-checking against the updated base');
        $this->get(route('tasks.show', $flagged))->assertOk()->assertSee('Refresh against current base')->assertSee(route('tasks.refresh', $flagged), false);
        $this->get(route('tasks.show', $failedRefresh))->assertOk()->assertSee('Refresh against current base')->assertSee(route('tasks.refresh', $failedRefresh), false);
        $this->get(route('tasks.show', $failedNormal))->assertOk()->assertDontSee('Refresh against current base');
        $this->get(route('tasks.show', $otherError))->assertOk()->assertDontSee('Refresh against current base');
        $this->get(route('tasks.show', $ordinary))->assertOk()->assertDontSee('Refresh against current base')->assertDontSee('Re-checking against the updated base');
    }

    private function readyEnvironment(): void
    {
        config(['mission.openai_api_key' => 'test-key-not-a-real-secret', 'mission.repository_path' => base_path(), 'mission.base_branch' => 'main', 'mission.worker_path' => base_path('artisan'), 'mission.test_command' => ['php', 'artisan', 'test']]);
    }
}
