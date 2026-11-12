<?php

namespace App\Services;

use App\Models\MissionTask;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class GitRepository
{
    /** @param list<string> $arguments */
    public function command(string $directory, array $arguments, bool $network = false): string
    {
        return trim($this->execute($directory, $arguments, $network));
    }

    /** @param list<string> $arguments */
    public function rawCommand(string $directory, array $arguments, ?string $input = null, ?string $indexFile = null): string
    {
        return $this->execute($directory, $arguments, input: $input, indexFile: $indexFile);
    }

    /** @param list<string> $arguments */
    private function execute(string $directory, array $arguments, bool $network = false, ?string $input = null, ?string $indexFile = null): string
    {
        $environment = $this->environment($network);
        if ($indexFile !== null) {
            $environment['GIT_INDEX_FILE'] = $indexFile;
        }
        $process = new Process(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-c', 'protocol.ext.allow=never', '-C', $directory, ...$arguments], env: $environment, input: $input);
        $process->setTimeout($network ? 60 : 30);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw ValidationException::withMessages([$network ? 'repository' : 'task' => 'Git timed out. The operation was stopped; check the repository status before trying again.']);
        }
        if (! $process->isSuccessful()) {
            if ($network) {
                throw ValidationException::withMessages(['repository' => 'Git could not complete the remote operation. Check network access and Git credentials, then try again.']);
            }
            $error = app(CodexProcess::class)->redact(trim($process->getErrorOutput()));
            $error = preg_replace('~(https?://)[^/\s@]+@~i', '$1[redacted]@', $error);
            throw ValidationException::withMessages(['task' => 'Git could not complete the operation: '.mb_substr($error, 0, 1000)]);
        }

        return $process->getOutput();
    }

    public function isValidBranch(string $branch): bool
    {
        $process = new Process(['git', 'check-ref-format', '--branch', $branch], env: $this->environment());
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful() && ! str_starts_with($branch, '-');
    }

    /** @return array{ref: string, name: string, remote: string, remote_ref: string}|null */
    public function upstream(string $repository, string $branch): ?array
    {
        $value = $this->command($repository, ['for-each-ref', '--format=%(upstream)%09%(upstream:short)%09%(upstream:remotename)%09%(upstream:remoteref)', 'refs/heads/'.$branch]);
        $parts = explode("\t", $value);
        if (count($parts) !== 4 || ! str_starts_with($parts[0], 'refs/remotes/') || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $parts[2]) || ! str_starts_with($parts[3], 'refs/heads/')) {
            return null;
        }

        return ['ref' => $parts[0], 'name' => $parts[1], 'remote' => $parts[2], 'remote_ref' => $parts[3]];
    }

    /** @return array{ref: string, revision: string}|null */
    public function remoteSnapshot(string $repository, string $branch): ?array
    {
        $upstream = $this->upstream($repository, $branch);
        if (! $upstream) {
            return null;
        }
        try {
            return ['ref' => $upstream['ref'], 'revision' => $this->command($repository, ['rev-parse', '--verify', $upstream['ref'].'^{commit}'])];
        } catch (ValidationException) {
            return null;
        }
    }

    /** @return array{ref: string, revision: string} */
    public function fetchUpstream(string $repository, string $branch): array
    {
        $upstream = $this->upstream($repository, $branch);
        if (! $upstream) {
            throw ValidationException::withMessages(['repository' => 'The configured base branch needs a remote upstream. Set its tracking branch in Git first.']);
        }
        $this->command($repository, ['fetch', '--no-tags', '--no-recurse-submodules', '--no-write-fetch-head', '--no-auto-maintenance', '--', $upstream['remote'], '+'.$upstream['remote_ref'].':'.$upstream['ref']], true);
        if ($this->upstream($repository, $branch) !== $upstream) {
            throw ValidationException::withMessages(['repository' => 'The upstream configuration changed during the fetch. Check the repository again before continuing.']);
        }

        return ['ref' => $upstream['ref'], 'revision' => $this->command($repository, ['rev-parse', '--verify', $upstream['ref'].'^{commit}'])];
    }

    /** @param array<string, mixed> $settings */
    public function assertBaseRevision(MissionTask $task, array $settings, bool $allowAlreadyMerged = false): void
    {
        $repository = $settings['repository_path'];
        $base = $this->command($repository, ['rev-parse', '--verify', 'refs/heads/'.$settings['base_branch']]);
        if ($base !== $task->base_revision && (! $allowAlreadyMerged || $base !== $task->revision)) {
            $this->invalidate($task, 'The base branch changed after this task started. Run the task again against the new base before approval or merging.');
        }
        $previous = $settings['remote_snapshot'] ?? null;
        if (is_array($previous)) {
            $current = $this->remoteSnapshot($repository, $settings['base_branch']);
            $alreadyMerged = $allowAlreadyMerged && $base === $task->revision && ($current['ref'] ?? null) === ($previous['ref'] ?? null) && ($current['revision'] ?? null) === $task->revision;
            if ($current !== $previous && ! $alreadyMerged) {
                $this->invalidate($task, 'The upstream changed after this task started. Sync the base and run the task again before approval or merging.');
            }
        }
    }

    public function assertReviewedRevision(MissionTask $task): void
    {
        $root = realpath(config('mission.worktree_root'));
        $path = $task->worktree_path ? realpath($task->worktree_path) : false;
        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            $this->invalidate($task, 'The reviewed worktree is missing. Run the task again before approval.');
        }
        if (! $task->revision || ! preg_match('/^[a-f0-9]{40,64}$/', $task->revision)) {
            $this->invalidate($task, 'There is no stable revision to approve.');
        }
        if ($this->command($path, ['rev-parse', 'HEAD']) !== $task->revision || $this->command($path, ['status', '--porcelain']) !== '') {
            $this->invalidate($task, 'The worktree changed after review. The previous approval is invalid; run a fresh review.');
        }
        if (! $task->branch || $this->command($path, ['symbolic-ref', '--short', 'HEAD']) !== $task->branch) {
            $this->invalidate($task, 'The reviewed branch changed. Run the task again before approval.');
        }
    }

    /** @param array<string, mixed> $settings
     * @param  array<string, mixed>|null  $destination
     */
    public function merge(MissionTask $task, array $settings, ?array $destination = null): string
    {
        $this->assertReviewedRevision($task);
        $destination ??= $settings;
        if ($destination['base_branch'] !== $settings['base_branch']) {
            throw ValidationException::withMessages(['task' => 'The configured merge branch differs from the original run. Restore the original base branch in Settings.']);
        }
        $repository = $destination['repository_path'];
        $base = $settings['base_branch'];
        if ($this->command($repository, ['symbolic-ref', '--short', 'HEAD']) !== $base) {
            throw ValidationException::withMessages(['task' => 'Open the configured base branch in the repository before merging. Mission Control will not switch your checkout.']);
        }
        if ($this->command($repository, ['status', '--porcelain']) !== '') {
            throw ValidationException::withMessages(['task' => 'The repository has uncommitted changes. Resolve them before merging.']);
        }
        $this->assertSameRepository($repository, $settings['repository_path'], $task->worktree_path);
        $this->assertBaseRevision($task, $settings, true);
        $currentRevision = $this->command($repository, ['rev-parse', 'HEAD']);
        if ($this->upstream($repository, $base)) {
            $remote = $this->fetchUpstream($repository, $base);
            if ($remote['revision'] !== $task->base_revision && ! ($currentRevision === $task->revision && $remote['revision'] === $task->revision)) {
                $this->invalidate($task, 'The remote base changed after review. Sync the base and run the task again before merging.');
            }
        }
        if ($this->command($repository, ['symbolic-ref', '--short', 'HEAD']) !== $base || $this->command($repository, ['rev-parse', 'HEAD']) !== $currentRevision || $this->command($repository, ['status', '--porcelain']) !== '') {
            throw ValidationException::withMessages(['task' => 'The merge checkout changed while checking the remote. Reload and inspect it before merging.']);
        }
        $this->assertSameRepository($repository, $settings['repository_path'], $task->worktree_path);
        $this->assertReviewedRevision($task);
        if ($currentRevision === $task->revision) {
            return $repository;
        }
        if ($currentRevision !== $task->base_revision) {
            $this->invalidate($task, 'The base branch changed after this task started. Run the task again against the new base before merging.');
        }
        $this->command($repository, ['merge', '--ff-only', '--no-edit', '--no-overwrite-ignore', $task->revision]);

        return $repository;
    }

    private function assertSameRepository(string $destination, string $original, string $worktree): void
    {
        $sourceCommon = realpath($this->command($destination, ['rev-parse', '--path-format=absolute', '--git-common-dir']));
        $originalCommon = realpath($this->command($original, ['rev-parse', '--path-format=absolute', '--git-common-dir']));
        $taskCommon = realpath($this->command($worktree, ['rev-parse', '--path-format=absolute', '--git-common-dir']));
        if (! $sourceCommon || $sourceCommon !== $taskCommon || $sourceCommon !== $originalCommon) {
            throw ValidationException::withMessages(['task' => 'The merge checkout must belong to the same repository as the original run and reviewed worktree.']);
        }
    }

    private function invalidate(MissionTask $task, string $message): never
    {
        $task->update(['status' => 'changes_requested', 'error' => $message]);
        throw ValidationException::withMessages(['task' => $message]);
    }

    /** @return array<string, string|false> */
    public function networkEnvironment(): array
    {
        return $this->environment(true);
    }

    public function effectivePushDestination(string $repository, string $url): string
    {
        return $this->command($repository, ['-c', 'remote.origin.url=', '-c', 'remote.origin.url='.$url, '-c', 'remote.origin.pushurl=', 'remote', 'get-url', '--push', '--all', 'origin'], true);
    }

    /** @return array<string, string|false> */
    private function environment(bool $network = false): array
    {
        $environment = array_fill_keys(array_unique([...array_keys($_SERVER), ...array_keys($_ENV), ...array_keys(getenv())]), false);
        $names = ['PATH', 'TMPDIR', 'LANG', 'LC_ALL', 'SYSTEMROOT'];
        if ($network) {
            $names = [...$names, 'HOME', 'USERPROFILE', 'USER', 'LOGNAME', 'XDG_CONFIG_HOME', 'SSH_AUTH_SOCK'];
        }
        foreach ($names as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }
        if ($network) {
            $environment['GIT_TERMINAL_PROMPT'] = '0';
            $environment['GCM_INTERACTIVE'] = 'never';
            $environment['GIT_ASKPASS'] = '/usr/bin/false';
            $environment['SSH_ASKPASS'] = '/usr/bin/false';
            $environment['GIT_SSH_COMMAND'] = 'ssh -o BatchMode=yes';
        } else {
            $environment['GIT_CONFIG_NOSYSTEM'] = '1';
            $environment['GIT_CONFIG_GLOBAL'] = '/dev/null';
        }

        return $environment;
    }
}
