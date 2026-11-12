<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\MissionTask;
use App\Services\GitRepository;
use App\Services\MissionSettings;
use App\Services\PreviewProcess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MissionPreviewControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_start_uses_the_reviewed_run_settings_and_exact_revision(): void
    {
        $this->freezeTime();
        $task = $this->taskWithPreview();
        app(MissionSettings::class)->update(['preview_command' => ['do-not-use-current-settings', '{port}']]);
        $handle = $this->handle($task);
        $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('assertReviewedRevision')->once());
        $this->mock(PreviewProcess::class, function ($mock) use ($task, $handle): void {
            $mock->shouldReceive('execute')->once()->with('status', ['taskId' => $task->id, 'previewRoot' => config('mission.preview_root'), 'revision' => $task->revision])->andReturn(['success' => true, 'preview' => null]);
            $mock->shouldReceive('execute')->once()->withArgs(fn (string $mode, array $input): bool => $mode === 'start' && $input['revision'] === $task->revision && $input['taskId'] === $task->id && $input['config']['command'] === ['node', 'server.js', '{port}'] && $input['repositoryPath'] === base_path())->andReturn(['success' => true, 'preview' => $handle]);
        });

        $response = $this->post(route('tasks.preview.start', $task), ['revision' => $task->revision]);

        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('review', $task->refresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_retrying_start_reuses_the_live_preview_for_the_same_revision(): void
    {
        $this->freezeTime();
        $task = $this->taskWithPreview();
        $handle = $this->handle($task);
        $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('assertReviewedRevision')->twice());
        $this->mock(PreviewProcess::class, function ($mock) use ($task, $handle): void {
            $mock->shouldReceive('execute')->twice()->with('status', ['taskId' => $task->id, 'previewRoot' => config('mission.preview_root'), 'revision' => $task->revision])->andReturn(['success' => true, 'preview' => $handle]);
            $mock->shouldNotReceive('execute')->withArgs(fn (string $mode, array $input): bool => $mode === 'start');
        });

        $this->post(route('tasks.preview.start', $task), ['revision' => $task->revision])->assertRedirect(route('tasks.show', $task));
        $this->post(route('tasks.preview.start', $task), ['revision' => $task->revision])->assertRedirect(route('tasks.show', $task));

        $this->assertSame('review', $task->refresh()->status);
        $this->assertDatabaseCount('agent_runs', 1);
    }

    public function test_ajax_start_returns_the_live_preview_without_a_redirect_or_flash(): void
    {
        $this->freezeTime();
        $task = $this->taskWithPreview();
        $handle = $this->handle($task);
        $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('assertReviewedRevision')->once());
        $this->mock(PreviewProcess::class, function ($mock) use ($task, $handle): void {
            $mock->shouldReceive('execute')->once()->with('status', ['taskId' => $task->id, 'previewRoot' => config('mission.preview_root'), 'revision' => $task->revision])->andReturn(['success' => true, 'preview' => $handle]);
        });

        $response = $this->postJson(route('tasks.preview.start', $task), ['revision' => $task->revision]);

        $response->assertOk()->assertExactJson(['message' => 'Preview started for this revision.', 'preview' => $handle])->assertSessionMissing('status');
        $this->assertSame('review', $task->refresh()->status);
    }

    public function test_ajax_stop_returns_offline_state_without_a_redirect_or_flash(): void
    {
        $task = $this->taskWithPreview();
        $this->mock(PreviewProcess::class, function ($mock) use ($task): void {
            $mock->shouldReceive('execute')->once()->with('stop', ['taskId' => $task->id, 'previewRoot' => config('mission.preview_root')])->andReturn(['success' => true, 'preview' => null]);
        });

        $response = $this->postJson(route('tasks.preview.stop', $task));

        $response->assertOk()->assertExactJson(['message' => 'Preview stopped.', 'preview' => null])->assertSessionMissing('status');
        $this->assertSame('review', $task->refresh()->status);
    }

    public function test_stale_page_cannot_start_a_preview_of_a_different_revision(): void
    {
        $task = $this->taskWithPreview();
        $this->mock(PreviewProcess::class, fn ($mock) => $mock->shouldNotReceive('execute'));

        $response = $this->post(route('tasks.preview.start', $task), ['revision' => str_repeat('f', 40)]);

        $response->assertSessionHasErrors('preview');
        $this->assertSame('review', $task->refresh()->status);
    }

    public function test_failed_start_does_not_publish_a_preview_url(): void
    {
        $task = $this->taskWithPreview();
        $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('assertReviewedRevision')->once());
        $this->mock(PreviewProcess::class, function ($mock) use ($task): void {
            $mock->shouldReceive('execute')->once()->with('status', ['taskId' => $task->id, 'previewRoot' => config('mission.preview_root'), 'revision' => $task->revision])->andReturn(['success' => true, 'preview' => null]);
            $mock->shouldReceive('execute')->once()->withArgs(fn (string $mode, array $payload): bool => $mode === 'start')->andThrow(ValidationException::withMessages(['preview' => 'The application failed to boot.']));
        });

        $response = $this->post(route('tasks.preview.start', $task), ['revision' => $task->revision]);

        $response->assertSessionHasErrors(['preview' => 'The application failed to boot.']);
        $this->assertArrayNotHasKey('preview', $task->refresh()->review_data);
        $this->assertSame('review', $task->status);
    }

    public function test_status_hides_a_stale_preview_handle(): void
    {
        $task = $this->taskWithPreview();
        $handle = [...$this->handle($task), 'revision' => str_repeat('e', 40)];
        $this->mock(PreviewProcess::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'preview' => $handle]));
        $this->mock(GitRepository::class, fn ($mock) => $mock->shouldNotReceive('command'));

        $this->get(route('tasks.preview.status', $task))->assertOk()->assertExactJson(['preview' => null]);
    }

    public function test_status_rejects_a_remote_url_from_the_preview_service(): void
    {
        $task = $this->taskWithPreview();
        $handle = [...$this->handle($task), 'url' => 'https://example.test'];
        $this->mock(PreviewProcess::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'preview' => $handle]));

        $this->get(route('tasks.preview.status', $task))->assertServiceUnavailable()->assertJsonPath('preview', null);
    }

    public function test_status_only_returns_a_live_handle_for_the_stable_reviewed_worktree(): void
    {
        $this->freezeTime();
        $task = $this->taskWithPreview();
        $handle = $this->handle($task);
        $this->mock(PreviewProcess::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'preview' => $handle]));
        $this->mock(GitRepository::class, function ($mock) use ($task): void {
            $mock->shouldReceive('command')->once()->with($task->worktree_path, ['rev-parse', 'HEAD'])->andReturn($task->revision);
            $mock->shouldReceive('command')->once()->with($task->worktree_path, ['status', '--porcelain'])->andReturn('');
        });

        $this->get(route('tasks.preview.status', $task))->assertOk()->assertJsonPath('preview.url', $handle['url'])->assertJsonPath('preview.revision', $task->revision);
    }

    public function test_active_runs_cannot_stop_the_capture_process(): void
    {
        $task = $this->taskWithPreview();
        $task->update(['status' => 'running']);
        $this->mock(PreviewProcess::class, fn ($mock) => $mock->shouldNotReceive('execute'));

        $this->post(route('tasks.preview.stop', $task))->assertSessionHasErrors('preview');
    }

    public function test_stop_uses_task_ownership_and_ignores_a_submitted_pid_or_id(): void
    {
        $task = $this->taskWithPreview();
        $this->mock(PreviewProcess::class, function ($mock) use ($task): void {
            $mock->shouldReceive('execute')->once()->with('stop', ['taskId' => $task->id, 'previewRoot' => config('mission.preview_root')])->andReturn(['success' => true, 'preview' => null]);
        });

        $this->post(route('tasks.preview.stop', $task), ['pid' => 1, 'id' => 'another-task'])->assertRedirect(route('tasks.show', $task));
    }

    public function test_failed_stop_preserves_review_evidence_and_prevents_a_rerun(): void
    {
        Queue::fake();
        $task = $this->taskWithPreview();
        $task->update(['status' => 'deferred']);
        config(['mission.openai_api_key' => 'test-key', 'mission.repository_path' => base_path(), 'mission.base_branch' => 'main', 'mission.worker_path' => base_path('artisan'), 'mission.test_command' => ['php', 'artisan', 'test']]);
        $this->mock(GitRepository::class, function ($mock): void {
            $mock->shouldReceive('command')->with(base_path(), ['rev-parse', '--show-toplevel'])->andReturn(base_path());
            $mock->shouldReceive('command')->with(base_path(), ['rev-parse', '--verify', 'refs/heads/main'])->andReturn(str_repeat('b', 40));
            $mock->shouldReceive('remoteSnapshot')->once()->with(base_path(), 'main')->andReturnNull();
        });
        $this->mock(PreviewProcess::class, fn ($mock) => $mock->shouldReceive('execute')->once()->andThrow(ValidationException::withMessages(['preview' => 'Could not stop the preview.'])));
        $oldRevision = $task->revision;

        $this->post(route('tasks.run', $task))->assertSessionHasErrors('preview');

        $this->assertSame('deferred', $task->refresh()->status);
        $this->assertSame($oldRevision, $task->revision);
        $this->assertDatabaseCount('agent_runs', 1);
        Queue::assertNothingPushed();
    }

    private function taskWithPreview(): MissionTask
    {
        $task = MissionTask::factory()->readyForReview()->create(['worktree_path' => '/test-worktree', 'artifact_path' => '/test-artifacts']);
        AgentRun::factory()->for($task, 'task')->create(['status' => 'completed', 'settings' => ['repository_path' => base_path(), 'preview_enabled' => true, 'preview_driver' => 'command', 'preview_directory' => '.', 'preview_command' => ['node', 'server.js', '{port}'], 'preview_paths' => ['/'], 'preview_ttl_seconds' => 1800]]);

        return $task;
    }

    private function handle(MissionTask $task): array
    {
        return ['id' => '5acba984-1481-4f8a-9b34-5f56a65e800c', 'url' => 'http://localhost:19284', 'revision' => $task->revision, 'expiresAt' => now()->addMinutes(20)->toISOString()];
    }
}
