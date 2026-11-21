<?php

namespace App\Jobs;

use App\Models\Goal;
use App\Services\ChiefOfStaff;
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

class PlanGoal implements ShouldQueue
{
    use Queueable;

    private const UNUSABLE_PLAN = 'The Chief of Staff returned a plan that could not be used. Give the goal again.';

    /**
     * Unlimited attempts: a goal waiting for a free slot is released back to the queue until retryUntil().
     */
    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 810;

    public bool $failOnTimeout = true;

    public int $dispatchedAt;

    public function __construct(public int $goalId)
    {
        $this->onQueue('missions');
        $this->dispatchedAt = now()->getTimestamp();
    }

    public function retryUntil(): DateTime
    {
        return now()->setTimestamp($this->dispatchedAt)->addHours(6);
    }

    public function handle(CodexProcess $worker, ChiefOfStaff $chief): void
    {
        $goal = Goal::query()->find($this->goalId);
        if (! $goal || $goal->status !== 'queued') {
            return;
        }
        $lock = app(RunSlots::class)->acquire($this->timeout + 30);
        if (! $lock) {
            $this->release(15);

            return;
        }
        try {
            $claimed = Goal::query()->whereKey($goal->id)->where('status', 'queued')->update(['status' => 'planning', 'started_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            if (app(TokenBudget::class)->exhausted()) {
                throw new RuntimeException('The daily token limit was reached before planning could start. Give the goal again tomorrow or raise the limit in Settings.');
            }
            if (! config('mission.openai_api_key')) {
                throw new RuntimeException('The queue worker has no API key. Restart it after configuring the key.');
            }
            $goal->refresh();
            $events = [];
            $result = $worker->plan($goal, function (array $event) use ($goal, &$events): void {
                $events[] = $event;
                $attributes = ['events' => array_slice($events, -500)];
                if (($event['type'] ?? null) === 'usage' && is_array($event['usage'] ?? null)) {
                    $attributes['usage'] = $event['usage'];
                }
                $goal->update($attributes);
            });
            $goal->update(['usage' => $result['usage'] ?? $goal->usage]);
            if (($result['success'] ?? false) !== true) {
                throw new RuntimeException(is_string($result['error'] ?? null) && $result['error'] !== '' ? $result['error'] : 'The Chief of Staff could not plan this goal.');
            }
            try {
                $chief->createTasks($goal, $result);
            } catch (InvalidArgumentException) {
                throw new RuntimeException(self::UNUSABLE_PLAN);
            }
            try {
                $chief->dispatch();
            } catch (Throwable $exception) {
                report($exception);
            }
        } catch (Throwable $exception) {
            $this->failed(new RuntimeException(mb_substr($worker->redact($exception->getMessage()), 0, 4000)));
        } finally {
            $lock->release();
        }
    }

    /**
     * Fail the goal, except a never-claimed goal whose retry window ran out while waiting for a slot: that one is queued again.
     */
    public function failed(?Throwable $exception): void
    {
        $message = mb_substr(app(CodexProcess::class)->redact($exception?->getMessage() ?? 'The worker was interrupted.'), 0, 4000);
        DB::transaction(function () use ($message, $exception): void {
            $goal = Goal::query()->lockForUpdate()->find($this->goalId);
            if (! $goal || ! in_array($goal->status, ['queued', 'planning'], true)) {
                return;
            }
            if ($exception instanceof MaxAttemptsExceededException && $goal->status === 'queued') {
                self::dispatch($goal->id)->afterCommit();

                return;
            }
            $goal->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
        });
    }
}
