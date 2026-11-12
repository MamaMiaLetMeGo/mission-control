<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\MissionTask;
use App\Services\GitHubProcess;
use App\Services\GitRepository;
use App\Services\StagingPublication;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StagingPublicationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_publish_pushes_only_the_reviewed_branch_and_creates_a_staging_pr(): void
    {
        [$task, $repository, $integration] = $this->fixture();
        $runSettings = $task->runs()->sole()->settings;
        $this->allowOrigin($task, $integration, push: true);
        $this->mock(GitHubProcess::class, function ($mock) use ($task): void {
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[0] === 'pr' && $args[1] === 'list' && in_array('staging', $args, true))->andReturn([]);
            $mock->shouldReceive('command')->once()->withArgs(function ($repository, $args, $body): bool {
                return $args[0] === 'pr' && $args[1] === 'create' && $args[3] === 'staging' && in_array('--no-maintainer-edit', $args, true) && ! str_contains($body, 'internal workflow instructions') && str_contains($body, 'Verification command');
            })->andReturn('https://github.com/example/project/pull/12');
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[1] === 'view')->andReturn($this->pull($task));
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args, $codes): bool => $args[1] === 'checks' && in_array('--required', $args, true) && $codes === [0, 1, 8])->andReturn($this->checks());
        });

        $response = $this->postJson(route('tasks.publish', $task), ['revision' => $task->revision]);

        $response->assertOk()->assertJsonPath('publication.state', 'ready')->assertJsonPath('publication.can_merge', true);
        $remoteHead = preg_split('/\s+/', $this->git($repository, ['ls-remote', '--heads', 'origin', 'refs/heads/'.$task->branch]))[0];
        $this->assertSame($task->revision, $remoteHead);
        $this->assertSame($task->base_revision, $this->git($repository, ['rev-parse', 'origin/staging']));
        $this->assertSame($runSettings, $task->runs()->sole()->settings);
        $this->assertDatabaseHas('decisions', ['mission_task_id' => $task->id, 'action' => 'publish', 'revision' => $task->revision]);
    }

    public function test_publish_reuses_a_matching_pr_after_an_interrupted_request(): void
    {
        [$task, , $integration] = $this->fixture();
        $this->allowOrigin($task, $integration, push: true);
        $this->mock(GitHubProcess::class, function ($mock) use ($task): void {
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[1] === 'list')->andReturn([['number' => 12, 'headRefOid' => $task->revision, 'state' => 'OPEN']]);
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[1] === 'view')->andReturn($this->pull($task));
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[1] === 'checks')->andReturn($this->checks());
            $mock->shouldNotReceive('command');
        });

        $this->postJson(route('tasks.publish', $task), ['revision' => $task->revision])->assertOk()->assertJsonPath('publication.pr_number', 12);

        $this->assertSame(1, $task->decisions()->where('action', 'publish')->count());
    }

    public function test_a_different_push_destination_is_rejected_before_publishing(): void
    {
        [$task, , $integration] = $this->fixture();
        $this->allowOrigin($task, $integration, pushUrl: 'https://github.com/another/private.git');
        $this->mock(GitHubProcess::class, fn ($mock) => $mock->shouldNotReceive('command', 'json'));

        $response = $this->postJson(route('tasks.publish', $task), ['revision' => $task->revision]);

        $response->assertUnprocessable()->assertJsonValidationErrors('publication');
        $this->assertNull($task->refresh()->publication);
        $this->assertSame(0, $task->decisions()->where('action', 'publish')->count());
    }

    #[TestWith(['insteadOf'])]
    #[TestWith(['pushInsteadOf'])]
    public function test_global_https_push_rewriting_cannot_redirect_an_ssh_origin_publication(string $rule): void
    {
        [$task, , $integration] = $this->fixture();
        $home = dirname(dirname($integration)).'/git-home';
        mkdir($home, 0700);
        file_put_contents($home.'/.gitconfig', "[url \"https://mirror.example/\"]\n ".$rule." = https://github.com/\n");
        $oldHome = getenv('HOME');
        putenv('HOME='.$home);
        $this->partialMock(GitRepository::class, function ($mock) use ($integration): void {
            $mock->shouldReceive('command')->with($integration, ['remote', 'get-url', 'origin'], true)->andReturn('git@github.com:example/project.git');
            $mock->shouldReceive('command')->with($integration, ['remote', 'get-url', '--push', '--all', 'origin'], true)->andReturn('git@github.com:example/project.git');
        });
        $this->mock(GitHubProcess::class, fn ($mock) => $mock->shouldNotReceive('command', 'json'));
        try {
            $response = $this->postJson(route('tasks.publish', $task), ['revision' => $task->revision]);

            $response->assertUnprocessable()->assertJsonValidationErrors('publication');
            $this->assertStringContainsString('URL rewriting', $response->json('errors.publication.0'));
            $this->assertNull($task->refresh()->publication);
        } finally {
            putenv($oldHome === false ? 'HOME' : 'HOME='.$oldHome);
        }
    }

    public function test_a_changed_remote_base_blocks_publication(): void
    {
        [$task, $repository, $integration] = $this->fixture();
        $this->allowOrigin($task, $integration);
        file_put_contents($repository.'/other.txt', 'New base work');
        $this->git($repository, ['add', 'other.txt']);
        $this->git($repository, ['commit', '-m', 'Advance staging']);
        $this->git($repository, ['push', 'origin', 'staging']);
        $this->mock(GitHubProcess::class, fn ($mock) => $mock->shouldNotReceive('command', 'json'));

        $this->postJson(route('tasks.publish', $task), ['revision' => $task->revision])->assertUnprocessable()->assertJsonValidationErrors('publication');

        $this->assertNull($task->refresh()->publication);
        $this->assertSame($task->revision, $this->git($integration, ['rev-parse', 'HEAD']));
    }

    #[TestWith(['pending', 'checks_pending'])]
    #[TestWith(['fail', 'checks_failed'])]
    public function test_required_checks_prevent_staging_merge(string $bucket, string $state): void
    {
        [$task, , $integration] = $this->fixture(published: true);
        $this->allowOrigin($task, $integration);
        $this->mock(GitHubProcess::class, function ($mock) use ($task, $bucket): void {
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[1] === 'view')->andReturn($this->pull($task));
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[1] === 'checks')->andReturn([['name' => 'Backend', 'bucket' => $bucket, 'link' => null]]);
            $mock->shouldNotReceive('command');
        });

        $this->postJson(route('tasks.publication.merge', $task), ['revision' => $task->revision])->assertUnprocessable()->assertJsonValidationErrors('publication');

        $this->assertSame($state, $task->refresh()->publication['state']);
        $this->assertFalse(app(StagingPublication::class)->status($task)['can_merge']);
        $this->assertSame(0, $task->decisions()->where('action', 'merge_staging')->count());
    }

    public function test_a_changed_pr_head_cannot_use_the_old_review(): void
    {
        [$task, , $integration] = $this->fixture(published: true);
        $this->allowOrigin($task, $integration);
        $this->mock(GitHubProcess::class, function ($mock) use ($task): void {
            $mock->shouldReceive('json')->once()->andReturn([...$this->pull($task), 'headRefOid' => str_repeat('e', 40)]);
            $mock->shouldNotReceive('command');
        });

        $this->postJson(route('tasks.publication.merge', $task), ['revision' => $task->revision])->assertUnprocessable()->assertJsonValidationErrors('publication');

        $this->assertSame(0, $task->decisions()->where('action', 'merge_staging')->count());
    }

    public function test_unreported_required_checks_are_pending_and_never_enable_merging(): void
    {
        [$task, , $integration] = $this->fixture(published: true);
        $this->allowOrigin($task, $integration);
        $this->mock(GitHubProcess::class, function ($mock) use ($task): void {
            $mock->shouldReceive('json')->twice()->withArgs(fn ($repository, $args): bool => $args[1] === 'view')->andReturn($this->pull($task));
            $mock->shouldReceive('json')->twice()->withArgs(fn ($repository, $args): bool => $args[1] === 'checks')->andReturn([]);
            $mock->shouldNotReceive('command');
        });

        $this->postJson(route('tasks.publication.refresh', $task), ['revision' => $task->revision])->assertOk()->assertJsonPath('publication.state', 'checks_pending')->assertJsonPath('publication.can_merge', false)->assertJsonPath('publication.error', null);
        $this->postJson(route('tasks.publication.merge', $task), ['revision' => $task->revision])->assertUnprocessable()->assertJsonValidationErrors('publication');

        $this->assertSame(0, $task->decisions()->where('action', 'merge_staging')->count());
    }

    public function test_production_pr_target_is_rejected_even_if_checks_are_green(): void
    {
        [$task, , $integration] = $this->fixture(published: true);
        $this->allowOrigin($task, $integration);
        $this->mock(GitHubProcess::class, function ($mock) use ($task): void {
            $mock->shouldReceive('json')->once()->andReturn([...$this->pull($task), 'baseRefName' => 'main']);
            $mock->shouldNotReceive('command');
        });

        $this->postJson(route('tasks.publication.merge', $task), ['revision' => $task->revision])->assertUnprocessable()->assertJsonValidationErrors('publication');
    }

    public function test_merge_pins_the_reviewed_head_and_reconciles_only_identical_integration_trees(): void
    {
        [$task, $repository, $integration] = $this->fixture(published: true);
        $this->allowOrigin($task, $integration);
        file_put_contents($repository.'/lesson.txt', "Original dirty checkout\n");
        $canonical = $this->git($integration, ['commit-tree', $task->revision.'^{tree}', '-p', $task->base_revision, '-m', 'GitHub squash result']);
        $merged = false;
        $this->mock(GitHubProcess::class, function ($mock) use ($task, $repository, $canonical, &$merged): void {
            $mock->shouldReceive('json')->withArgs(fn ($repository, $args): bool => $args[0] === 'pr' && $args[1] === 'view')->andReturnUsing(function () use ($task, $canonical, &$merged): array {
                return $merged ? [...$this->pull($task), 'state' => 'MERGED', 'mergeCommit' => ['oid' => $canonical]] : $this->pull($task);
            });
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[1] === 'checks')->andReturn($this->checks());
            $mock->shouldReceive('command')->once()->with('example/project', ['pr', 'merge', '12', '--squash', '--match-head-commit', $task->revision])->andReturnUsing(function () use ($repository, $canonical, &$merged): string {
                $this->git($repository, ['push', 'origin', $canonical.':refs/heads/staging']);
                $merged = true;

                return '';
            });
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[0] === 'run' && in_array($canonical, $args, true) && in_array('staging', $args, true))->andReturn([['headSha' => $canonical, 'headBranch' => 'staging', 'status' => 'queued', 'conclusion' => null, 'url' => 'https://github.com/example/project/actions/runs/42']]);
        });

        $response = $this->postJson(route('tasks.publication.merge', $task), ['revision' => $task->revision]);

        $response->assertOk()->assertJsonPath('publication.state', 'merged')->assertJsonPath('publication.integration_synced', true)->assertJsonPath('publication.deployment.state', 'pending')->assertJsonPath('publication.merge_revision', $canonical)->assertJsonPath('publication.can_merge', false);
        $this->assertSame($canonical, $this->git($integration, ['rev-parse', 'HEAD']));
        $this->assertSame($task->base_revision, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertSame("Original dirty checkout\n", file_get_contents($repository.'/lesson.txt'));
        $this->assertSame($task->revision, $this->git($task->worktree_path, ['rev-parse', 'HEAD']));
        $this->assertCount(3, $task->refresh()->publication['checks']);
        $this->assertDatabaseHas('decisions', ['mission_task_id' => $task->id, 'action' => 'merge_staging', 'revision' => $task->revision]);
    }

    public function test_stale_revision_and_active_tasks_block_remote_operations(): void
    {
        [$task] = $this->fixture();
        $this->mock(GitHubProcess::class, fn ($mock) => $mock->shouldNotReceive('command', 'json'));

        $this->postJson(route('tasks.publish', $task), ['revision' => str_repeat('f', 40)])->assertUnprocessable()->assertJsonValidationErrors('publication');
        MissionTask::factory()->create(['status' => 'running']);
        $this->postJson(route('tasks.publish', $task), ['revision' => $task->revision])->assertUnprocessable()->assertJsonValidationErrors('publication');

        $this->assertNull($task->refresh()->publication);
    }

    public function test_historical_deployment_refresh_works_after_the_integration_moves_to_another_task(): void
    {
        [$task, , $integration] = $this->fixture(published: true);
        $mergedRevision = str_repeat('a', 40);
        $savedChecks = [['name' => 'Backend', 'state' => 'pass', 'url' => null]];
        $task->update(['publication' => [...$task->publication, 'state' => 'merged', 'merge_revision' => $mergedRevision, 'checks' => $savedChecks]]);
        config(['mission.base_branch' => 'next-task-base']);
        MissionTask::factory()->create(['status' => 'running']);
        $this->mock(GitHubProcess::class, function ($mock) use ($task, $mergedRevision): void {
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[0] === 'pr')->andReturn([...$this->pull($task), 'state' => 'MERGED', 'mergeCommit' => ['oid' => $mergedRevision]]);
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[0] === 'run')->andReturn([
                ['headSha' => str_repeat('b', 40), 'headBranch' => 'staging', 'status' => 'completed', 'conclusion' => 'success', 'url' => null],
                ['headSha' => $mergedRevision, 'headBranch' => 'staging', 'status' => 'in_progress', 'conclusion' => null, 'url' => 'https://github.com/example/project/actions/runs/42'],
            ]);
            $mock->shouldNotReceive('command');
        });

        $response = $this->postJson(route('tasks.publication.refresh', $task), ['revision' => $task->revision]);

        $response->assertOk()->assertJsonPath('publication.state', 'merged')->assertJsonPath('publication.deployment.state', 'running')->assertJsonPath('publication.deployment.revision', $mergedRevision)->assertJsonPath('publication.integration_synced', false);
        $this->assertSame($savedChecks, $task->refresh()->publication['checks']);
        $this->assertSame($task->revision, $this->git($integration, ['rev-parse', 'HEAD']));
        $this->get(route('tasks.show', $task))->assertOk()->assertSee('Merged into staging')->assertSee('Running')->assertViewHas('publication', fn (array $status): bool => ! $status['can_publish'] && ! $status['can_merge']);
    }

    public function test_unpublished_page_capabilities_block_active_tasks_and_changed_base_settings(): void
    {
        [$task] = $this->fixture();

        $this->get(route('tasks.show', $task))->assertOk()->assertSee('Publish approved change')->assertViewHas('publication', fn (array $status): bool => $status['can_publish'] && ! $status['can_merge']);
        config(['mission.base_branch' => 'next-task-base']);
        MissionTask::factory()->create(['status' => 'running']);
        $this->get(route('tasks.show', $task))->assertOk()->assertViewHas('publication', fn (array $status): bool => ! $status['can_publish'] && count($status['blockers']) === 2);
    }

    public function test_staging_link_requires_a_successful_deployment_for_the_exact_merge_revision(): void
    {
        [$task] = $this->fixture(published: true);
        $revision = str_repeat('a', 40);
        $task->update(['publication' => [...$task->publication, 'state' => 'merged', 'merge_revision' => $revision, 'deployment' => ['state' => 'success', 'revision' => $revision, 'url' => null]]]);

        $this->get(route('tasks.show', $task))->assertOk()->assertSee('href="https://staging.project.example.com"', false);
        $task->update(['publication' => [...$task->publication, 'deployment' => ['state' => 'success', 'revision' => str_repeat('b', 40), 'url' => null]]]);
        $this->get(route('tasks.show', $task))->assertOk()->assertDontSee('href="https://staging.project.example.com"', false);
        $task->update(['publication' => [...$task->publication, 'deployment' => ['state' => 'pending', 'revision' => $revision, 'url' => null]]]);
        $this->get(route('tasks.show', $task))->assertOk()->assertDontSee('href="https://staging.project.example.com"', false);
    }

    public function test_a_github_merge_with_different_content_never_rewrites_the_integration_checkout(): void
    {
        [$task, $repository, $integration] = $this->fixture(published: true);
        $this->allowOrigin($task, $integration);
        file_put_contents($repository.'/unrelated.txt', 'New staging content');
        $this->git($repository, ['add', 'unrelated.txt']);
        $this->git($repository, ['commit', '-m', 'Different merged tree']);
        $canonical = $this->git($repository, ['rev-parse', 'HEAD']);
        $this->git($repository, ['push', 'origin', 'staging']);
        $this->mock(GitHubProcess::class, function ($mock) use ($task, $canonical): void {
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[0] === 'pr')->andReturn([...$this->pull($task), 'state' => 'MERGED', 'mergeCommit' => ['oid' => $canonical]]);
            $mock->shouldReceive('json')->once()->withArgs(fn ($repository, $args): bool => $args[0] === 'run')->andReturn([]);
        });

        $this->postJson(route('tasks.publication.refresh', $task), ['revision' => $task->revision])->assertOk()->assertJsonPath('publication.integration_synced', false);

        $this->assertSame($task->revision, $this->git($integration, ['rev-parse', 'HEAD']));
        $this->assertFileDoesNotExist($integration.'/unrelated.txt');
    }

    private function allowOrigin(MissionTask $task, string $integration, bool $push = false, string $pushUrl = 'https://github.com/example/project.git'): void
    {
        $this->partialMock(GitRepository::class, function ($mock) use ($task, $integration, $push, $pushUrl): void {
            $mock->shouldReceive('command')->with($integration, ['remote', 'get-url', 'origin'], true)->andReturn('https://github.com/example/project.git');
            $mock->shouldReceive('command')->with($integration, ['remote', 'get-url', '--push', '--all', 'origin'], true)->andReturn($pushUrl);
            if ($push) {
                $mock->shouldReceive('command')->once()->with($integration, ['push', '--porcelain', '--no-follow-tags', '--no-mirror', '--', 'https://github.com/example/project.git', $task->revision.':refs/heads/'.$task->branch], true)->andReturnUsing(fn (): string => $this->git($integration, ['push', 'origin', $task->revision.':refs/heads/'.$task->branch]));
            }
        });
    }

    private function pull(MissionTask $task): array
    {
        return ['number' => 12, 'url' => 'https://github.com/example/project/pull/12', 'state' => 'OPEN', 'isDraft' => false, 'headRefName' => $task->branch, 'headRefOid' => $task->revision, 'baseRefName' => 'staging', 'baseRefOid' => $task->base_revision, 'isCrossRepository' => false, 'mergeable' => 'MERGEABLE', 'mergeStateStatus' => 'CLEAN', 'mergeCommit' => null];
    }

    private function checks(): array
    {
        return [['name' => 'Backend', 'bucket' => 'pass', 'link' => null], ['name' => 'App', 'bucket' => 'pass', 'link' => null], ['name' => 'Lesson assets', 'bucket' => 'skipping', 'link' => null]];
    }

    private function fixture(bool $published = false): array
    {
        $root = storage_path('framework/testing/publication-'.Str::uuid());
        $this->directories[] = $root;
        $repository = $root.'/repository';
        $integration = $root.'/integration/project';
        $worktree = $root.'/worktrees/task';
        mkdir($repository, 0700, true);
        mkdir(dirname($integration), 0700, true);
        mkdir(dirname($worktree), 0700, true);
        $this->git($repository, ['init', '-b', 'staging']);
        $this->git($repository, ['config', 'user.email', 'test@example.test']);
        $this->git($repository, ['config', 'user.name', 'Mission Control Test']);
        file_put_contents($repository.'/lesson.txt', "Original lesson\n");
        $this->git($repository, ['add', '.']);
        $this->git($repository, ['commit', '-m', 'Initial lesson']);
        $base = $this->git($repository, ['rev-parse', 'HEAD']);
        $this->git($root, ['init', '--bare', '-b', 'staging', $root.'/origin.git']);
        $this->git($repository, ['remote', 'add', 'origin', $root.'/origin.git']);
        $this->git($repository, ['push', '-u', 'origin', 'staging']);
        $this->git($repository, ['worktree', 'add', '-b', 'integration-base', $integration, 'origin/staging']);
        $this->git($repository, ['worktree', 'add', '-b', 'mission/task-test', $worktree, $base]);
        file_put_contents($worktree.'/lesson.txt', "Available Languages\n");
        $this->git($worktree, ['add', '.']);
        $this->git($worktree, ['commit', '-m', 'Use available languages heading']);
        $revision = $this->git($worktree, ['rev-parse', 'HEAD']);
        $this->git($integration, ['merge', '--ff-only', $revision]);
        config(['mission.repository_path' => $integration, 'mission.base_branch' => 'integration-base', 'mission.worktree_root' => dirname($worktree), 'mission.integration_root' => dirname($integration)]);
        $result = ['success' => true, 'revision' => $revision, 'baseRevision' => $base, 'tests' => ['exitCode' => 0, 'command' => ['php', '-l', 'view.blade.php']], 'review' => ['verdict' => 'pass', 'summary' => 'The heading change is correct.']];
        $task = MissionTask::factory()->create(['status' => 'merged', 'request' => 'internal workflow instructions', 'recommendation' => 'The homepage section now says Available Languages.', 'revision' => $revision, 'base_revision' => $base, 'branch' => 'mission/task-test', 'worktree_path' => $worktree, 'review_data' => $result]);
        AgentRun::factory()->for($task, 'task')->create(['status' => 'completed', 'result' => $result, 'settings' => ['repository_path' => $repository, 'base_branch' => 'integration-base', 'preview_enabled' => false]]);
        foreach (['approve', 'merge'] as $action) {
            $task->decisions()->create(['action' => $action, 'revision' => $revision]);
        }
        if ($published) {
            $task->update(['publication' => ['repository' => 'example/project', 'state' => 'open', 'pr_number' => 12, 'pr_url' => 'https://github.com/example/project/pull/12', 'head_branch' => $task->branch, 'head_revision' => $revision, 'base_branch' => 'staging', 'base_revision' => $base]]);
        }

        return [$task, $repository, $integration];
    }

    private function git(string $path, array $arguments): string
    {
        $process = new Process(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', '-C', $path, ...$arguments]);
        $process->setTimeout(10);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
