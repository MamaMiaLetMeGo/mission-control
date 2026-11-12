<?php

namespace App\Services;

use App\Models\MissionTask;
use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ProductionPublication
{
    private const PR_FIELDS = 'number,url,state,isDraft,headRefName,headRefOid,baseRefName,baseRefOid,isCrossRepository,mergeable,mergeStateStatus,mergeCommit';

    private ?Lock $operationLock = null;

    private float $deadline = 0;

    public function __construct(private MissionSettings $settings, private GitRepository $git, private GitHubProcess $github, private ProductionReleasePlan $plans, private ProductionVerification $verification) {}

    /** @return array<string, mixed> */
    public function status(MissionTask $task): array
    {
        $status = [...['state' => 'unplanned', 'plan' => null, 'pr_number' => null, 'pr_url' => null, 'head_revision' => null, 'merge_revision' => null, 'checks' => [], 'verification' => ['state' => 'not_verified'], 'deployment' => ['state' => 'not_started', 'url' => null, 'revision' => null], 'conflicts' => [], 'blockers' => [], 'error' => null, 'last_checked_at' => null], ...($task->production_publication ?? [])];
        $active = MissionTask::query()->whereIn('status', ['queued', 'running'])->exists();
        $staged = $task->status === 'merged' && data_get($task->publication, 'state') === 'merged' && data_get($task->publication, 'deployment.state') === 'success' && data_get($task->publication, 'deployment.revision') === data_get($task->publication, 'merge_revision');
        if (! $staged) {
            $status['blockers'][] = 'Verify this task’s exact staging deployment before planning production.';
        }
        if ($active) {
            $status['blockers'][] = 'Wait for the active task before changing production publication.';
        }
        $available = $staged && ! $active;
        $status['can_plan'] = $available && (! $status['pr_number'] || $status['state'] === 'closed');
        $status['can_publish'] = $available && $status['plan'] && ! $status['pr_number'] && $status['conflicts'] === [];
        $status['can_verify'] = $available && $status['plan'] && $status['state'] !== 'merged';
        $verified = data_get($status, 'verification.state') === 'passed' && data_get($status, 'verification.plan_hash') === data_get($status, 'plan.hash');
        $status['can_ready'] = $available && $status['state'] === 'draft' && $verified && $status['conflicts'] === [];
        $status['can_merge'] = $available && $status['state'] === 'ready' && $verified && $status['conflicts'] === [] && ! $status['error'];
        if ($status['plan']) {
            $status['plan']['diff_url'] = route('tasks.production.diff', ['task' => $task, 'plan_hash' => $status['plan']['hash']]);
        }

        return $status;
    }

    /** @param list<string> $additionalPaths
     * @return array<string, mixed>
     */
    public function plan(MissionTask $task, string $revision, string $scope, array $additionalPaths): array
    {
        return $this->locked($task, $revision, null, function (array $context) use ($task, $scope, $additionalPaths): array {
            $history = data_get($task->production_publication, 'history', []);
            if (data_get($task->production_publication, 'pr_number')) {
                $oldPlan = $task->production_publication['plan'];
                $this->plans->validate($task, $context['path'], $oldPlan, $oldPlan['hash']);
                $old = $this->pull($task, $context, $oldPlan);
                if ($old['state'] !== 'CLOSED') {
                    $this->reject('An existing production PR already owns this plan. Explicitly close it and refresh before preparing another.');
                }
                $previous = $task->production_publication;
                unset($previous['history']);
                $history[] = $previous;
            }
            $source = $this->source($task, $context);
            $this->fetch($context);
            $this->git->command($context['path'], ['merge-base', '--is-ancestor', $source, 'refs/remotes/origin/staging']);
            $main = $this->git->command($context['path'], ['rev-parse', 'refs/remotes/origin/main']);
            $this->assertMutationAllowed();
            $plan = $this->plans->create($task, $context['path'], $main, $source, $scope, $additionalPaths);
            $conflicts = $this->conflicts($context, $plan);
            $this->assertMutationAllowed();
            $task->update(['production_publication' => ['state' => 'planned', 'repository_path' => $context['path'], 'plan' => $plan, 'head_revision' => $plan['release_revision'], 'conflicts' => $conflicts, 'history' => $history, 'last_checked_at' => now()->toISOString()]]);

            return $this->status($task);
        });
    }

    /** @return array<string, mixed> */
    public function verify(MissionTask $task, string $revision, string $hash, string $file): array
    {
        return $this->locked($task, $revision, $hash, function (array $context, array $plan) use ($task, $file): array {
            $proof = $this->verification->read($context['path'], $plan, $file);
            $this->save($task, ['verification' => $proof, 'error' => null]);
            $task->decisions()->create(['action' => 'verify_production', 'revision' => $plan['release_revision'], 'feedback' => 'Verified release plan '.$plan['hash'].' with receipt '.$proof['receipt_digest']]);

            return $this->status($task);
        });
    }

    /** @return array<string, mixed> */
    public function publish(MissionTask $task, string $revision, string $hash): array
    {
        return $this->locked($task, $revision, $hash, function (array $context, array $plan) use ($task): array {
            $this->assertFresh($task, $context, $plan);
            $this->assertNoConflicts($task, $context, $plan);
            if (data_get($task->production_publication, 'pr_number')) {
                return $this->refreshState($task, $context, $plan);
            }
            $existing = $this->git->command($context['path'], ['ls-remote', '--heads', '--', 'origin', 'refs/heads/'.$plan['branch']], true);
            if ($existing !== '' && preg_split('/\s+/', $existing)[0] !== $plan['release_revision']) {
                $this->reject('The remote release branch differs from this exact plan. It will not be overwritten.');
            }
            $this->context($task, $task->revision);
            $this->assertMutationAllowed();
            $this->git->command($context['path'], ['push', '--porcelain', '--no-follow-tags', '--no-mirror', '--recurse-submodules=no', '--', $context['push_url'], $plan['release_revision'].':refs/heads/'.$plan['branch']], true);
            $this->assertFresh($task, $context, $plan);
            $pulls = $this->openPulls($context);
            $matching = $this->matching($pulls, $plan);
            if (count($pulls) > ($matching ? 1 : 0)) {
                $this->reject('Another production PR appeared. Resolve it explicitly before creating a release PR.');
            }
            if ($matching) {
                $number = $matching['number'];
            } else {
                $files = implode("\n", array_map(fn (array $change): string => '- '.$change['status'].' '.$change['path'], $plan['changed_paths']));
                $scopeDescription = $plan['scope'] === 'staging' ? 'Release the complete verified staging snapshot.' : 'Release '.$task->title.'.'.($plan['additional_paths'] ? ' Include the explicitly reviewed prerequisites: '.implode(', ', $plan['additional_paths']).'.' : '');
                $body = $scopeDescription."\n\nProduction base: ".$plan['main_revision']."\nVerified staging source: ".$plan['staging_revision']."\nRelease tree: ".$plan['tree_revision']."\nPlan: ".$plan['hash']."\n\nComplete changed paths\n".$files."\n\nLocal production checks must be imported before this draft is marked ready. Merging targets production.";
                $title = $plan['scope'] === 'staging' ? 'Release verified staging snapshot '.substr($plan['staging_revision'], 0, 12) : 'Release: '.$task->title;
                $this->assertMutationAllowed();
                $url = $this->github->command($context['repository'], ['pr', 'create', '--draft', '--base', 'main', '--head', $plan['branch'], '--title', $title, '--body-file', '-', '--no-maintainer-edit'], $body);
                if (! preg_match('~^https://github\.com/'.preg_quote($context['repository'], '~').'/pull/(\d+)$~', $url, $match)) {
                    $this->reject('Could not verify the created production PR. Retry to recover the matching PR.');
                }
                $number = (int) $match[1];
            }
            $this->save($task, ['pr_number' => $number, 'pr_url' => 'https://github.com/'.$context['repository'].'/pull/'.$number]);
            $task->decisions()->firstOrCreate(['action' => 'publish_production', 'revision' => $plan['release_revision']], ['feedback' => 'Published exact release plan '.$plan['hash'].' as draft '.$task->production_publication['pr_url']]);

            return $this->refreshState($task, $context, $plan);
        });
    }

    /** @return array<string, mixed> */
    public function ready(MissionTask $task, string $revision, string $hash): array
    {
        return $this->locked($task, $revision, $hash, function (array $context, array $plan) use ($task): array {
            $this->assertFresh($task, $context, $plan);
            $this->assertNoConflicts($task, $context, $plan);
            $this->assertVerified($task, $context, $plan);
            $pull = $this->pull($task, $context, $plan);
            if ($pull['state'] !== 'OPEN') {
                $this->reject('Only the pinned open production PR can be marked ready.');
            }
            if ($pull['isDraft']) {
                $this->save($task, ['ready_requested_at' => now()->toISOString()]);
                $this->assertMutationAllowed();
                $this->github->command($context['repository'], ['pr', 'ready', (string) $pull['number']]);
            }
            $task->decisions()->firstOrCreate(['action' => 'ready_production', 'revision' => $plan['release_revision']], ['feedback' => 'Marked exact release plan '.$plan['hash'].' ready after verified local checks.']);

            return $this->refreshState($task, $context, $plan);
        });
    }

    /** @return array<string, mixed> */
    public function merge(MissionTask $task, string $revision, string $hash): array
    {
        return $this->locked($task, $revision, $hash, function (array $context, array $plan) use ($task): array {
            $state = $this->refreshState($task, $context, $plan);
            if ($state['state'] === 'merged') {
                return $state;
            }
            $this->assertFresh($task, $context, $plan);
            $this->assertNoConflicts($task, $context, $plan);
            $this->assertVerified($task, $context, $plan);
            if (! $state['can_merge']) {
                $this->reject('The pinned production PR is not ready: '.implode(' ', $state['blockers']));
            }
            $pull = $this->pull($task, $context, $plan);
            if ($pull['state'] !== 'OPEN' || $pull['isDraft'] || $pull['baseRefOid'] !== $plan['main_revision'] || $pull['mergeStateStatus'] !== 'CLEAN' || $pull['mergeable'] !== 'MERGEABLE') {
                $this->reject('Production PR mergeability or its base changed. Review a refreshed plan.');
            }
            $this->assertMutationAllowed();
            $this->github->command($context['repository'], ['pr', 'merge', (string) $pull['number'], '--squash', '--match-head-commit', $plan['release_revision']]);

            return $this->refreshState($task, $context, $plan);
        });
    }

    /** @return array<string, mixed> */
    public function refresh(MissionTask $task, string $revision, string $hash): array
    {
        return $this->locked($task, $revision, $hash, function (array $context, array $plan) use ($task): array {
            if (! data_get($task->production_publication, 'pr_number')) {
                $this->save($task, ['conflicts' => $this->conflicts($context, $plan), 'error' => null, 'last_checked_at' => now()->toISOString()]);

                return $this->status($task);
            }

            return $this->refreshState($task, $context, $plan);
        }, readOnly: true);
    }

    /** @return array<string, mixed> */
    public function refreshForPolling(MissionTask $task, string $revision, string $hash, Lock $lock): array
    {
        if (! $lock->isOwnedByCurrentProcess()) {
            $this->reject('The status refresh lost its workflow lock. Try again shortly.');
        }
        $context = $this->context($task, $revision, data_get($task->production_publication, 'repository_path'));
        $plan = data_get($task->production_publication, 'plan');
        if (! is_array($plan)) {
            $this->reject('Prepare and review a production plan first.');
        }
        $this->plans->validate($task, $context['path'], $plan, $hash);

        return $this->refreshState($task, $context, $plan);
    }

    /** @return array<string, mixed> */
    public function closeConflict(MissionTask $task, string $revision, string $hash, int $number, string $head): array
    {
        return $this->locked($task, $revision, $hash, function (array $context, array $plan) use ($task, $number, $head): array {
            $recorded = collect(data_get($task->production_publication, 'conflicts', []))->first(fn (array $conflict): bool => $conflict['number'] === $number && $conflict['head_revision'] === $head);
            $current = collect($this->conflicts($context, $plan))->first(fn (array $conflict): bool => $conflict['number'] === $number && $conflict['head_revision'] === $head);
            if (! $recorded || ! $current || $recorded !== $current) {
                $this->reject('The competing PR changed or is no longer open. Refresh before deciding to close it.');
            }
            $this->assertFresh($task, $context, $plan);
            $latest = collect($this->conflicts($context, $plan))->first(fn (array $conflict): bool => $conflict['number'] === $number);
            if ($latest !== $recorded) {
                $this->reject('The competing PR changed during the freshness check. Refresh before deciding to close it.');
            }
            $this->assertMutationAllowed();
            $this->github->command($context['repository'], ['pr', 'close', (string) $number]);
            $task->decisions()->create(['action' => 'close_production_pr', 'revision' => $head, 'feedback' => 'Explicitly closed competing production PR #'.$number.' for release plan '.$plan['hash']]);
            $this->save($task, ['conflicts' => $this->conflicts($context, $plan), 'error' => null]);

            return $this->status($task);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function locked(MissionTask $task, string $revision, ?string $hash, Closure $operation, bool $readOnly = false): array
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(360);
        }

        $lock = Cache::lock('mission-workflow', 900);

        try {
            return $lock->block(5, function () use ($task, $revision, $hash, $operation, $readOnly, $lock): array {
                $this->operationLock = $lock;
                $this->deadline = microtime(true) + 300;
                $task->refresh();
                try {
                    if (! $readOnly && MissionTask::query()->whereIn('status', ['queued', 'running'])->exists()) {
                        $this->reject('Wait for the active task before changing production publication.');
                    }
                    $context = $this->context($task, $revision, $readOnly ? data_get($task->production_publication, 'repository_path') : null);
                    $plan = data_get($task->production_publication, 'plan');
                    if ($hash !== null) {
                        if (! is_array($plan)) {
                            $this->reject('Prepare and review a production plan first.');
                        }
                        $this->plans->validate($task, $context['path'], $plan, $hash);
                    }

                    return $operation($context, $plan);
                } catch (ValidationException $exception) {
                    if ($task->production_publication) {
                        $this->save($task, ['error' => collect($exception->errors())->flatten()->first()]);
                    }
                    throw $exception;
                } finally {
                    $this->operationLock = null;
                }
            });
        } catch (LockTimeoutException) {
            $this->reject('Another Mission Control operation is updating status. Wait a moment, then try this action again.');
        }
    }

    /** @return array{path: string, repository: string, push_url: string} */
    private function context(MissionTask $task, string $revision, ?string $savedPath = null): array
    {
        if ($task->status !== 'merged' || $task->revision !== $revision || data_get($task->publication, 'state') !== 'merged' || data_get($task->publication, 'head_revision') !== $revision || ! preg_match('/^[a-f0-9]{40,64}$/', data_get($task->publication, 'merge_revision', ''))) {
            $this->reject('Production starts from this exact task’s verified staging merge.');
        }
        $run = $task->runs()->first();
        $settings = $this->settings->all();
        $path = realpath($savedPath ?? $settings['repository_path']);
        if (! $path || ! $run || $run->status !== 'completed' || data_get($run->result, 'revision') !== $revision) {
            $this->reject('The original completed run and local repository are required.');
        }
        $common = realpath($this->git->command($path, ['rev-parse', '--path-format=absolute', '--git-common-dir']));
        if (! $common || realpath($this->git->command($run->settings['repository_path'], ['rev-parse', '--path-format=absolute', '--git-common-dir'])) !== $common) {
            $this->reject('The configured repository differs from the staged task’s original repository.');
        }
        $repository = config('mission.staging_repository');
        $expected = '~^(?:https://github\.com/|git@github\.com:)'.preg_quote($repository, '~').'(?:\.git)?$~i';
        $fetch = $this->git->command($path, ['remote', 'get-url', '--all', 'origin'], true);
        $push = $this->git->command($path, ['remote', 'get-url', '--push', '--all', 'origin'], true);
        $canonical = 'https://github.com/'.$repository.'.git';
        if (! preg_match($expected, $fetch) || ! preg_match($expected, $push) || ! preg_match($expected, $this->git->effectivePushDestination($path, $canonical))) {
            $this->reject('Production origin and effective push destination must identify exactly the configured GitHub repository.');
        }

        return ['path' => $path, 'repository' => $repository, 'push_url' => $canonical];
    }

    /** @param array<string, mixed> $context */
    private function source(MissionTask $task, array $context): string
    {
        $publication = $task->publication;
        $pull = $this->github->json($context['repository'], ['pr', 'view', (string) $publication['pr_number'], '--json', self::PR_FIELDS]);
        $source = $publication['merge_revision'];
        if (($pull['state'] ?? null) !== 'MERGED' || ($pull['baseRefName'] ?? null) !== 'staging' || ($pull['headRefOid'] ?? null) !== $task->revision || data_get($pull, 'mergeCommit.oid') !== $source || ($pull['isCrossRepository'] ?? true)) {
            $this->reject('The recorded staging PR no longer verifies this task’s exact source.');
        }
        if ($this->deployment($context, 'staging', $source)['state'] !== 'success') {
            $this->reject('The exact source staging deployment has not succeeded.');
        }

        return $source;
    }

    /** @param array<string, mixed> $context */
    private function fetch(array $context): void
    {
        $this->git->command($context['path'], ['fetch', '--atomic', '--no-tags', '--no-recurse-submodules', '--no-write-fetch-head', '--no-auto-maintenance', '--', 'origin', '+refs/heads/main:refs/remotes/origin/main', '+refs/heads/staging:refs/remotes/origin/staging'], true);
    }

    /** @param array<string, mixed> $context
     * @param  array<string, mixed>  $plan
     */
    private function assertFresh(MissionTask $task, array $context, array $plan): void
    {
        if ($this->source($task, $context) !== $plan['staging_revision']) {
            $this->reject('The verified staging source differs from the pinned plan.');
        }
        $this->fetch($context);
        if ($this->git->command($context['path'], ['rev-parse', 'refs/remotes/origin/main']) !== $plan['main_revision']) {
            $this->reject('Production advanced after this plan. Prepare and review a new plan.');
        }
        $this->git->command($context['path'], ['merge-base', '--is-ancestor', $plan['staging_revision'], 'refs/remotes/origin/staging']);
        $this->plans->validate($task, $context['path'], $plan, $plan['hash']);
        $this->context($task, $task->revision);
    }

    /** @param array<string, mixed> $context
     */
    private function openPulls(array $context): array
    {
        return $this->github->json($context['repository'], ['pr', 'list', '--state', 'open', '--base', 'main', '--limit', '100', '--json', self::PR_FIELDS]);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function matching(array $pulls, array $plan): ?array
    {
        return collect($pulls)->first(fn (array $pull): bool => ($pull['headRefName'] ?? null) === $plan['branch'] && ($pull['headRefOid'] ?? null) === $plan['release_revision'] && ($pull['baseRefName'] ?? null) === 'main' && ($pull['baseRefOid'] ?? null) === $plan['main_revision'] && ($pull['isCrossRepository'] ?? true) === false);
    }

    /** @param array<string, mixed> $context
     * @param  array<string, mixed>  $plan
     */
    private function conflicts(array $context, array $plan): array
    {
        $pulls = $this->openPulls($context);
        $matching = $this->matching($pulls, $plan);

        return array_values(array_map(fn (array $pull): array => ['number' => $pull['number'], 'url' => $pull['url'], 'head_revision' => $pull['headRefOid'], 'head_branch' => $pull['headRefName']], array_filter($pulls, fn (array $pull): bool => ! $matching || $pull['number'] !== $matching['number'])));
    }

    private function assertNoConflicts(MissionTask $task, array $context, array $plan): void
    {
        $conflicts = $this->conflicts($context, $plan);
        $this->save($task, ['conflicts' => $conflicts]);
        if ($conflicts !== []) {
            $this->reject('Another production PR is open. Resolve it explicitly before proceeding.');
        }
    }

    private function assertVerified(MissionTask $task, array $context, array $plan): void
    {
        $proof = data_get($task->production_publication, 'verification');
        if (! is_array($proof) || ($proof['state'] ?? null) !== 'passed') {
            $this->reject('Import successful local checks for this exact production release before marking ready or merging.');
        }
        $this->verification->read($context['path'], $plan, $proof['receipt_file'], $proof['receipt_digest']);
    }

    private function pull(MissionTask $task, array $context, array $plan): array
    {
        $number = data_get($task->production_publication, 'pr_number');
        if (! is_int($number) || $number < 1) {
            $this->reject('Publish the reviewed plan as a draft production PR first.');
        }
        $pull = $this->github->json($context['repository'], ['pr', 'view', (string) $number, '--json', self::PR_FIELDS]);
        if (($pull['number'] ?? null) !== $number || ($pull['url'] ?? null) !== 'https://github.com/'.$context['repository'].'/pull/'.$number || ($pull['headRefName'] ?? null) !== $plan['branch'] || ($pull['headRefOid'] ?? null) !== $plan['release_revision'] || ($pull['baseRefName'] ?? null) !== 'main' || ($pull['isCrossRepository'] ?? true)) {
            $this->reject('The production PR no longer matches the exact planned head and target.');
        }

        return $pull;
    }

    private function refreshState(MissionTask $task, array $context, array $plan): array
    {
        $pull = $this->pull($task, $context, $plan);
        $checks = data_get($task->production_publication, 'checks', []);
        $blockers = [];
        $merge = null;
        $deployment = ['state' => 'not_started', 'url' => null, 'revision' => null];
        $state = 'closed';
        if ($pull['state'] === 'MERGED') {
            $merge = data_get($pull, 'mergeCommit.oid');
            if (! is_string($merge) || ! preg_match('/^[a-f0-9]{40,64}$/', $merge)) {
                $this->reject('GitHub has not reported the canonical production merge revision.');
            }
            if (data_get($task->production_publication, 'state') !== 'merged' || data_get($task->production_publication, 'merge_revision') !== $merge) {
                $this->fetch($context);
            }
            if ($this->git->command($context['path'], ['rev-parse', $merge.'^{tree}']) !== $plan['tree_revision'] || $this->git->command($context['path'], ['show', '-s', '--format=%P', $merge]) !== $plan['main_revision']) {
                $this->reject('The merged production commit does not have the exact planned tree and parent. Deployment cannot be verified from this plan.');
            }
            $state = 'merged';
            $deployment = $this->deployment($context, 'main', $merge);
            $task->decisions()->firstOrCreate(['action' => 'merge_production', 'revision' => $plan['release_revision']], ['feedback' => 'GitHub merged release plan '.$plan['hash'].' into production at '.$merge]);
        } elseif ($pull['state'] === 'OPEN') {
            if ($pull['isDraft']) {
                $state = 'draft';
                $blockers[] = 'Import exact release checks, then mark this draft ready.';
            } else {
                $reported = $this->github->json($context['repository'], ['pr', 'checks', (string) $pull['number'], '--required', '--json', 'name,bucket,link'], [0, 1, 8]);
                $checks = array_map(fn (array $check): array => ['name' => $check['name'] ?? 'Required check', 'state' => $check['bucket'] ?? 'pending', 'url' => $check['link'] ?? null], $reported);
                if ($checks === []) {
                    $blockers[] = 'GitHub has not reported the required checks yet.';
                } elseif (! collect($checks)->contains(fn (array $check): bool => $check['state'] === 'pass')) {
                    $blockers[] = 'At least one required check must pass for this release; draft-only skipped checks are insufficient.';
                }
                foreach ($checks as $check) {
                    if (! in_array($check['state'], ['pass', 'skipping'], true)) {
                        $blockers[] = $check['name'].' is '.$check['state'].'.';
                    }
                }
                if ($pull['baseRefOid'] !== $plan['main_revision'] || $pull['mergeStateStatus'] !== 'CLEAN' || $pull['mergeable'] !== 'MERGEABLE') {
                    $blockers[] = 'The production PR must match its base and satisfy all branch protections.';
                }
                $failed = collect($checks)->contains(fn (array $check): bool => in_array($check['state'], ['fail', 'cancel'], true));
                $state = $blockers === [] ? 'ready' : ($failed ? 'checks_failed' : 'checks_pending');
            }
        }
        $this->save($task, ['state' => $state, 'checks' => $checks, 'blockers' => $blockers, 'merge_revision' => $merge, 'deployment' => $deployment, 'conflicts' => $this->conflicts($context, $plan), 'error' => null, 'last_checked_at' => now()->toISOString()]);

        return $this->status($task);
    }

    private function deployment(array $context, string $branch, string $revision): array
    {
        $runs = $this->github->json($context['repository'], ['run', 'list', '--workflow', config('mission.staging_deploy_workflow'), '--branch', $branch, '--commit', $revision, '--event', 'push', '--limit', '5', '--json', 'status,conclusion,url,headSha,headBranch']);
        foreach ($runs as $run) {
            if (($run['headSha'] ?? null) === $revision && ($run['headBranch'] ?? null) === $branch) {
                return ['state' => $run['status'] === 'completed' ? ($run['conclusion'] === 'success' ? 'success' : 'failure') : ($run['status'] === 'in_progress' ? 'running' : 'pending'), 'url' => $run['url'] ?? null, 'revision' => $revision];
            }
        }

        return ['state' => 'not_started', 'url' => null, 'revision' => $revision];
    }

    private function save(MissionTask $task, array $values): void
    {
        $task->update(['production_publication' => [...($task->production_publication ?? []), ...$values]]);
    }

    private function assertMutationAllowed(): void
    {
        if (microtime(true) >= $this->deadline || ! $this->operationLock?->isOwnedByCurrentProcess()) {
            $this->reject('This operation exceeded its safe time window or lost its lock. Refresh the production status before trying again.');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['production' => $message]);
    }
}
