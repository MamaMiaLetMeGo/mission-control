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
    private const RECHECKING = 'The base moved, so this change is being re-checked against it. Review the refreshed revision when it is ready.';

    private const REFRESH_TOKEN_LIMIT = 'The base moved and the daily token limit is reached. Refresh this task tomorrow or raise the limit.';

    private const REFRESHABLE = ['review', 'approved', 'deferred'];

    public function __construct(private MissionSettings $settings, private GitRepository $git, private MissionPreview $previews, private BrowserEvidence $evidence, private TokenBudget $tokens) {}

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
            $settings = $this->settings->all();
            if ($this->runsCountedToday() >= $settings['daily_run_limit']) {
                $this->reject('The daily run limit has been reached. Increase it in Settings or wait until tomorrow.');
            }
            if ($this->tokens->exhausted()) {
                $this->reject('The daily token limit of '.$this->tokens->limit().' has been reached. Raise it in Settings or wait until tomorrow.');
            }
            $this->startRun($task, $settings);
        });
    }

    /**
     * Replay the reviewed revision onto the current base and test and review it again. The task gets a new revision that
     * needs a new approval. Returns false, leaving the task where it is with an explanation, when the token limit is reached.
     */
    public function refresh(MissionTask $task): bool
    {
        return Cache::lock('mission-workflow', 120)->block(5, fn (): bool => $this->refreshWhileLocked($task));
    }

    /**
     * The body of refresh(), for callers that already hold the `mission-workflow` lock.
     */
    private function refreshWhileLocked(MissionTask $task): bool
    {
        $task->refresh();
        if (! in_array($task->status, self::REFRESHABLE, true) || ! is_string($task->revision) || ! preg_match('/^[a-f0-9]{40,64}$/', $task->revision)) {
            $this->reject('Only reviewed or approved tasks with a revision can be refreshed.');
        }
        if ($this->tokens->exhausted()) {
            $task->update(['error' => self::REFRESH_TOKEN_LIMIT]);

            return false;
        }
        $readiness = $this->settings->readiness();
        if (! $readiness['ready']) {
            $this->reject(implode(' ', $readiness['issues']));
        }
        $this->startRun($task, [
            ...$this->settings->all(),
            'mode' => 'refresh',
            'source_revision' => $task->revision,
            'previous_summary' => $this->previousSummary($task->recommendation ?: $task->title),
        ]);

        return true;
    }

    /**
     * Reset the task to `queued` and dispatch a run with the given settings plus the repository snapshot.
     *
     * @param  array<string, mixed>  $settings
     */
    private function startRun(MissionTask $task, array $settings): void
    {
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
            $run = $task->runs()->create(['role' => $task->lead?->key ?? 'engineering', 'status' => 'queued', 'settings' => $settings, 'events' => []]);
            RunMissionTask::dispatch($run->id)->afterCommit();
        });
    }

    /**
     * Runs created today that count against `daily_run_limit`. Refresh runs do not.
     */
    private function runsCountedToday(): int
    {
        return AgentRun::query()->where('created_at', '>=', now()->startOfDay())
            ->where(fn ($query) => $query->whereNull('settings->mode')->orWhere('settings->mode', '!=', 'refresh'))
            ->count();
    }

    /**
     * The worker accepts at most 20000 UTF-16 code units without NUL bytes.
     */
    private function previousSummary(string $summary): string
    {
        $summary = mb_substr(str_replace("\0", '', $summary), 0, 20000);
        while (strlen(mb_convert_encoding($summary, 'UTF-16LE', 'UTF-8')) > 40000) {
            $summary = mb_substr($summary, 0, -1);
        }

        return $summary;
    }

    /**
     * When the local base moved under a reviewed task, start a refresh and refuse with an explanation. Returns without
     * doing anything when the refusal had another cause, such as tampering that already invalidated the task.
     *
     * @param  array<string, mixed>  $settings
     */
    private function refreshWhenBaseMoved(MissionTask $task, array $settings, bool $allowAlreadyMerged): void
    {
        $task->refresh();
        if (! in_array($task->status, self::REFRESHABLE, true) || ! $this->git->baseMoved($task, $settings, $allowAlreadyMerged)) {
            return;
        }
        $this->reject($this->refreshWhileLocked($task) ? self::RECHECKING : self::REFRESH_TOKEN_LIMIT);
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
                try {
                    $this->git->assertBaseRevision($task, $run->settings, invalidateWhenMoved: false);
                } catch (ValidationException $exception) {
                    $this->refreshWhenBaseMoved($task, $run->settings, false);
                    throw $exception;
                }
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
            try {
                $checkout = $this->git->merge($task, $run->settings, $destination, invalidateWhenBaseMoved: false);
            } catch (ValidationException $exception) {
                $this->refreshWhenBaseMoved($task, $run->settings, true);
                throw $exception;
            }
            DB::transaction(function () use ($task, $checkout): void {
                $task->decisions()->create(['action' => 'merge', 'revision' => $task->revision, 'feedback' => 'Merged locally by explicit approval. Checkout: '.$checkout]);
                $task->update(['status' => 'merged']);
            });
            $this->refreshOthersAfterMerge($task, $this->git->command($checkout, ['rev-parse', '--verify', 'refs/heads/'.$run->settings['base_branch']]));
        });
    }

    /**
     * Refresh every other reviewed or approved task that was reviewed against an older base. A refresh that cannot start
     * leaves its task where it is with the reason, so it never undoes the merge that already happened.
     */
    private function refreshOthersAfterMerge(MissionTask $merged, string $baseHead): void
    {
        $stale = MissionTask::query()->whereKeyNot($merged->id)->whereIn('status', ['review', 'approved'])->whereNotNull('revision')
            ->where(fn ($query) => $query->whereNull('base_revision')->orWhere('base_revision', '!=', $baseHead))
            ->orderBy('id')->get();
        foreach ($stale as $task) {
            try {
                $this->refreshWhileLocked($task);
            } catch (ValidationException $exception) {
                $task->update(['error' => collect($exception->errors())->flatten()->first()]);
            }
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['task' => $message]);
    }
}
