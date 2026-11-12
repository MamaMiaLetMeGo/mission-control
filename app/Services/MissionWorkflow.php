<?php

namespace App\Services;

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use App\Models\MissionTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MissionWorkflow
{
    public function __construct(private MissionSettings $settings, private GitRepository $git, private MissionPreview $previews, private BrowserEvidence $evidence) {}

    public function queue(MissionTask $task): void
    {
        Cache::lock('mission-workflow', 120)->block(5, function () use ($task): void {
            $task->refresh();
            if (! in_array($task->status, ['backlog', 'changes_requested', 'deferred', 'failed'], true)) {
                $this->reject('Only backlog, deferred, failed, or changes-requested tasks can be started.');
            }
            $readiness = $this->settings->readiness();
            if (! $readiness['ready']) {
                $this->reject(implode(' ', $readiness['issues']));
            }
            if (MissionTask::query()->whereIn('status', ['queued', 'running'])->exists()) {
                $this->reject('One task is already active. Wait for it to finish before starting another.');
            }
            $settings = $this->settings->all();
            if (AgentRun::query()->where('created_at', '>=', now()->startOfDay())->count() >= $settings['daily_run_limit']) {
                $this->reject('The daily run limit has been reached. Increase it in Settings or wait until tomorrow.');
            }
            $repository = realpath($settings['repository_path']);
            $repositoryRoot = $this->git->command($repository, ['rev-parse', '--show-toplevel']);
            if (realpath($repositoryRoot) !== $repository) {
                $this->reject('Choose the Git repository root in Settings.');
            }
            $settings['repository_path'] = $repository;
            $settings['previous_review'] = $task->review_data;
            $this->git->command($repository, ['rev-parse', '--verify', 'refs/heads/'.$settings['base_branch']]);
            $settings['remote_snapshot'] = $this->git->remoteSnapshot($repository, $settings['base_branch']);
            $this->previews->stop($task);
            DB::transaction(function () use ($task, $settings): void {
                $task->update(['status' => 'queued', 'error' => null, 'recommendation' => null, 'review_data' => null, 'revision' => null, 'base_revision' => null, 'branch' => null, 'worktree_path' => null, 'artifact_path' => null]);
                $run = $task->runs()->create(['role' => 'engineering', 'status' => 'queued', 'settings' => $settings, 'events' => []]);
                RunMissionTask::dispatch($run->id)->afterCommit();
            });
        });
    }

    public function decide(MissionTask $task, string $action, ?string $feedback, ?string $revision): void
    {
        Cache::lock('mission-workflow', 120)->block(5, function () use ($task, $action, $feedback, $revision): void {
            $task->refresh();
            if (in_array($task->status, ['queued', 'running', 'merged', 'rejected'], true)) {
                $this->reject('This task cannot receive a decision in its current state.');
            }
            if ($action === 'approve') {
                if (! in_array($task->status, ['review', 'deferred'], true) || ! $revision || $revision !== $task->revision) {
                    $this->reject('Reload the current review before approving its exact revision.');
                }
                if (data_get($task->review_data, 'success') !== true || data_get($task->review_data, 'review.verdict') !== 'pass' || data_get($task->review_data, 'tests.exitCode') !== 0) {
                    $this->reject('Approval requires passing tests and an independent review of this revision.');
                }
                $this->git->assertReviewedRevision($task);
                $this->evidence->assertComplete($task);
                $run = $task->runs()->first();
                if (! $run) {
                    $this->reject('The original run settings are missing.');
                }
                $this->git->assertBaseRevision($task, $run->settings);
            }
            if ($action === 'request_changes' && ! in_array($task->status, ['review', 'approved', 'failed'], true)) {
                $this->reject('Request changes after a run has produced review evidence.');
            }
            $status = ['approve' => 'approved', 'request_changes' => 'changes_requested', 'defer' => 'deferred', 'reject' => 'rejected'][$action];
            if ($action === 'reject') {
                $this->previews->stop($task);
            }
            DB::transaction(function () use ($task, $action, $feedback, $status): void {
                $task->decisions()->create(['action' => $action, 'feedback' => $feedback, 'revision' => $task->revision]);
                $task->update(['status' => $status]);
            });
        });
    }

    public function merge(MissionTask $task, string $revision): void
    {
        Cache::lock('mission-workflow', 360)->block(5, function () use ($task, $revision): void {
            $task->refresh();
            if ($task->status !== 'approved' || $task->revision !== $revision) {
                $this->reject('Only the currently approved revision can be merged.');
            }
            if (MissionTask::query()->whereIn('status', ['queued', 'running'])->exists()) {
                $this->reject('Wait for the active task to finish before merging.');
            }
            $run = $task->runs()->first();
            if (! $run) {
                $this->reject('The original run settings are missing.');
            }
            $this->evidence->assertComplete($task);
            $this->previews->stop($task);
            $destination = $this->settings->all();
            if (function_exists('set_time_limit')) {
                set_time_limit(150);
            }
            $checkout = $this->git->merge($task, $run->settings, $destination);
            DB::transaction(function () use ($task, $checkout): void {
                $task->decisions()->create(['action' => 'merge', 'revision' => $task->revision, 'feedback' => 'Merged locally by explicit approval. Checkout: '.$checkout]);
                $task->update(['status' => 'merged']);
            });
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['task' => $message]);
    }
}
