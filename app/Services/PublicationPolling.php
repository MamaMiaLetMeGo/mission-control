<?php

namespace App\Services;

use App\Models\MissionTask;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class PublicationPolling
{
    public function __construct(private StagingPublication $staging, private ProductionPublication $production) {}

    /** @return array<string, array<string, mixed>> */
    public function metadata(MissionTask $task): array
    {
        return [
            'staging' => $this->schedule($task->publication ?? []),
            'production' => $this->schedule($task->production_publication ?? []),
        ];
    }

    /** @return array{busy: bool} */
    public function refresh(MissionTask $task, string $environment, string $revision, ?string $planHash): array
    {
        $this->assertIdentity($task, $revision, $planHash);
        $lock = Cache::lock('mission-workflow', 900);
        if (! $lock->get()) {
            return ['busy' => true];
        }

        try {
            $task->refresh();
            $this->assertIdentity($task, $revision, $planHash);
            $attribute = $environment === 'staging' ? 'publication' : 'production_publication';
            $publication = $task->{$attribute} ?? [];
            if (! $this->schedule($publication)['published']) {
                return ['busy' => false];
            }
            $key = 'publication-poll:'.$task->id.':'.$environment.':'.hash('sha256', $revision.'|'.$planHash.'|'.$publication['pr_number']);
            if (Cache::has($key)) {
                return ['busy' => false];
            }
            Cache::put($key, true, 15);
            if (function_exists('set_time_limit')) {
                set_time_limit(360);
            }
            try {
                if ($environment === 'staging') {
                    $this->staging->refreshForPolling($task, $revision, $lock);
                } else {
                    $this->production->refreshForPolling($task, $revision, (string) $planHash, $lock);
                }
            } catch (ValidationException $exception) {
                $task->update([$attribute => [...$task->{$attribute}, 'error' => collect($exception->errors())->flatten()->first()]]);
            }
            Cache::put($key, true, intdiv($this->schedule($task->{$attribute})['poll_after_ms'], 1000));

            return ['busy' => false];
        } finally {
            $lock->release();
        }
    }

    private function assertIdentity(MissionTask $task, string $revision, ?string $planHash): void
    {
        if ($task->revision !== $revision || data_get($task->production_publication, 'plan.hash') !== $planHash) {
            throw new HttpResponseException(response()->json([
                'message' => 'This task revision or production plan changed. Reload to review the current scope before continuing.',
                'reload' => true,
            ], 409));
        }
    }

    /** @param array<string, mixed> $publication
     * @return array<string, mixed>
     */
    private function schedule(array $publication): array
    {
        $published = is_int($publication['pr_number'] ?? null) && $publication['pr_number'] > 0;
        $error = $publication['error'] ?? null;
        $state = $publication['state'] ?? null;
        $completed = $state === 'merged'
            && is_string($publication['merge_revision'] ?? null)
            && data_get($publication, 'deployment.state') === 'success'
            && data_get($publication, 'deployment.revision') === $publication['merge_revision'];

        return [
            'published' => $published,
            'should_poll' => $published && ($error || ($state !== 'closed' && ! $completed)),
            'poll_after_ms' => $error || data_get($publication, 'deployment.state') === 'failure' ? 60000 : 15000,
            'last_checked_at' => $publication['last_checked_at'] ?? null,
            'error' => $error,
        ];
    }
}
