<?php

namespace App\Services;

use App\Models\MissionTask;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionReleasePlan
{
    public function __construct(private GitRepository $git) {}

    /** @param list<string> $additionalPaths
     * @return array<string, mixed>
     */
    public function create(MissionTask $task, string $repository, string $main, string $staging, string $scope, array $additionalPaths): array
    {
        $directory = config('mission.production_release_root').'/task-'.$task->id.'/'.Str::uuid();
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->reject('Could not create a private release plan directory.');
        }
        $index = $directory.'/index';
        sort($additionalPaths);
        $additionalPaths = array_values(array_unique($additionalPaths));
        if ($scope === 'staging' && $additionalPaths !== []) {
            $this->reject('Full staging snapshots do not accept additional paths.');
        }
        try {
            if ($scope === 'staging') {
                $tree = $this->git->command($repository, ['rev-parse', $staging.'^{tree}']);
            } else {
                $this->git->rawCommand($repository, ['read-tree', $main], indexFile: $index);
                $patch = $this->git->rawCommand($repository, ['diff', '--binary', '--full-index', '--no-ext-diff', '--no-textconv', '--no-renames', $task->base_revision, $task->revision, '--']);
                if ($patch === '') {
                    $this->reject('The reviewed task has no source changes to release.');
                }
                $this->git->rawCommand($repository, ['apply', '--cached', '--whitespace=nowarn', '-'], $patch, $index);
                $taskPaths = array_column($this->changes($repository, $task->base_revision, $task->revision), 'path');
                $stagingPaths = array_column($this->changes($repository, $main, $staging), 'path');
                foreach ($additionalPaths as $path) {
                    if (! self::isPath($path) || in_array($path, $taskPaths, true) || ! in_array($path, $stagingPaths, true)) {
                        $this->reject('Each prerequisite must be a separate changed path from the pinned staging snapshot.');
                    }
                }
                if ($additionalPaths !== []) {
                    $patch = $this->git->rawCommand($repository, ['--literal-pathspecs', 'diff', '--binary', '--full-index', '--no-ext-diff', '--no-textconv', '--no-renames', $main, $staging, '--', ...$additionalPaths]);
                    $this->git->rawCommand($repository, ['apply', '--cached', '--whitespace=nowarn', '-'], $patch, $index);
                }
                $tree = trim($this->git->rawCommand($repository, ['write-tree'], indexFile: $index));
            }
        } finally {
            if (is_file($index)) {
                unlink($index);
            }
        }
        $changes = $this->changes($repository, $main, $tree);
        if ($changes === []) {
            $this->reject('This scope already matches production. No release PR is needed.');
        }
        $source = ['version' => 1, 'repository' => config('mission.staging_repository'), 'task_id' => $task->id, 'task_revision' => $task->revision, 'task_base_revision' => $task->base_revision, 'main_revision' => $main, 'staging_revision' => $staging, 'tree_revision' => $tree, 'scope' => $scope, 'additional_paths' => $additionalPaths, 'changed_paths' => $changes];
        $snapshot = hash('sha256', json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $branch = 'mission/release-'.$task->id.'-'.substr($snapshot, 0, 16);
        $existing = $this->git->command($repository, ['for-each-ref', '--format=%(objectname)', 'refs/heads/'.$branch]);
        if ($existing !== '') {
            if ($this->git->command($repository, ['show', '-s', '--format=%P', $existing]) !== $main || $this->git->command($repository, ['rev-parse', $existing.'^{tree}']) !== $tree) {
                $this->reject('An existing release branch does not match this plan. Nothing was overwritten.');
            }
            $release = $existing;
        } else {
            $message = 'Release '.$task->title."\n\nRelease-Snapshot: ".$snapshot."\nStaging-Commit: ".$staging."\nProduction-Base: ".$main."\n";
            $release = trim($this->git->rawCommand($repository, ['-c', 'user.name=Mission Control', '-c', 'user.email=mission-control@localhost', 'commit-tree', $tree, '-p', $main], $message));
            $this->git->command($repository, ['update-ref', 'refs/heads/'.$branch, $release, str_repeat('0', strlen($release))]);
        }
        $diff = $this->git->rawCommand($repository, ['diff', '--binary', '--full-index', '--no-ext-diff', '--no-textconv', '--no-renames', $main, $release, '--']);
        file_put_contents($directory.'/diff.patch', $diff);
        chmod($directory.'/diff.patch', 0600);
        $plan = [...$source, 'release_revision' => $release, 'branch' => $branch, 'diff_sha256' => hash('sha256', $diff)];
        $plan['hash'] = $this->hash($plan);
        $plan['artifact_path'] = $directory;
        $plan['created_at'] = now()->toISOString();
        file_put_contents($directory.'/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        chmod($directory.'/plan.json', 0600);

        return $plan;
    }

    /** @param array<string, mixed> $plan */
    public function validate(MissionTask $task, string $repository, array $plan, string $hash): void
    {
        if (! hash_equals($plan['hash'] ?? '', $hash) || ! hash_equals($hash, $this->hash($plan)) || ($plan['task_id'] ?? null) !== $task->id || ($plan['task_revision'] ?? null) !== $task->revision || ($plan['repository'] ?? null) !== config('mission.staging_repository')) {
            $this->reject('The release plan changed. Reload and review its complete scope before continuing.');
        }
        $release = $plan['release_revision'];
        if ($this->git->command($repository, ['rev-parse', 'refs/heads/'.$plan['branch']]) !== $release || $this->git->command($repository, ['show', '-s', '--format=%P', $release]) !== $plan['main_revision'] || $this->git->command($repository, ['rev-parse', $release.'^{tree}']) !== $plan['tree_revision'] || $this->changes($repository, $plan['main_revision'], $release) !== $plan['changed_paths']) {
            $this->reject('The release commit or complete diff no longer matches the approved plan.');
        }
        $diff = $this->git->rawCommand($repository, ['diff', '--binary', '--full-index', '--no-ext-diff', '--no-textconv', '--no-renames', $plan['main_revision'], $release, '--']);
        if (! hash_equals($plan['diff_sha256'], hash('sha256', $diff))) {
            $this->reject('The release diff no longer matches the pinned plan.');
        }
    }

    /** @return list<array{status: string, path: string}> */
    private function changes(string $repository, string $before, string $after): array
    {
        $output = $this->git->rawCommand($repository, ['diff', '--no-ext-diff', '--no-renames', '--name-status', '-z', $before, $after, '--']);
        $parts = explode("\0", rtrim($output, "\0"));
        if ($output === '') {
            return [];
        }
        if (count($parts) % 2 !== 0) {
            $this->reject('Could not read the complete release file list.');
        }
        $changes = [];
        for ($index = 0; $index < count($parts); $index += 2) {
            if (! self::isPath($parts[$index + 1])) {
                $this->reject('A release path cannot be safely represented in this plan.');
            }
            $changes[] = ['status' => $parts[$index], 'path' => $parts[$index + 1]];
        }

        return $changes;
    }

    public static function isPath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\') && ! preg_match('/[\x00-\x1f\x7f]/', $path) && ! array_intersect(explode('/', $path), ['', '.', '..']) && ! str_starts_with($path, ':');
    }

    /** @param array<string, mixed> $plan */
    private function hash(array $plan): string
    {
        unset($plan['hash'], $plan['artifact_path'], $plan['created_at'], $plan['diff_url']);

        return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['production' => $message]);
    }
}
