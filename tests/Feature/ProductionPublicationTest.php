<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\MissionTask;
use App\Services\GitHubProcess;
use App\Services\GitRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionPublicationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $directories = [];

    private array $remote = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_plan_publish_verify_ready_and_merge_preserve_staging_and_original_checkout(): void
    {
        [$task, $repository] = $this->fixture();
        $staging = $task->publication;
        $original = $this->git($repository, ['rev-parse', 'HEAD']);
        file_put_contents($repository.'/uncommitted.txt', 'User draft');

        $plan = $this->plan($task);
        $this->get(route('tasks.production.diff', ['task' => $task, 'plan_hash' => $plan['hash']]))->assertOk()->assertDownload('production-'.$task->id.'-'.substr($plan['hash'], 0, 12).'.patch');
        $this->assertStringContainsString('Available Languages', file_get_contents($plan['artifact_path'].'/diff.patch'));
        $this->postJson(route('tasks.production.publish', $task), $this->pin($task, $plan))->assertOk()->assertJsonPath('productionPublication.state', 'draft')->assertJsonPath('productionPublication.can_ready', false);
        $receipt = $this->receipt($repository, $plan);
        $this->postJson(route('tasks.production.verify', $task), [...$this->pin($task, $plan), 'evidence_file' => $receipt])->assertOk()->assertJsonPath('productionPublication.verification.state', 'passed')->assertJsonPath('productionPublication.can_ready', true);
        $this->postJson(route('tasks.production.ready', $task), $this->pin($task, $plan))->assertOk()->assertJsonPath('productionPublication.state', 'ready');
        $response = $this->postJson(route('tasks.production.merge', $task), $this->pin($task, $plan));

        $response->assertOk()->assertJsonPath('productionPublication.state', 'merged')->assertJsonPath('productionPublication.deployment.state', 'success')->assertJsonPath('productionPublication.can_merge', false);
        $this->assertSame($plan['tree_revision'], $this->git($repository, ['rev-parse', $this->remote['merge'].'^{tree}']));
        $this->assertSame($plan['main_revision'], $this->git($repository, ['show', '-s', '--format=%P', $this->remote['merge']]));
        $this->assertSame($original, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertSame('User draft', file_get_contents($repository.'/uncommitted.txt'));
        $this->assertSame($staging, $task->fresh()->publication);
        $this->assertSame(['create', 'ready', 'merge'], $this->remote['actions']);
        $this->assertDatabaseHas('decisions', ['mission_task_id' => $task->id, 'action' => 'merge_production', 'revision' => $plan['release_revision']]);
        $this->get(route('tasks.show', $task))->assertOk()->assertSee('href="https://project.example.com"', false);
    }

    public function test_production_poll_tracks_draft_and_checks_without_marking_ready_or_merging(): void
    {
        $this->freezeTime();
        [$task] = $this->fixture();
        $plan = $this->plan($task);
        $this->postJson(route('tasks.production.publish', $task), $this->pin($task, $plan))->assertOk();
        $payload = [...$this->pin($task, $plan), 'environment' => 'production'];

        $this->postJson(route('tasks.publications.poll', $task), $payload)->assertOk()->assertJsonPath('polling.production.should_poll', true);
        $this->assertSame('draft', $task->fresh()->production_publication['state']);
        $this->remote['draft'] = false;
        $this->remote['checks'] = [];
        $this->travel(16)->seconds();
        $this->postJson(route('tasks.publications.poll', $task), $payload)->assertOk();
        $this->assertSame('checks_pending', $task->fresh()->production_publication['state']);
        $this->remote['checks'] = [['name' => 'Backend', 'bucket' => 'pass']];
        $this->travel(16)->seconds();
        $response = $this->postJson(route('tasks.publications.poll', $task), $payload);

        $response->assertOk()->assertJsonPath('polling.production.should_poll', true)->assertJsonPath('polling.production.error', null);
        $this->assertStringContainsString('Passed', $response->json('panels.production'));
        $this->assertSame('ready', $task->fresh()->production_publication['state']);
        $this->assertSame(['create'], $this->remote['actions']);
        $this->assertSame(0, $task->decisions()->where('action', 'merge_production')->count());
    }

    public function test_poll_verifies_external_production_merge_without_checkout_changes_or_redundant_fetch(): void
    {
        $this->freezeTime();
        [$task, $repository] = $this->fixture();
        $original = $this->git($repository, ['rev-parse', 'HEAD']);
        file_put_contents($repository.'/uncommitted.txt', 'Keep user work');
        $plan = $this->plan($task);
        $this->postJson(route('tasks.production.publish', $task), $this->pin($task, $plan))->assertOk();
        $canonical = $this->git($repository, ['commit-tree', $plan['tree_revision'], '-p', $plan['main_revision'], '-m', 'External production merge']);
        $this->git($repository, ['push', 'origin', $canonical.':refs/heads/main']);
        $this->remote['merge'] = $canonical;
        $this->remote['merged'] = true;
        $this->remote['open'] = false;
        app(GitRepository::class)->shouldReceive('command')->once()->withArgs(fn ($path, $args): bool => $args[0] === 'fetch')->passthru();
        $payload = [...$this->pin($task, $plan), 'environment' => 'production'];

        $this->postJson(route('tasks.publications.poll', $task), $payload)->assertOk()->assertJsonPath('polling.production.should_poll', false)->assertJsonPath('polling.production.error', null);
        $this->travel(16)->seconds();
        $this->postJson(route('tasks.publications.poll', $task), $payload)->assertOk()->assertJsonPath('polling.production.should_poll', false);

        $this->assertSame($canonical, $task->fresh()->production_publication['deployment']['revision']);
        $this->assertSame($original, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertSame('Keep user work', file_get_contents($repository.'/uncommitted.txt'));
        $this->assertSame(['create'], $this->remote['actions']);
        $this->assertSame(1, $task->decisions()->where('action', 'merge_production')->count());
    }

    public function test_poll_does_not_accept_an_external_merge_with_a_different_release_tree(): void
    {
        [$task, $repository] = $this->fixture();
        $plan = $this->plan($task);
        $this->postJson(route('tasks.production.publish', $task), $this->pin($task, $plan))->assertOk();
        $canonical = $this->git($repository, ['commit-tree', $plan['main_revision'].'^{tree}', '-p', $plan['main_revision'], '-m', 'Different merge']);
        $this->git($repository, ['push', 'origin', $canonical.':refs/heads/main']);
        $this->remote['merge'] = $canonical;
        $this->remote['merged'] = true;
        $this->remote['open'] = false;

        $this->postJson(route('tasks.publications.poll', $task), [...$this->pin($task, $plan), 'environment' => 'production'])->assertOk()->assertJsonPath('polling.production.should_poll', true)->assertJsonPath('polling.production.poll_after_ms', 60000);

        $this->assertSame('draft', $task->refresh()->production_publication['state']);
        $this->assertStringContainsString('exact planned tree and parent', $task->production_publication['error']);
        $this->assertSame(0, $task->decisions()->where('action', 'merge_production')->count());
    }

    public function test_competing_pr_requires_explicit_pinned_closure_before_publishing(): void
    {
        [$task] = $this->fixture();
        $this->remote['conflicts'] = [$this->conflict()];
        $plan = $this->plan($task);

        $this->postJson(route('tasks.production.publish', $task), $this->pin($task, $plan))->assertUnprocessable()->assertJsonValidationErrors('production');
        $this->assertSame([], $this->remote['actions']);
        $this->postJson(route('tasks.production.close-conflict', $task), [...$this->pin($task, $plan), 'pr_number' => 414, 'head_revision' => str_repeat('c', 40)])->assertOk()->assertJsonPath('productionPublication.conflicts', []);

        $this->assertSame(['close'], $this->remote['actions']);
        $this->assertDatabaseHas('decisions', ['mission_task_id' => $task->id, 'action' => 'close_production_pr', 'revision' => str_repeat('c', 40)]);
    }

    public function test_changed_conflict_branch_cannot_be_closed_using_an_old_snapshot(): void
    {
        [$task] = $this->fixture();
        $this->remote['conflicts'] = [$this->conflict()];
        $plan = $this->plan($task);
        $this->remote['conflicts'][0]['headRefName'] = 'another-release';

        $this->postJson(route('tasks.production.close-conflict', $task), [...$this->pin($task, $plan), 'pr_number' => 414, 'head_revision' => str_repeat('c', 40)])->assertUnprocessable()->assertJsonValidationErrors('production');

        $this->assertSame([], $this->remote['actions']);
    }

    public function test_conflict_changed_during_freshness_checks_is_not_closed(): void
    {
        [$task] = $this->fixture();
        $this->remote['conflicts'] = [$this->conflict()];
        $plan = $this->plan($task);
        $this->remote['advance_conflict_on_source'] = true;

        $this->postJson(route('tasks.production.close-conflict', $task), [...$this->pin($task, $plan), 'pr_number' => 414, 'head_revision' => str_repeat('c', 40)])->assertUnprocessable()->assertJsonValidationErrors('production');

        $this->assertSame([], $this->remote['actions']);
    }

    public function test_ready_requires_actual_pinned_local_verification_evidence(): void
    {
        [$task] = $this->fixture();
        $plan = $this->plan($task);
        $this->postJson(route('tasks.production.publish', $task), $this->pin($task, $plan))->assertOk();

        $this->postJson(route('tasks.production.ready', $task), $this->pin($task, $plan))->assertUnprocessable()->assertJsonValidationErrors('production');

        $this->assertSame(['create'], $this->remote['actions']);
        $this->assertTrue($this->remote['draft']);
    }

    public function test_skipped_draft_checks_never_enable_production_merge(): void
    {
        [$task, $repository] = $this->fixture();
        $plan = $this->plan($task);
        $this->postJson(route('tasks.production.publish', $task), $this->pin($task, $plan))->assertOk();
        $this->postJson(route('tasks.production.verify', $task), [...$this->pin($task, $plan), 'evidence_file' => $this->receipt($repository, $plan)])->assertOk();
        $this->remote['checks'] = [['name' => 'Backend', 'bucket' => 'skipping', 'link' => null]];

        $this->postJson(route('tasks.production.ready', $task), $this->pin($task, $plan))->assertOk()->assertJsonPath('productionPublication.state', 'checks_pending')->assertJsonPath('productionPublication.can_merge', false);
        $this->postJson(route('tasks.production.merge', $task), $this->pin($task, $plan))->assertUnprocessable()->assertJsonValidationErrors('production');

        $this->assertSame(['create', 'ready'], $this->remote['actions']);
    }

    public function test_changed_main_or_plan_hash_prevents_a_release_push(): void
    {
        [$task, $repository] = $this->fixture();
        $plan = $this->plan($task);
        $this->postJson(route('tasks.production.publish', $task), [...$this->pin($task, $plan), 'plan_hash' => str_repeat('f', 64)])->assertUnprocessable()->assertJsonValidationErrors('production');
        file_put_contents($repository.'/new-main.txt', 'Main advanced');
        $this->git($repository, ['add', 'new-main.txt']);
        $this->git($repository, ['commit', '-m', 'Main advanced']);
        $this->git($repository, ['push', 'origin', 'main']);

        $this->postJson(route('tasks.production.publish', $task), $this->pin($task, $plan))->assertUnprocessable()->assertJsonValidationErrors('production');

        $this->assertSame([], $this->remote['actions']);
    }

    public function test_live_closed_owned_pr_can_be_replanned_with_history_retained(): void
    {
        [$task] = $this->fixture();
        $old = $this->plan($task);
        $this->postJson(route('tasks.production.publish', $task), $this->pin($task, $old))->assertOk();
        $this->remote['closed'] = true;
        $this->remote['open'] = false;
        $this->postJson(route('tasks.production.refresh', $task), $this->pin($task, $old))->assertOk()->assertJsonPath('productionPublication.can_plan', true);

        $response = $this->postJson(route('tasks.production.plan', $task), ['revision' => $task->revision, 'scope' => 'staging']);

        $response->assertOk()->assertJsonPath('productionPublication.state', 'planned')->assertJsonPath('productionPublication.history.0.pr_number', 415)->assertJsonPath('productionPublication.history.0.plan.hash', $old['hash']);
        $this->assertSame(1, $task->decisions()->where('action', 'publish_production')->count());
    }

    public function test_lost_workflow_lock_prevents_remote_push(): void
    {
        [$task] = $this->fixture();
        $plan = $this->plan($task);
        $this->remote['drop_lock'] = true;

        $this->postJson(route('tasks.production.publish', $task), $this->pin($task, $plan))->assertUnprocessable()->assertJsonValidationErrors('production');

        $this->assertSame([], $this->remote['actions']);
    }

    public function test_invalid_prerequisite_input_is_a_validation_error(): void
    {
        [$task] = $this->fixture();

        $this->postJson(route('tasks.production.plan', $task), ['revision' => $task->revision, 'scope' => 'task', 'additional_paths' => [['bad']]])->assertUnprocessable()->assertJsonValidationErrors('additional_paths.0');
    }

    private function plan(MissionTask $task): array
    {
        $response = $this->postJson(route('tasks.production.plan', $task), ['revision' => $task->revision, 'scope' => 'task', 'additional_paths' => '']);
        $response->assertOk()->assertJsonPath('productionPublication.state', 'planned');

        return $response->json('productionPublication.plan');
    }

    private function pin(MissionTask $task, array $plan): array
    {
        return ['revision' => $task->revision, 'plan_hash' => $plan['hash']];
    }

    private function receipt(string $repository, array $plan): string
    {
        $root = config('mission.production_verification_root');
        mkdir($root.'/receipt', 0700, true);
        $worktree = dirname($repository).'/verified-release';
        $this->git($repository, ['worktree', 'add', '--detach', $worktree, $plan['release_revision']]);
        $checks = [];
        foreach (config('mission.production_required_checks') as $name => $check) {
            $output = $name.'.txt';
            file_put_contents($root.'/receipt/'.$output, 'Passed fixture check');
            $checks[] = ['name' => $name, ...$check, 'exitCode' => 0, 'outputFile' => $output, 'outputSha256' => hash_file('sha256', $root.'/receipt/'.$output)];
        }
        file_put_contents($root.'/receipt/evidence.json', json_encode(['version' => 1, 'planHash' => $plan['hash'], 'revision' => $plan['release_revision'], 'tree' => $plan['tree_revision'], 'worktreePath' => $worktree, 'completedAt' => now()->toISOString(), 'checks' => $checks]));

        return 'receipt/evidence.json';
    }

    private function fixture(): array
    {
        $root = storage_path('framework/testing/production-'.Str::uuid());
        $this->directories[] = $root;
        $repository = $root.'/repository';
        $worktree = $root.'/task';
        mkdir($repository, 0700, true);
        $this->git($repository, ['init', '-b', 'main']);
        $this->git($repository, ['config', 'user.name', 'Test']);
        $this->git($repository, ['config', 'user.email', 'test@example.test']);
        file_put_contents($repository.'/heading.txt', "Original heading\n");
        $this->git($repository, ['add', '.']);
        $this->git($repository, ['commit', '-m', 'Initial']);
        $main = $this->git($repository, ['rev-parse', 'HEAD']);
        $this->git($repository, ['worktree', 'add', '-b', 'mission/task', $worktree, $main]);
        file_put_contents($worktree.'/heading.txt', "Available Languages\n");
        $this->git($worktree, ['add', '.']);
        $this->git($worktree, ['commit', '-m', 'Approved heading']);
        $revision = $this->git($worktree, ['rev-parse', 'HEAD']);
        $this->git($repository, ['branch', 'staging', $revision]);
        $this->git($root, ['init', '--bare', '-b', 'main', $root.'/origin.git']);
        $this->git($repository, ['remote', 'add', 'origin', $root.'/origin.git']);
        $this->git($repository, ['push', 'origin', 'main', 'staging']);
        config(['mission.repository_path' => $repository, 'mission.production_release_root' => $root.'/releases', 'mission.production_verification_root' => $root.'/verification']);
        $task = MissionTask::factory()->create(['status' => 'merged', 'title' => 'Available Languages', 'revision' => $revision, 'base_revision' => $main, 'branch' => 'mission/task', 'worktree_path' => $worktree, 'publication' => ['state' => 'merged', 'pr_number' => 413, 'head_revision' => $revision, 'merge_revision' => $revision, 'deployment' => ['state' => 'success', 'revision' => $revision]]]);
        AgentRun::factory()->for($task, 'task')->create(['status' => 'completed', 'result' => ['revision' => $revision], 'settings' => ['repository_path' => $repository, 'base_branch' => 'staging']]);
        $this->remote = ['open' => false, 'closed' => false, 'draft' => true, 'merged' => false, 'merge' => null, 'conflicts' => [], 'checks' => [['name' => 'Backend', 'bucket' => 'pass', 'link' => null], ['name' => 'App', 'bucket' => 'skipping', 'link' => null]], 'actions' => [], 'drop_lock' => false];
        $this->partialMock(GitRepository::class, function ($mock) use ($repository): void {
            $mock->shouldReceive('command')->with($repository, ['remote', 'get-url', '--all', 'origin'], true)->andReturn('https://github.com/example/project.git');
            $mock->shouldReceive('command')->with($repository, ['remote', 'get-url', '--push', '--all', 'origin'], true)->andReturn('https://github.com/example/project.git');
            $mock->shouldReceive('command')->withArgs(fn ($path, $args): bool => $args[0] === 'push')->andReturnUsing(function ($path, $args) use ($repository): string {
                return $this->git($repository, ['push', 'origin', end($args)]);
            });
        });
        $this->mock(GitHubProcess::class, function ($mock) use ($task, $repository): void {
            $mock->shouldReceive('json')->andReturnUsing(function ($repo, $args) use ($task): array {
                if ($args[0] === 'run') {
                    $branch = $args[array_search('--branch', $args, true) + 1];
                    $sha = $args[array_search('--commit', $args, true) + 1];

                    return [['headSha' => $sha, 'headBranch' => $branch, 'status' => 'completed', 'conclusion' => 'success', 'url' => 'https://github.com/example/project/actions/runs/42']];
                }
                if ($args[1] === 'checks') {
                    return $this->remote['checks'];
                }
                if ($args[1] === 'list') {
                    if ($this->remote['drop_lock']) {
                        Cache::lock('mission-workflow')->forceRelease();
                    }

                    return [...$this->remote['conflicts'], ...($this->remote['open'] ? [$this->releasePull($task)] : [])];
                }
                if ($args[1] === 'view' && $args[2] === '413') {
                    if ($this->remote['advance_conflict_on_source'] ?? false) {
                        $this->remote['conflicts'][0]['headRefOid'] = str_repeat('e', 40);
                    }

                    return ['state' => 'MERGED', 'baseRefName' => 'staging', 'headRefOid' => $task->revision, 'mergeCommit' => ['oid' => $task->publication['merge_revision']], 'isCrossRepository' => false];
                }

                return $this->releasePull($task);
            });
            $mock->shouldReceive('command')->andReturnUsing(function ($repo, $args) use ($task, $repository): string {
                $this->remote['actions'][] = $args[1];
                if ($args[1] === 'create') {
                    $this->assertContains('--draft', $args);
                    $this->assertSame('main', $args[array_search('--base', $args, true) + 1]);
                    $this->remote['open'] = true;

                    return 'https://github.com/example/project/pull/415';
                }
                if ($args[1] === 'ready') {
                    $this->remote['draft'] = false;
                }
                if ($args[1] === 'close') {
                    $this->remote['conflicts'] = [];
                }
                if ($args[1] === 'merge') {
                    $plan = $task->fresh()->production_publication['plan'];
                    $this->assertSame(['pr', 'merge', '415', '--squash', '--match-head-commit', $plan['release_revision']], $args);
                    $canonical = $this->git($repository, ['commit-tree', $plan['tree_revision'], '-p', $plan['main_revision'], '-m', 'Canonical production squash']);
                    $this->git($repository, ['push', 'origin', $canonical.':refs/heads/main']);
                    $this->remote['merged'] = true;
                    $this->remote['open'] = false;
                    $this->remote['merge'] = $canonical;
                }

                return '';
            });
        });

        return [$task, $repository];
    }

    private function releasePull(MissionTask $task): array
    {
        $plan = $task->fresh()->production_publication['plan'];

        return ['number' => 415, 'url' => 'https://github.com/example/project/pull/415', 'state' => $this->remote['merged'] ? 'MERGED' : ($this->remote['closed'] ? 'CLOSED' : 'OPEN'), 'isDraft' => $this->remote['draft'], 'headRefName' => $plan['branch'], 'headRefOid' => $plan['release_revision'], 'baseRefName' => 'main', 'baseRefOid' => $plan['main_revision'], 'isCrossRepository' => false, 'mergeable' => 'MERGEABLE', 'mergeStateStatus' => 'CLEAN', 'mergeCommit' => $this->remote['merge'] ? ['oid' => $this->remote['merge']] : null];
    }

    private function conflict(): array
    {
        return ['number' => 414, 'url' => 'https://github.com/example/project/pull/414', 'headRefName' => 'staging', 'headRefOid' => str_repeat('c', 40), 'baseRefName' => 'main', 'baseRefOid' => str_repeat('d', 40), 'isCrossRepository' => false];
    }

    private function git(string $repository, array $arguments): string
    {
        $process = new Process(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', '-C', $repository, ...$arguments]);
        $process->setTimeout(10);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
