<?php

namespace App\Jobs;

use App\Models\BrainRun;
use App\Services\Brain;
use App\Services\CodexProcess;
use App\Services\RunSlots;
use App\Services\TokenBudget;
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class LearnPreferences implements ShouldQueue
{
    use Queueable;

    private const UNUSABLE_CHANGES = 'The Brain returned changes that could not be used. Nothing was changed.';

    /**
     * Unlimited attempts: a run waiting for a free slot is released back to the queue until retryUntil().
     */
    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 810;

    public bool $failOnTimeout = true;

    public int $dispatchedAt;

    public function __construct(public int $runId)
    {
        $this->onQueue('missions');
        $this->dispatchedAt = now()->getTimestamp();
    }

    public function retryUntil(): DateTime
    {
        return now()->setTimestamp($this->dispatchedAt)->addHours(6);
    }

    public function handle(CodexProcess $worker, Brain $brain): void
    {
        $run = BrainRun::query()->find($this->runId);
        if (! $run || $run->status !== 'queued') {
            return;
        }
        $lock = app(RunSlots::class)->acquire($this->timeout + 30);
        if (! $lock) {
            $this->release(15);

            return;
        }
        try {
            $claimed = BrainRun::query()->whereKey($run->id)->where('status', 'queued')->update(['status' => 'learning', 'started_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            if (app(TokenBudget::class)->exhausted()) {
                throw new RuntimeException('The daily token limit was reached before the Brain could start. Try again tomorrow or raise the limit in Settings.');
            }
            if (! config('mission.openai_api_key')) {
                throw new RuntimeException('The queue worker has no API key. Restart it after configuring the key.');
            }
            $run->refresh();
            $events = [];
            $result = $worker->learn($run, function (array $event) use ($run, &$events): void {
                $events[] = $event;
                $attributes = ['events' => array_slice($events, -500)];
                if (($event['type'] ?? null) === 'usage' && is_array($event['usage'] ?? null)) {
                    $attributes['usage'] = $event['usage'];
                }
                $run->update($attributes);
            });
            $run->update(['usage' => $result['usage'] ?? $run->usage]);
            if (($result['success'] ?? false) !== true) {
                throw new RuntimeException(is_string($result['error'] ?? null) && $result['error'] !== '' ? $result['error'] : 'The Brain could not learn from these decisions.');
            }
            try {
                $brain->apply($run, $result);
            } catch (InvalidArgumentException) {
                throw new RuntimeException(self::UNUSABLE_CHANGES);
            }
        } catch (Throwable $exception) {
            $this->failed(new RuntimeException(mb_substr($worker->redact($exception->getMessage()), 0, 4000)));
        } finally {
            $lock->release();
        }
    }

    /**
     * Fail the run, except a never-claimed run whose retry window ran out while waiting for a slot: that one is queued again.
     */
    public function failed(?Throwable $exception): void
    {
        $message = mb_substr(app(CodexProcess::class)->redact($exception?->getMessage() ?? 'The worker was interrupted.'), 0, 4000);
        DB::transaction(function () use ($message, $exception): void {
            $run = BrainRun::query()->lockForUpdate()->find($this->runId);
            if (! $run || ! in_array($run->status, ['queued', 'learning'], true)) {
                return;
            }
            if ($exception instanceof MaxAttemptsExceededException && $run->status === 'queued') {
                self::dispatch($run->id)->afterCommit();

                return;
            }
            $run->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
        });
    }
}
