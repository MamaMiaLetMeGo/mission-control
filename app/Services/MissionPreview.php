<?php

namespace App\Services;

use App\Models\MissionTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

class MissionPreview
{
    public const VIEWABLE_STATUSES = ['review', 'approved', 'deferred', 'failed'];

    public function __construct(private MissionSettings $settings, private GitRepository $git, private PreviewProcess $process) {}

    /** @return array{id: string, url: string, revision: string, expiresAt: string} */
    public function start(MissionTask $task, string $revision): array
    {
        return Cache::lock('mission-workflow', 300)->block(5, function () use ($task, $revision): array {
            $task->refresh();
            if (! in_array($task->status, self::VIEWABLE_STATUSES, true) || ! $task->revision || $revision !== $task->revision) {
                throw ValidationException::withMessages(['preview' => 'Reload the current reviewed revision before starting its preview.']);
            }
            $run = $task->runs()->first();
            $configuration = $this->settings->previewConfiguration($run?->settings ?? []);
            if (! $configuration['enabled']) {
                throw ValidationException::withMessages(['preview' => 'Previews were not enabled for this run. Enable them in Settings and start a new run.']);
            }
            $this->git->assertReviewedRevision($task);
            $existing = $this->process->execute('status', ['taskId' => $task->id, 'previewRoot' => config('mission.preview_root'), 'revision' => $task->revision]);
            $preview = $this->publicHandle($existing['preview'], $task->revision);
            if ($preview) {
                return $preview;
            }
            $result = $this->process->execute('start', [
                'taskId' => $task->id,
                'revision' => $task->revision,
                'worktreePath' => $task->worktree_path,
                'repositoryPath' => $run->settings['repository_path'],
                'artifactPath' => $task->artifact_path,
                'previewRoot' => config('mission.preview_root'),
                'phase' => 'after',
                'config' => $configuration,
                'database' => config('mission.preview_database'),
            ]);
            $preview = $this->publicHandle($result['preview'], $task->revision);
            if (! $preview) {
                throw ValidationException::withMessages(['preview' => 'The preview service did not return a live preview for this revision.']);
            }

            return $preview;
        });
    }

    /** @return array{id: string, url: string, revision: string, expiresAt: string}|null */
    public function status(MissionTask $task): ?array
    {
        if (! in_array($task->status, self::VIEWABLE_STATUSES, true) || ! $task->revision || ! $this->enabledFor($task)) {
            return null;
        }
        $result = $this->process->execute('status', ['taskId' => $task->id, 'previewRoot' => config('mission.preview_root'), 'revision' => $task->revision]);
        $preview = $this->publicHandle($result['preview'], $task->revision);
        if (! $preview) {
            return null;
        }
        if (! $task->worktree_path || $this->git->command($task->worktree_path, ['rev-parse', 'HEAD']) !== $task->revision || $this->git->command($task->worktree_path, ['status', '--porcelain']) !== '') {
            return null;
        }

        return $preview;
    }

    public function stop(MissionTask $task): void
    {
        if (! $this->enabledFor($task) && ! data_get($task->review_data, 'preview.id')) {
            return;
        }
        $result = $this->process->execute('stop', ['taskId' => $task->id, 'previewRoot' => config('mission.preview_root')]);
        if ($result['preview'] !== null) {
            throw ValidationException::withMessages(['preview' => 'The preview is still running. Stop it before continuing.']);
        }
    }

    private function enabledFor(MissionTask $task): bool
    {
        $run = $task->relationLoaded('runs') ? $task->runs->first() : $task->runs()->first();

        return (bool) ($run?->settings['preview_enabled'] ?? false);
    }

    /** @return array{id: string, url: string, revision: string, expiresAt: string}|null */
    private function publicHandle(mixed $value, string $revision): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! is_string($value['id'] ?? null) || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value['id']) || ! is_string($value['url'] ?? null) || ! preg_match('~^http://(?:localhost|127\.0\.0\.1):([0-9]{1,5})$~', $value['url'], $matches) || (int) $matches[1] < 1 || (int) $matches[1] > 65535 || ! is_string($value['revision'] ?? null) || ! is_string($value['expiresAt'] ?? null)) {
            throw ValidationException::withMessages(['preview' => 'The preview service returned an invalid local preview address.']);
        }
        if ($value['revision'] !== $revision) {
            return null;
        }
        try {
            $expiresAt = CarbonImmutable::parse($value['expiresAt']);
        } catch (Throwable) {
            throw ValidationException::withMessages(['preview' => 'The preview service returned an invalid expiry time.']);
        }
        if ($expiresAt->isPast()) {
            return null;
        }

        return ['id' => $value['id'], 'url' => $value['url'], 'revision' => $revision, 'expiresAt' => $expiresAt->toISOString()];
    }
}
