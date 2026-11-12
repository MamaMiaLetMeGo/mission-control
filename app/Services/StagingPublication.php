<?php

namespace App\Services;

use App\Models\MissionTask;
use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class StagingPublication
{
    private const PR_FIELDS = 'number,url,state,isDraft,headRefName,headRefOid,baseRefName,baseRefOid,isCrossRepository,mergeable,mergeStateStatus,mergeCommit';

    public function __construct(private MissionSettings $settings, private GitRepository $git, private GitHubProcess $github, private BrowserEvidence $evidence) {}

    /** @return array<string, mixed> */
    public function status(MissionTask $task): array
    {
        $publication = $task->publication ?? [];
        $status = [...[
            'state' => 'not_published', 'pr_url' => null, 'pr_number' => null,
            'head_revision' => $task->revision, 'base_branch' => 'staging', 'base_revision' => $task->base_revision,
            'merge_revision' => null, 'checks' => [], 'deployment' => ['state' => 'not_started', 'url' => null, 'revision' => null],
            'last_checked_at' => null, 'error' => null, 'blockers' => [],
        ], ...$publication];
        $run = $task->relationLoaded('runs') ? $task->runs->first() : $task->runs()->first();
        $settings = $this->settings->all();
        $currentBase = $run && $settings['base_branch'] === ($run->settings['base_branch'] ?? null);
        $active = MissionTask::query()->whereIn('status', ['queued', 'running'])->exists();
        if ($active) {
            $status['blockers'][] = 'Wait for the active task to finish before publishing or merging.';
        }
        if (! $currentBase) {
            $status['blockers'][] = 'The configured base branch differs from this task’s original run.';
        }
        $canMutate = $task->status === 'merged' && $currentBase && ! $active;
        $status['can_publish'] = $canMutate && ! $status['pr_number'] && $status['head_revision'] === $task->revision;
        $status['can_merge'] = $canMutate && $status['state'] === 'ready' && $status['head_revision'] === $task->revision && ! $status['error'];

        return $status;
    }

    /** @return array<string, mixed> */
    public function publish(MissionTask $task, string $revision): array
    {
        return $this->locked($task, $revision, function (array $context) use ($task): array {
            if (data_get($task->publication, 'pr_number')) {
                return $this->refreshState($task, $context);
            }
            $this->assertFreshBase($task, $context);
            $branch = $task->branch;
            if (! is_string($branch) || ! str_starts_with($branch, 'mission/') || ! $this->git->isValidBranch($branch)) {
                $this->reject('Publication requires the original Mission Control task branch.');
            }
            $existing = $this->git->command($context['path'], ['ls-remote', '--heads', '--', 'origin', 'refs/heads/'.$branch], true);
            if ($existing !== '' && preg_split('/\s+/', $existing)[0] !== $task->revision) {
                $this->reject('The remote task branch has a different revision. Mission Control will not overwrite it.');
            }
            $this->context($task, $task->revision);
            $task->update(['publication' => [
                'state' => 'publishing', 'repository' => $context['repository'], 'head_branch' => $branch,
                'head_revision' => $task->revision, 'base_branch' => 'staging', 'base_revision' => $task->base_revision,
                'error' => null,
            ]]);
            $this->git->command($context['path'], ['push', '--porcelain', '--no-follow-tags', '--no-mirror', '--', $context['push_url'], $task->revision.':refs/heads/'.$branch], true);
            $pulls = $this->github->json($context['repository'], ['pr', 'list', '--state', 'all', '--head', $branch, '--base', 'staging', '--limit', '20', '--json', 'number,headRefOid,state']);
            $number = null;
            foreach ($pulls as $pull) {
                if (($pull['headRefOid'] ?? null) === $task->revision && in_array($pull['state'] ?? null, ['OPEN', 'MERGED'], true)) {
                    $number = $pull['number'];
                    break;
                }
            }
            if ($number === null && $pulls !== []) {
                $this->reject('A different or closed pull request already uses this task branch. Review it in GitHub before continuing.');
            }
            if ($number === null) {
                $summary = mb_substr(data_get($task->review_data, 'review.summary') ?: $task->title, 0, 3000);
                $testCommand = json_encode(data_get($task->review_data, 'tests.command', []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $body = $summary."\n\nValidation\n- Verification command: `".$testCommand."` (exit 0).\n- Independent review passed.\n- Reviewed revision: `".$task->revision."`.\n- Review evidence is retained in Mission Control task #".$task->id.".\n\nTarget: staging.";
                $url = $this->github->command($context['repository'], ['pr', 'create', '--base', 'staging', '--head', $branch, '--title', $task->title, '--body-file', '-', '--no-maintainer-edit'], $body);
                if (! preg_match('~^https://github\.com/'.preg_quote($context['repository'], '~').'/pull/(\d+)$~i', $url, $match)) {
                    $this->reject('The PR creation response could not be verified. Refresh or retry to locate the existing PR.');
                }
                $number = (int) $match[1];
            }
            $task->update(['publication' => [...$task->publication, 'pr_number' => (int) $number, 'pr_url' => 'https://github.com/'.$context['repository'].'/pull/'.$number]]);
            $task->decisions()->firstOrCreate(['action' => 'publish', 'revision' => $task->revision], ['feedback' => 'Published reviewed revision as a staging pull request: '.$task->publication['pr_url']]);

            return $this->refreshState($task, $context);
        });
    }

    /** @return array<string, mixed> */
    public function refresh(MissionTask $task, string $revision): array
    {
        return $this->locked($task, $revision, fn (array $context): array => $this->refreshState($task, $context), readOnly: true);
    }

    /** @return array<string, mixed> */
    public function refreshForPolling(MissionTask $task, string $revision, Lock $lock): array
    {
        if (! $lock->isOwnedByCurrentProcess()) {
            $this->reject('The status refresh lost its workflow lock. Try again shortly.');
        }

        return $this->refreshState($task, $this->publicationContext($task, $revision), reconcile: false);
    }

    /** @return array<string, mixed> */
    public function merge(MissionTask $task, string $revision): array
    {
        return $this->locked($task, $revision, function (array $context) use ($task): array {
            $state = $this->refreshState($task, $context);
            if ($state['state'] === 'merged') {
                return $state;
            }
            if (! $state['can_merge']) {
                $this->reject(implode(' ', $state['blockers']) ?: 'The staging PR is not ready to merge. Refresh its checks first.');
            }
            $this->assertFreshBase($task, $context);
            $latest = $this->pullRequest($task, $context);
            if (($latest['baseRefOid'] ?? null) !== $task->base_revision || ($latest['mergeStateStatus'] ?? null) !== 'CLEAN' || ($latest['mergeable'] ?? null) !== 'MERGEABLE') {
                $this->reject('The PR base or mergeability changed. Refresh and review the latest GitHub status.');
            }
            $this->github->command($context['repository'], ['pr', 'merge', (string) $state['pr_number'], '--squash', '--match-head-commit', $task->revision]);

            return $this->refreshState($task, $context);
        });
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function locked(MissionTask $task, string $revision, Closure $operation, bool $readOnly = false): array
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(240);
        }

        try {
            return Cache::lock('mission-workflow', 300)->block(5, function () use ($task, $revision, $operation, $readOnly): array {
                $task->refresh();
                try {
                    $context = $readOnly ? $this->publicationContext($task, $revision) : $this->context($task, $revision);

                    return $operation($context);
                } catch (ValidationException $exception) {
                    if ($task->publication) {
                        $task->update(['publication' => [...$task->publication, 'error' => collect($exception->errors())->flatten()->first()]]);
                    }
                    throw $exception;
                }
            });
        } catch (LockTimeoutException) {
            $this->reject('Another Mission Control operation is updating status. Wait a moment, then try this action again.');
        }
    }

    /** @return array{repository: string} */
    private function publicationContext(MissionTask $task, string $revision): array
    {
        $publication = $task->publication;
        if ($task->status !== 'merged' || $task->revision !== $revision || ! is_array($publication) || ($publication['head_revision'] ?? null) !== $revision || ($publication['head_branch'] ?? null) !== $task->branch || ($publication['base_branch'] ?? null) !== 'staging' || ($publication['repository'] ?? null) !== config('mission.staging_repository')) {
            $this->reject('The stored staging publication does not match this task revision.');
        }

        return ['repository' => $publication['repository']];
    }

    /** @return array<string, mixed> */
    private function context(MissionTask $task, string $revision): array
    {
        if ($task->status !== 'merged' || ! $task->revision || $task->revision !== $revision) {
            $this->reject('Only the exact locally merged and reviewed task revision can be published to staging.');
        }
        if (MissionTask::query()->whereIn('status', ['queued', 'running'])->exists()) {
            $this->reject('Wait for the active task to finish before changing staging publication.');
        }
        $run = $task->runs()->first();
        if (! $run || $run->status !== 'completed' || data_get($run->result, 'revision') !== $revision || data_get($task->review_data, 'success') !== true || data_get($task->review_data, 'tests.exitCode') !== 0 || data_get($task->review_data, 'review.verdict') !== 'pass' || ! $task->decisions()->where('action', 'approve')->where('revision', $revision)->exists() || ! $task->decisions()->where('action', 'merge')->where('revision', $revision)->exists()) {
            $this->reject('The complete review, local merge, and approval evidence must match this revision.');
        }
        $this->git->assertReviewedRevision($task);
        $this->evidence->assertComplete($task);
        $settings = $this->settings->all();
        $path = realpath($settings['repository_path']);
        if (! $path || $settings['base_branch'] !== $run->settings['base_branch'] || $this->git->command($path, ['branch', '--show-current']) !== $settings['base_branch']) {
            $this->reject('Select the clean integration checkout and the original run base branch before publishing.');
        }
        $common = realpath($this->git->command($path, ['rev-parse', '--path-format=absolute', '--git-common-dir']));
        foreach ([$run->settings['repository_path'], $task->worktree_path] as $original) {
            if (! $common || realpath($this->git->command($original, ['rev-parse', '--path-format=absolute', '--git-common-dir'])) !== $common) {
                $this->reject('Publication must use the same repository as the reviewed task and its original run.');
            }
        }
        $head = $this->git->command($path, ['rev-parse', 'HEAD']);
        if (! in_array($head, array_filter([$revision, data_get($task->publication, 'merge_revision')]), true) || $this->git->command($path, ['status', '--porcelain']) !== '') {
            $this->reject('The integration checkout changed or has uncommitted files. Restore its reviewed revision before continuing.');
        }
        $url = $this->git->command($path, ['remote', 'get-url', 'origin'], true);
        if (! preg_match('~^(?:https://github\.com/|git@github\.com:)([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?$~', $url, $match) || strcasecmp($match[1], config('mission.staging_repository')) !== 0) {
            $this->reject('Staging publication is limited to the configured GitHub origin repository.');
        }
        $pushUrls = explode("\n", $this->git->command($path, ['remote', 'get-url', '--push', '--all', 'origin'], true));
        if (count($pushUrls) !== 1 || ! preg_match('~^(?:https://github\.com/|git@github\.com:)'.preg_quote($match[1], '~').'(?:\.git)?$~i', $pushUrls[0])) {
            $this->reject('Origin must have exactly one push destination in the configured GitHub repository.');
        }
        $canonical = 'https://github.com/'.$match[1].'.git';
        $effectivePush = $this->git->effectivePushDestination($path, $canonical);
        if (! preg_match('~^(?:https://github\.com/|git@github\.com:)'.preg_quote($match[1], '~').'(?:\.git)?$~i', $effectivePush)) {
            $this->reject('Git URL rewriting redirects the publication push outside the configured GitHub repository.');
        }
        $upstream = $this->git->upstream($path, $settings['base_branch']);
        if (! $upstream || $upstream['remote'] !== 'origin' || $upstream['remote_ref'] !== 'refs/heads/staging') {
            $this->reject('The integration base must track origin/staging. Production branches are not accepted.');
        }
        if ($task->publication && ((data_get($task->publication, 'repository') !== $match[1]) || data_get($task->publication, 'head_revision') !== $revision || data_get($task->publication, 'head_branch') !== $task->branch || data_get($task->publication, 'base_branch') !== 'staging')) {
            $this->reject('The stored publication does not match this reviewed task and staging repository.');
        }

        return ['path' => $path, 'repository' => $match[1], 'branch' => $settings['base_branch'], 'push_url' => $canonical];
    }

    /** @param array<string, mixed> $context */
    private function assertFreshBase(MissionTask $task, array $context): void
    {
        $remote = $this->git->fetchUpstream($context['path'], $context['branch']);
        if ($remote['revision'] !== $task->base_revision) {
            $this->reject('Remote staging changed after this task was reviewed. Run a fresh task against the current staging base before publishing or merging.');
        }
        $this->context($task, $task->revision);
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function pullRequest(MissionTask $task, array $context): array
    {
        $number = data_get($task->publication, 'pr_number');
        if (! is_int($number) || $number < 1) {
            $this->reject('Publish the reviewed branch to create a staging pull request first.');
        }
        $pull = $this->github->json($context['repository'], ['pr', 'view', (string) $number, '--json', self::PR_FIELDS]);
        if (($pull['number'] ?? null) !== $number || ($pull['headRefOid'] ?? null) !== $task->revision || ($pull['headRefName'] ?? null) !== $task->branch || ($pull['baseRefName'] ?? null) !== 'staging' || ($pull['isCrossRepository'] ?? true) !== false || ($pull['url'] ?? null) !== 'https://github.com/'.$context['repository'].'/pull/'.$number) {
            $this->reject('The pull request head, repository, or staging target changed. It cannot be merged from this review.');
        }

        return $pull;
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function refreshState(MissionTask $task, array $context, bool $reconcile = true): array
    {
        $pull = $this->pullRequest($task, $context);
        $checks = data_get($task->publication, 'checks', []);
        $blockers = [];
        $state = 'open';
        $merged = ($pull['state'] ?? null) === 'MERGED';
        $mergeRevision = $merged ? data_get($pull, 'mergeCommit.oid') : null;
        if ($merged && (! is_string($mergeRevision) || ! preg_match('/^[a-f0-9]{40,64}$/', $mergeRevision))) {
            $this->reject('GitHub has not reported a verifiable merge revision yet. Refresh again.');
        }
        if (! $merged && ($pull['state'] ?? null) === 'OPEN') {
            $checks = [];
            $reported = $this->github->json($context['repository'], ['pr', 'checks', (string) $pull['number'], '--required', '--json', 'name,bucket,link'], [0, 1, 8]);
            foreach ($reported as $check) {
                $checks[] = ['name' => (string) ($check['name'] ?? 'Required check'), 'state' => $check['bucket'] ?? 'pending', 'url' => $this->githubUrl($check['link'] ?? null)];
            }
            if ($checks === []) {
                $blockers[] = 'GitHub has not reported the required checks yet.';
            }
            foreach ($checks as $check) {
                if (! in_array($check['state'], ['pass', 'skipping'], true)) {
                    $blockers[] = $check['name'].' is '.$check['state'].'.';
                }
            }
            if (($pull['isDraft'] ?? true) || ($pull['mergeStateStatus'] ?? null) !== 'CLEAN' || ($pull['mergeable'] ?? null) !== 'MERGEABLE') {
                $blockers[] = 'GitHub must report a ready, conflict-free PR with all branch protections satisfied.';
            }
            if (($pull['baseRefOid'] ?? null) !== $task->base_revision) {
                $blockers[] = 'The staging base changed after this task was reviewed.';
            }
            $hasFailure = collect($checks)->contains(fn (array $check): bool => in_array($check['state'], ['fail', 'cancel'], true));
            $state = $blockers === [] ? 'ready' : ($hasFailure ? 'checks_failed' : 'checks_pending');
        } elseif ($merged) {
            $state = 'merged';
        } else {
            $state = 'closed';
            $blockers[] = 'The staging pull request is closed without being merged.';
        }
        $publication = [...$task->publication, 'state' => $state, 'merge_revision' => $mergeRevision, 'checks' => $checks, 'blockers' => $blockers, 'error' => null, 'last_checked_at' => now()->toISOString()];
        $task->update(['publication' => $publication]);
        if ($merged) {
            $task->decisions()->firstOrCreate(['action' => 'merge_staging', 'revision' => $task->revision], ['feedback' => 'GitHub merged the reviewed PR into staging at '.$mergeRevision.'. '.$publication['pr_url']]);
            $publication['deployment'] = $this->deployment($context['repository'], $mergeRevision);
            if ($reconcile) {
                $publication['integration_synced'] = $this->reconcile($task, $mergeRevision);
            }
            $task->update(['publication' => $publication]);
        }

        return $this->status($task);
    }

    /** @return array{state: string, url: ?string, revision: string} */
    private function deployment(string $repository, string $revision): array
    {
        $runs = $this->github->json($repository, ['run', 'list', '--workflow', config('mission.staging_deploy_workflow'), '--branch', 'staging', '--commit', $revision, '--event', 'push', '--limit', '5', '--json', 'status,conclusion,url,headSha,headBranch']);
        foreach ($runs as $run) {
            if (($run['headSha'] ?? null) !== $revision || ($run['headBranch'] ?? null) !== 'staging') {
                continue;
            }
            $state = match (true) {
                ($run['status'] ?? null) !== 'completed' => ($run['status'] ?? null) === 'in_progress' ? 'running' : 'pending',
                ($run['conclusion'] ?? null) === 'success' => 'success',
                default => 'failure',
            };

            return ['state' => $state, 'url' => $this->githubUrl($run['url'] ?? null), 'revision' => $revision];
        }

        return ['state' => 'not_started', 'url' => null, 'revision' => $revision];
    }

    private function reconcile(MissionTask $task, string $mergeRevision): bool
    {
        try {
            $settings = $this->settings->all();
            $run = $task->runs()->first();
            $root = realpath(config('mission.integration_root'));
            $path = realpath($settings['repository_path']);
            if (! $run || ! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || $settings['base_branch'] !== ($run->settings['base_branch'] ?? null) || MissionTask::query()->whereIn('status', ['queued', 'running'])->exists()) {
                return false;
            }
            $safe = function () use ($task, $run, $path, $settings): bool {
                $common = realpath($this->git->command($path, ['rev-parse', '--path-format=absolute', '--git-common-dir']));
                foreach ([$run->settings['repository_path'], $task->worktree_path] as $original) {
                    if (! $common || realpath($this->git->command($original, ['rev-parse', '--path-format=absolute', '--git-common-dir'])) !== $common) {
                        return false;
                    }
                }

                return $this->git->command($path, ['branch', '--show-current']) === $settings['base_branch'] && $this->git->command($path, ['status', '--porcelain']) === '';
            };
            if (! $safe()) {
                return false;
            }
            $upstream = $this->git->upstream($path, $settings['base_branch']);
            if (! $upstream || $upstream['remote'] !== 'origin' || $upstream['remote_ref'] !== 'refs/heads/staging') {
                return false;
            }
            $origin = $this->git->command($path, ['remote', 'get-url', 'origin'], true);
            if (! preg_match('~^(?:https://github\.com/|git@github\.com:)'.preg_quote(config('mission.staging_repository'), '~').'(?:\.git)?$~i', $origin)) {
                return false;
            }
            $remote = $this->git->fetchUpstream($path, $settings['base_branch']);
            $head = $this->git->command($path, ['rev-parse', 'HEAD']);
            if ($remote['revision'] !== $mergeRevision || ! $safe()) {
                return false;
            }
            if ($head === $mergeRevision) {
                return true;
            }
            if ($head !== $task->revision || $this->git->command($path, ['rev-parse', $head.'^{tree}']) !== $this->git->command($path, ['rev-parse', $mergeRevision.'^{tree}'])) {
                return false;
            }
            if (! $safe() || $this->git->command($path, ['rev-parse', 'HEAD']) !== $task->revision) {
                return false;
            }
            $this->git->command($path, ['reset', '--keep', $mergeRevision]);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function githubUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/(?:actions/runs/\d+|pull/\d+)(?:/[A-Za-z0-9_.?=&/-]+)?$~', $url) ? $url : null;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['publication' => $message]);
    }
}
