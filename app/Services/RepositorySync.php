<?php

namespace App\Services;

use App\Models\MissionTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class RepositorySync
{
    public function __construct(private MissionSettings $settings, private GitRepository $git) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $settings = $this->settings->all();
        $status = [
            'repository_path' => $settings['repository_path'], 'base_branch' => $settings['base_branch'],
            'current_branch' => null, 'local_revision' => null, 'upstream' => null, 'remote_revision' => null,
            'ahead' => null, 'behind' => null, 'dirty_count' => 0, 'last_checked_at' => null,
            'state' => 'unavailable', 'can_sync' => false, 'blockers' => [],
        ];
        try {
            $repository = $this->repository($settings);
            $status['repository_path'] = $repository;
            $status['current_branch'] = $this->git->command($repository, ['branch', '--show-current']) ?: null;
            $status['local_revision'] = $this->git->command($repository, ['rev-parse', '--verify', 'HEAD']);
            $changes = $this->git->command($repository, ['status', '--porcelain=v1', '--untracked-files=all']);
            $status['dirty_count'] = $changes === '' ? 0 : count(explode("\n", $changes));
            $upstream = $this->git->upstream($repository, $settings['base_branch']);
            if ($upstream) {
                $status['upstream'] = $upstream['name'];
                $remote = $this->git->remoteSnapshot($repository, $settings['base_branch']);
                $status['remote_revision'] = $remote['revision'] ?? null;
                if ($remote) {
                    $counts = preg_split('/\s+/', $this->git->command($repository, ['rev-list', '--left-right', '--count', $status['local_revision'].'...'.$remote['revision']]));
                    $status['ahead'] = (int) $counts[0];
                    $status['behind'] = (int) $counts[1];
                }
                $checked = Cache::get($this->cacheKey($repository, $settings['base_branch']));
                if (is_array($checked) && ($checked['remote'] ?? null) === $remote) {
                    $status['last_checked_at'] = $checked['at'];
                }
            }
            if ($status['current_branch'] !== $settings['base_branch']) {
                $status['blockers'][] = 'Open the configured base branch before syncing. Mission Control will not switch branches.';
            }
            if ($status['dirty_count'] > 0) {
                $status['blockers'][] = 'Commit or move your uncommitted changes before syncing. Mission Control will not stash or discard them.';
            }
            if (! $upstream) {
                $status['blockers'][] = 'Configure a remote upstream for this base branch in Git.';
            } elseif (! $status['last_checked_at']) {
                $status['blockers'][] = 'Check the remote before syncing.';
            }
            if ($status['ahead'] > 0) {
                $status['blockers'][] = $status['behind'] > 0 ? 'Local and remote history diverged. Resolve this in Git before syncing.' : 'Local commits are ahead of the remote. This button only pulls; it does not push.';
            }
            if (MissionTask::query()->whereIn('status', ['queued', 'running'])->exists()) {
                $status['blockers'][] = 'Wait for the active task to finish before syncing.';
            }
            $status['state'] = match (true) {
                $status['current_branch'] !== $settings['base_branch'] => 'wrong_branch',
                $status['dirty_count'] > 0 => 'dirty',
                ! $status['last_checked_at'] => 'unchecked',
                $status['ahead'] > 0 && $status['behind'] > 0 => 'diverged',
                $status['ahead'] > 0 => 'ahead',
                $status['behind'] > 0 => 'behind',
                default => 'up_to_date',
            };
            $status['can_sync'] = $status['blockers'] === [] && $status['behind'] > 0;
        } catch (ValidationException $exception) {
            $status['blockers'] = array_values(collect($exception->errors())->flatten()->all());
        }

        return $status;
    }

    /** @return array<string, mixed> */
    public function check(): array
    {
        $this->reserveTime();

        return Cache::lock('mission-workflow', 120)->block(5, function (): array {
            $settings = $this->settings->all();
            $repository = $this->repository($settings);
            $this->fetch($repository, $settings['base_branch']);

            return $this->status();
        });
    }

    /** @param array{expected_repository: string, expected_base: string, expected_head: string, expected_remote: string} $expected
     * @return array<string, mixed>
     */
    public function sync(array $expected): array
    {
        $this->reserveTime();

        return Cache::lock('mission-workflow', 150)->block(5, function () use ($expected): array {
            $before = $this->status();
            $this->assertExpected($before, $expected);
            if (! $before['can_sync']) {
                $this->reject(implode(' ', $before['blockers']) ?: 'The local branch is already up to date.');
            }
            $remote = $this->fetch($before['repository_path'], $before['base_branch']);
            if ($remote['revision'] !== $expected['expected_remote']) {
                $this->reject('The remote changed since the displayed check. Review the updated status before syncing.');
            }
            $current = $this->status();
            $this->assertExpected($current, $expected);
            if (! $current['can_sync']) {
                $this->reject(implode(' ', $current['blockers']) ?: 'The repository is no longer ready to sync.');
            }
            try {
                $this->git->command($current['repository_path'], ['merge', '--ff-only', '--no-edit', '--no-overwrite-ignore', $expected['expected_remote']]);
            } catch (ValidationException $exception) {
                $this->reject(collect($exception->errors())->flatten()->first());
            }

            return $this->status();
        });
    }

    /** @param array<string, mixed> $settings */
    private function repository(array $settings): string
    {
        $repository = is_string($settings['repository_path']) ? realpath($settings['repository_path']) : false;
        if (! $repository || ! is_dir($repository) || ! $this->git->isValidBranch($settings['base_branch']) || realpath($this->git->command($repository, ['rev-parse', '--show-toplevel'])) !== $repository) {
            $this->reject('Choose an existing Git repository root and base branch in Settings.');
        }
        $this->git->command($repository, ['rev-parse', '--verify', 'refs/heads/'.$settings['base_branch']]);

        return $repository;
    }

    /** @return array{ref: string, revision: string} */
    private function fetch(string $repository, string $branch): array
    {
        $remote = $this->git->fetchUpstream($repository, $branch);
        Cache::put($this->cacheKey($repository, $branch), ['remote' => $remote, 'at' => now()->toISOString()], now()->addDays(30));

        return $remote;
    }

    /** @param array<string, mixed> $status
     * @param  array<string, string>  $expected
     */
    private function assertExpected(array $status, array $expected): void
    {
        if ($status['repository_path'] !== $expected['expected_repository'] || $status['base_branch'] !== $expected['expected_base']) {
            $this->reject('The configured repository or base branch changed. Reload its status before syncing.');
        }
        if ($status['local_revision'] !== $expected['expected_head']) {
            $this->reject('The local revision changed. Reload its status before syncing.');
        }
        if ($status['remote_revision'] !== $expected['expected_remote']) {
            $this->reject('The remote revision changed. Check and review its status before syncing.');
        }
    }

    private function cacheKey(string $repository, string $branch): string
    {
        return 'repository-check:'.hash('sha256', $repository."\0".$branch);
    }

    private function reserveTime(): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(120);
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['repository' => $message]);
    }
}
