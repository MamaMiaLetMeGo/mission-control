<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\MissionTask;
use App\Services\GitRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MissionMergeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_approval_records_a_revision_without_merging_it(): void
    {
        [$task, $repository, $worktree] = $this->reviewedTask();

        $response = $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $task->revision]);

        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('approved', $task->refresh()->status);
        $this->assertSame($task->base_revision, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertDatabaseHas('decisions', ['mission_task_id' => $task->id, 'action' => 'approve', 'revision' => $task->revision]);
    }

    public function test_changes_after_review_invalidate_approval(): void
    {
        [$task, $repository, $worktree] = $this->reviewedTask();
        file_put_contents($worktree.'/lesson.txt', "Changed after review\n");

        $response = $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $task->revision]);

        $response->assertSessionHasErrors('task');
        $this->assertSame('changes_requested', $task->refresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_a_deferred_review_can_be_approved_without_another_run(): void
    {
        [$task] = $this->reviewedTask();
        $task->update(['status' => 'deferred']);

        $response = $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $task->revision]);

        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('approved', $task->refresh()->status);
        $this->assertDatabaseCount('agent_runs', 1);
    }

    public function test_explicit_merge_fast_forwards_a_clean_base_to_the_approved_revision(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);

        $response = $this->post(route('tasks.merge', $task), ['revision' => $task->revision]);

        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('merged', $task->refresh()->status);
        $this->assertSame($task->revision, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertSame("Updated lesson\n", file_get_contents($repository.'/lesson.txt'));
        $this->assertDatabaseHas('decisions', ['mission_task_id' => $task->id, 'action' => 'merge']);
    }

    public function test_merge_refuses_a_dirty_source_checkout_without_changing_it(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);
        file_put_contents($repository.'/lesson.txt', "User work in progress\n");

        $response = $this->post(route('tasks.merge', $task), ['revision' => $task->revision]);

        $response->assertSessionHasErrors('task');
        $this->assertSame('approved', $task->refresh()->status);
        $this->assertSame("User work in progress\n", file_get_contents($repository.'/lesson.txt'));
        $this->assertSame($task->base_revision, $this->git($repository, ['rev-parse', 'HEAD']));
    }

    public function test_a_changed_base_invalidates_the_old_approval(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);
        file_put_contents($repository.'/other.txt', "Another feature\n");
        $this->git($repository, ['add', 'other.txt']);
        $this->git($repository, ['commit', '-m', 'Other work']);
        $newBase = $this->git($repository, ['rev-parse', 'HEAD']);

        $response = $this->post(route('tasks.merge', $task), ['revision' => $task->revision]);

        $response->assertSessionHasErrors('task');
        $this->assertSame('changes_requested', $task->refresh()->status);
        $this->assertSame($newBase, $this->git($repository, ['rev-parse', 'HEAD']));
    }

    public function test_merge_reconciles_a_previous_success_if_database_recording_was_interrupted(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);
        $this->git($repository, ['merge', '--ff-only', $task->revision]);

        $response = $this->post(route('tasks.merge', $task), ['revision' => $task->revision]);

        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('merged', $task->refresh()->status);
        $this->assertSame(1, $task->decisions()->where('action', 'merge')->count());
    }

    public function test_artifact_downloads_cannot_follow_a_symlink_outside_the_recorded_directory(): void
    {
        [$task] = $this->reviewedTask();
        $root = $this->directories[0];
        mkdir($root.'/artifacts');
        mkdir($root.'/artifacts/task');
        config(['mission.artifact_root' => $root.'/artifacts']);
        file_put_contents($root.'/secret.txt', 'private fixture data');
        symlink($root.'/secret.txt', $root.'/artifacts/task/result.json');
        $task->update(['artifact_path' => $root.'/artifacts/task']);

        $this->get(route('tasks.artifact', [$task, 'result.json']))->assertNotFound();
    }

    public function test_artifact_download_serves_only_the_allowlisted_evidence(): void
    {
        [$task] = $this->reviewedTask();
        $root = $this->directories[0];
        mkdir($root.'/artifacts');
        mkdir($root.'/artifacts/task');
        config(['mission.artifact_root' => $root.'/artifacts']);
        file_put_contents($root.'/artifacts/task/diff.patch', 'fixture diff');
        $task->update(['artifact_path' => $root.'/artifacts/task']);

        $this->get(route('tasks.artifact', [$task, 'diff.patch']))->assertOk()->assertDownload('diff.patch');
        $this->get(route('tasks.artifact', [$task, 'unknown.txt']))->assertNotFound();
    }

    public function test_local_merge_does_not_execute_repository_hooks(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);
        $marker = $repository.'/hook-ran.txt';
        file_put_contents($repository.'/.git/hooks/post-merge', "#!/bin/sh\n: > ".escapeshellarg($marker)."\n");
        chmod($repository.'/.git/hooks/post-merge', 0700);

        $response = $this->post(route('tasks.merge', $task), ['revision' => $task->revision]);

        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('merged', $task->refresh()->status);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_merge_can_use_a_clean_configured_checkout_without_changing_dirty_original_work(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);
        $run = $task->runs()->sole();
        $snapshot = $run->settings;
        $this->git($repository, ['checkout', '-b', 'user-work']);
        file_put_contents($repository.'/lesson.txt', "Uncommitted user changes\n");
        $destination = dirname($repository).'/integration';
        $this->git($repository, ['worktree', 'add', $destination, 'main']);
        config(['mission.repository_path' => $destination]);

        $response = $this->post(route('tasks.merge', $task), ['revision' => $task->revision]);

        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('merged', $task->refresh()->status);
        $this->assertSame($task->revision, $this->git($destination, ['rev-parse', 'HEAD']));
        $this->assertSame($task->base_revision, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertSame('user-work', $this->git($repository, ['branch', '--show-current']));
        $this->assertSame("Uncommitted user changes\n", file_get_contents($repository.'/lesson.txt'));
        $this->assertSame($snapshot, $run->refresh()->settings);
        $this->assertStringContainsString($destination, $task->decisions()->where('action', 'merge')->sole()->feedback);
    }

    public function test_merge_refuses_a_different_repository_even_with_the_same_base_revision(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);
        $foreign = dirname($repository).'/foreign';
        $this->git($repository, ['clone', $repository, $foreign]);
        config(['mission.repository_path' => $foreign]);

        $this->post(route('tasks.merge', $task), ['revision' => $task->revision])->assertSessionHasErrors('task');

        $this->assertSame('approved', $task->refresh()->status);
        $this->assertSame($task->base_revision, $this->git($foreign, ['rev-parse', 'HEAD']));
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_merge_refuses_a_changed_configured_base_branch(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);
        $this->git($repository, ['checkout', '-b', 'other-base']);
        config(['mission.base_branch' => 'other-base']);

        $this->post(route('tasks.merge', $task), ['revision' => $task->revision])->assertSessionHasErrors('task');

        $this->assertSame('approved', $task->refresh()->status);
        $this->assertSame($task->base_revision, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_approval_is_invalidated_when_the_local_base_changed_after_review(): void
    {
        [$task, $repository] = $this->reviewedTask();
        file_put_contents($repository.'/other.txt', "New local base work\n");
        $this->git($repository, ['add', 'other.txt']);
        $this->git($repository, ['commit', '-m', 'New base']);

        $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $task->revision])->assertSessionHasErrors('task');

        $this->assertSame('changes_requested', $task->refresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_legacy_merge_fetches_and_refuses_a_remote_that_changed_after_review(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);
        $publisher = $this->remote($repository);
        file_put_contents($publisher.'/remote.txt', "New upstream work\n");
        $this->git($publisher, ['add', 'remote.txt']);
        $this->git($publisher, ['commit', '-m', 'Remote changed']);
        $this->git($publisher, ['push', 'origin', 'main']);
        $remote = $this->git($publisher, ['rev-parse', 'HEAD']);
        $this->assertSame($task->base_revision, $this->git($repository, ['rev-parse', 'origin/main']));

        $this->post(route('tasks.merge', $task), ['revision' => $task->revision])->assertSessionHasErrors('task');

        $this->assertSame('changes_requested', $task->refresh()->status);
        $this->assertSame($task->base_revision, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertSame($remote, $this->git($repository, ['rev-parse', 'origin/main']));
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_merge_checks_remote_freshness_with_a_longer_bounded_php_budget(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);
        $this->remote($repository);
        $oldLimit = (int) ini_get('max_execution_time');
        set_time_limit(30);
        try {
            $response = $this->post(route('tasks.merge', $task), ['revision' => $task->revision]);

            $response->assertRedirect(route('tasks.show', $task));
            $this->assertSame(150, (int) ini_get('max_execution_time'));
            $this->assertSame('merged', $task->refresh()->status);
            $this->assertSame($task->revision, $this->git($repository, ['rev-parse', 'HEAD']));
        } finally {
            set_time_limit($oldLimit);
        }
    }

    public function test_a_fetched_upstream_change_invalidates_approval_for_a_snapshotted_run(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $publisher = $this->remote($repository);
        $run = $task->runs()->sole();
        $run->update(['settings' => [...$run->settings, 'remote_snapshot' => app(GitRepository::class)->remoteSnapshot($repository, 'main')]]);
        file_put_contents($publisher.'/remote.txt', "Upstream change\n");
        $this->git($publisher, ['add', '.']);
        $this->git($publisher, ['commit', '-m', 'Upstream change']);
        $this->git($publisher, ['push', 'origin', 'main']);
        $this->git($repository, ['fetch', 'origin', 'main']);

        $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $task->revision])->assertSessionHasErrors('task');

        $this->assertSame('changes_requested', $task->refresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_a_checkout_changed_during_remote_fetch_is_not_merged(): void
    {
        [$task, $repository] = $this->reviewedTask();
        $task->update(['status' => 'approved']);
        $this->remote($repository);
        $this->partialMock(GitRepository::class, function ($mock) use ($repository, $task): void {
            $mock->shouldReceive('fetchUpstream')->once()->andReturnUsing(function () use ($repository, $task): array {
                file_put_contents($repository.'/lesson.txt', "User changed the checkout during fetch\n");

                return ['ref' => 'refs/remotes/origin/main', 'revision' => $task->base_revision];
            });
        });

        $this->post(route('tasks.merge', $task), ['revision' => $task->revision])->assertSessionHasErrors('task');

        $this->assertSame('approved', $task->refresh()->status);
        $this->assertSame($task->base_revision, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertSame("User changed the checkout during fetch\n", file_get_contents($repository.'/lesson.txt'));
    }

    public function test_merge_preserves_ignored_files_that_the_reviewed_commit_would_overwrite(): void
    {
        [$task, $repository, $worktree] = $this->reviewedTask();
        file_put_contents($worktree.'/new-file.txt', "Reviewed new file\n");
        $this->git($worktree, ['add', 'new-file.txt']);
        $this->git($worktree, ['commit', '-m', 'Add file']);
        $task->update(['status' => 'approved', 'revision' => $this->git($worktree, ['rev-parse', 'HEAD'])]);
        file_put_contents($repository.'/.git/info/exclude', "new-file.txt\n", FILE_APPEND);
        file_put_contents($repository.'/new-file.txt', "User-owned ignored file\n");

        $this->post(route('tasks.merge', $task), ['revision' => $task->revision])->assertSessionHasErrors('task');

        $this->assertSame('approved', $task->refresh()->status);
        $this->assertSame("User-owned ignored file\n", file_get_contents($repository.'/new-file.txt'));
        $this->assertSame($task->base_revision, $this->git($repository, ['rev-parse', 'HEAD']));
    }

    private function remote(string $repository): string
    {
        $root = dirname($repository);
        $origin = $root.'/origin.git';
        $publisher = $root.'/publisher';
        $this->git($root, ['init', '--bare', '-b', 'main', $origin]);
        $this->git($repository, ['remote', 'add', 'origin', $origin]);
        $this->git($repository, ['push', '-u', 'origin', 'main']);
        $this->git($root, ['clone', $origin, $publisher]);
        $this->git($publisher, ['config', 'user.email', 'test@example.test']);
        $this->git($publisher, ['config', 'user.name', 'Mission Control Test']);

        return $publisher;
    }

    private function reviewedTask(): array
    {
        $root = storage_path('framework/testing/mission-'.Str::uuid());
        $this->directories[] = $root;
        mkdir($root.'/repository', 0700, true);
        mkdir($root.'/worktrees', 0700, true);
        config(['mission.worktree_root' => $root.'/worktrees']);
        $repository = $root.'/repository';
        $worktree = $root.'/worktrees/task';
        config(['mission.repository_path' => $repository, 'mission.base_branch' => 'main']);
        $this->git($repository, ['init', '-b', 'main']);
        $this->git($repository, ['config', 'user.email', 'test@example.test']);
        $this->git($repository, ['config', 'user.name', 'Mission Control Test']);
        file_put_contents($repository.'/lesson.txt', "Original lesson\n");
        $this->git($repository, ['add', 'lesson.txt']);
        $this->git($repository, ['commit', '-m', 'Initial lesson']);
        $baseRevision = $this->git($repository, ['rev-parse', 'HEAD']);
        $this->git($repository, ['worktree', 'add', '-b', 'mission/test-task', $worktree, $baseRevision]);
        file_put_contents($worktree.'/lesson.txt', "Updated lesson\n");
        $this->git($worktree, ['add', 'lesson.txt']);
        $this->git($worktree, ['commit', '-m', 'Improve lesson']);
        $revision = $this->git($worktree, ['rev-parse', 'HEAD']);
        $task = MissionTask::factory()->readyForReview()->create(['revision' => $revision, 'base_revision' => $baseRevision, 'worktree_path' => $worktree, 'branch' => 'mission/test-task']);
        AgentRun::factory()->for($task, 'task')->create(['status' => 'completed', 'settings' => ['repository_path' => $repository, 'base_branch' => 'main']]);

        return [$task, $repository, $worktree];
    }

    private function git(string $path, array $arguments): string
    {
        $process = new Process(['git', '-c', 'core.hooksPath=/dev/null', '-C', $path, ...$arguments]);
        $process->setTimeout(10);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
