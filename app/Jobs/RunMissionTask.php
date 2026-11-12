<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Services\CodexProcess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RunMissionTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 810;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId)
    {
        $this->onQueue('missions');
    }

    public function handle(CodexProcess $worker): void
    {
        $run = AgentRun::query()->with('task')->find($this->runId);
        if (! $run || $run->status !== 'queued' || $run->task->status !== 'queued') {
            return;
        }
        $lock = Cache::lock('mission-execution', $this->timeout + 30);
        if (! $lock->get()) {
            return;
        }
        try {
            $claimed = DB::transaction(function () use ($run): bool {
                $claimed = AgentRun::query()->whereKey($run->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()]);
                if ($claimed) {
                    $run->task->update(['status' => 'running']);
                }

                return $claimed === 1;
            });
            if (! $claimed) {
                return;
            }
            if (! config('mission.openai_api_key')) {
                throw new RuntimeException('The queue worker has no API key. Restart it after configuring the key.');
            }
            $run->refresh();
            $result = $worker->run($run, function (array $event) use ($run): void {
                $events = $run->events ?? [];
                $events[] = $event;
                $attributes = ['events' => array_slice($events, -500)];
                if (($event['type'] ?? null) === 'thread' && is_string($event['threadId'] ?? null) && ($event['role'] ?? '') === 'implementation') {
                    $attributes['thread_id'] = mb_substr($event['threadId'], 0, 255);
                }
                if (($event['type'] ?? null) === 'usage' && is_array($event['usage'] ?? null)) {
                    $attributes['usage'] = $event['usage'];
                }
                $run->update($attributes);
            });
            $successful = ($result['success'] ?? false) === true && data_get($result, 'tests.exitCode') === 0 && data_get($result, 'review.verdict') === 'pass';
            DB::transaction(function () use ($run, $result, $successful): void {
                $currentRun = AgentRun::query()->lockForUpdate()->find($run->id);
                $task = $run->task()->lockForUpdate()->first();
                if (! $currentRun || $currentRun->status !== 'running' || $task->status !== 'running' || $task->runs()->value('id') !== $run->id) {
                    return;
                }
                $run->update(['result' => $result, 'status' => $successful ? 'completed' : 'failed', 'usage' => $result['usage'] ?? $run->usage, 'finished_at' => now(), 'error' => $successful ? null : ($result['error'] ?? 'Verification did not pass. Review the evidence before retrying.')]);
                $run->task->update([
                    'status' => $successful ? 'review' : 'failed',
                    'recommendation' => $result['summary'] ?? null,
                    'review_data' => $result,
                    'branch' => $result['branch'] ?? null,
                    'worktree_path' => $result['worktreePath'] ?? null,
                    'revision' => $result['revision'] ?? null,
                    'base_revision' => $result['baseRevision'] ?? null,
                    'artifact_path' => $result['artifactPath'] ?? null,
                    'error' => $run->error,
                ]);
            });
        } catch (Throwable $exception) {
            $message = $worker->redact($exception->getMessage());
            $this->failed(new RuntimeException(mb_substr($message, 0, 4000)));
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = app(CodexProcess::class)->redact($exception?->getMessage() ?? 'The worker was interrupted.');
        DB::transaction(function () use ($message): void {
            $run = AgentRun::query()->lockForUpdate()->find($this->runId);
            if (! $run || ! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }
            $run->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            if ($run->task->runs()->value('id') === $run->id) {
                $run->task->update(['status' => 'failed', 'error' => $message]);
            }
        });
    }
}
