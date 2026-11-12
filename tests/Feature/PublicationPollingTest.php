<?php

namespace Tests\Feature;

use App\Models\MissionTask;
use App\Services\GitHubProcess;
use App\Services\GitRepository;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PublicationPollingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_poll_refreshes_checks_and_throttles_across_requests_without_remote_actions(): void
    {
        $this->freezeTime();
        $task = $this->publishedTask();
        $this->mock(GitRepository::class)->shouldNotReceive('command');
        $this->mock(GitHubProcess::class, function ($mock) use ($task): void {
            $mock->shouldReceive('json')->twice()->withArgs(fn ($repo, $args): bool => $args[1] === 'view')->andReturn($this->pull($task));
            $mock->shouldReceive('json')->twice()->withArgs(fn ($repo, $args): bool => $args[1] === 'checks')->andReturn([['name' => 'Backend', 'bucket' => 'pass']]);
            $mock->shouldNotReceive('command');
        });

        $first = $this->postJson(route('tasks.publications.poll', $task), $this->payload($task));
        $first->assertOk()->assertJsonPath('busy', false)->assertJsonPath('polling.staging.should_poll', true)->assertJsonPath('polling.staging.poll_after_ms', 15000)->assertJsonPath('revision', $task->revision);
        $this->assertStringContainsString('Passed', $first->json('panels.staging'));
        $this->postJson(route('tasks.publications.poll', $task), $this->payload($task))->assertOk();
        $this->travel(16)->seconds();
        $this->postJson(route('tasks.publications.poll', $task), $this->payload($task))->assertOk();

        $this->assertSame('ready', $task->fresh()->publication['state']);
        $this->assertSame(0, $task->decisions()->count());
    }

    public function test_automatic_staging_merge_status_never_reconciles_git_and_enables_production_planning(): void
    {
        $task = $this->publishedTask();
        $merge = str_repeat('c', 40);
        $this->mock(GitRepository::class)->shouldNotReceive('command');
        $this->mock(GitHubProcess::class, function ($mock) use ($task, $merge): void {
            $mock->shouldReceive('json')->once()->withArgs(fn ($repo, $args): bool => $args[0] === 'pr')->andReturn([...$this->pull($task), 'state' => 'MERGED', 'mergeCommit' => ['oid' => $merge]]);
            $mock->shouldReceive('json')->once()->withArgs(fn ($repo, $args): bool => $args[0] === 'run' && in_array($merge, $args, true) && in_array('staging', $args, true))->andReturn([['headSha' => $merge, 'headBranch' => 'staging', 'status' => 'completed', 'conclusion' => 'success']]);
            $mock->shouldNotReceive('command');
        });

        $response = $this->postJson(route('tasks.publications.poll', $task), $this->payload($task));

        $response->assertOk()->assertJsonPath('polling.staging.should_poll', false)->assertJsonPath('polling.production.published', false);
        $this->assertStringContainsString('Plan release', $response->json('panels.production'));
        $this->assertStringContainsString('Open staging', $response->json('panels.staging'));
        $this->assertArrayNotHasKey('integration_synced', $task->fresh()->publication);
        $this->assertSame(1, $task->decisions()->where('action', 'merge_staging')->count());
        $this->assertSame(0, $task->decisions()->where('action', 'merge_production')->count());
    }

    public function test_busy_workflow_returns_cached_panels_without_waiting_or_github_reads(): void
    {
        $task = $this->publishedTask();
        $before = $task->publication;
        $this->mock(GitHubProcess::class)->shouldNotReceive('json', 'command');
        $lock = Cache::lock('mission-workflow', 900);
        $lock->get();
        try {
            $this->postJson(route('tasks.publications.poll', $task), $this->payload($task))->assertOk()->assertJsonPath('busy', true)->assertJsonPath('polling.staging.should_poll', true);
            $this->assertTrue($lock->isOwnedByCurrentProcess());
            $this->assertSame($before, $task->fresh()->publication);
        } finally {
            $lock->release();
        }
    }

    public function test_github_error_keeps_existing_evidence_and_retries_with_backoff(): void
    {
        $this->freezeTime();
        $task = $this->publishedTask();
        $this->mock(GitHubProcess::class, function ($mock): void {
            $mock->shouldReceive('json')->twice()->andThrow(ValidationException::withMessages(['publication' => 'GitHub timed out. Try again.']));
            $mock->shouldNotReceive('command');
        });

        $this->postJson(route('tasks.publications.poll', $task), $this->payload($task))->assertOk()->assertJsonPath('polling.staging.error', 'GitHub timed out. Try again.')->assertJsonPath('polling.staging.poll_after_ms', 60000)->assertJsonPath('polling.staging.should_poll', true);
        $this->travel(16)->seconds();
        $this->postJson(route('tasks.publications.poll', $task), $this->payload($task))->assertOk();
        $this->travel(45)->seconds();
        $this->postJson(route('tasks.publications.poll', $task), $this->payload($task))->assertOk();

        $this->assertSame($task->revision, $task->fresh()->publication['head_revision']);
        $this->assertNull($task->publication['last_checked_at'] ?? null);
    }

    #[TestWith(['revision'])]
    #[TestWith(['plan'])]
    #[TestWith(['new_plan'])]
    public function test_stale_page_identity_returns_409_without_remote_reads_or_new_action_fragments(string $changed): void
    {
        $task = $this->publishedTask();
        $payload = $this->payload($task);
        if ($changed === 'revision') {
            $task->update(['revision' => str_repeat('e', 40)]);
        } else {
            $task->update(['production_publication' => ['plan' => ['hash' => str_repeat('e', 64)]]]);
            if ($changed === 'plan') {
                $payload['plan_hash'] = str_repeat('d', 64);
            }
        }
        $this->mock(GitHubProcess::class)->shouldNotReceive('json', 'command');

        $this->postJson(route('tasks.publications.poll', $task), $payload)->assertStatus(409)->assertJsonPath('reload', true)->assertJsonMissingPath('panels');
    }

    #[TestWith(['closed', null, null, false, 15000])]
    #[TestWith(['ready', null, null, true, 15000])]
    #[TestWith(['checks_failed', null, null, true, 15000])]
    #[TestWith(['merged', 'failure', 'matching', true, 60000])]
    #[TestWith(['merged', 'success', 'matching', false, 15000])]
    #[TestWith(['merged', 'success', 'different', true, 15000])]
    public function test_cached_schedule_continues_until_exact_deployment_success_or_closed_pr(string $state, ?string $deployment, ?string $identity, bool $continue, int $delay): void
    {
        $task = $this->publishedTask();
        $task->update(['publication' => [...$task->publication, 'state' => $state, 'merge_revision' => str_repeat('c', 40), 'deployment' => ['state' => $deployment, 'revision' => str_repeat($identity === 'matching' ? 'c' : 'd', 40)]]]);
        $this->mock(GitHubProcess::class)->shouldNotReceive('json', 'command');
        $lock = Cache::lock('mission-workflow', 900);
        $lock->get();
        try {
            $this->postJson(route('tasks.publications.poll', $task), $this->payload($task))->assertOk()->assertJsonPath('polling.staging.should_poll', $continue)->assertJsonPath('polling.staging.poll_after_ms', $delay);
        } finally {
            $lock->release();
        }
    }

    public function test_unpublished_records_and_normal_page_render_make_no_github_requests(): void
    {
        $task = $this->publishedTask();
        $this->mock(GitHubProcess::class)->shouldNotReceive('json', 'command');

        $this->get(route('tasks.show', $task))->assertOk()->assertViewHas('publicationPolling', fn (array $metadata): bool => $metadata['staging']['published']);
        $task->update(['publication' => null]);
        $this->get(route('tasks.show', $task))->assertOk();
        $this->postJson(route('tasks.publications.poll', $task), $this->payload($task))->assertOk()->assertJsonPath('polling.staging.published', false)->assertJsonPath('polling.staging.should_poll', false);
        $this->assertNull($task->fresh()->publication);
    }

    public function test_poll_validates_input_and_keeps_local_origin_boundary(): void
    {
        $task = $this->publishedTask();
        $this->mock(GitHubProcess::class)->shouldNotReceive('json', 'command');

        $this->postJson(route('tasks.publications.poll', $task), ['environment' => 'publish', 'revision' => 'not-a-sha'])->assertUnprocessable()->assertJsonValidationErrors(['environment', 'revision', 'plan_hash']);
        $this->postJson(route('tasks.publications.poll', $task), $this->payload($task), ['Origin' => 'https://other.example'])->assertForbidden();
    }

    #[TestWith(['tasks.publication.refresh', 'publication'])]
    #[TestWith(['tasks.production.refresh', 'production'])]
    public function test_manual_action_during_a_long_refresh_returns_a_clear_busy_error(string $route, string $field): void
    {
        $task = $this->publishedTask();
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')->once()->andReturn($lock);
        $this->mock(GitHubProcess::class)->shouldNotReceive('json', 'command');

        $this->postJson(route($route, $task), ['revision' => $task->revision, 'plan_hash' => str_repeat('b', 64)])->assertUnprocessable()->assertJsonValidationErrors($field)->assertSee('Wait a moment');
    }

    private function publishedTask(): MissionTask
    {
        $revision = str_repeat('a', 40);

        return MissionTask::factory()->create([
            'status' => 'merged', 'revision' => $revision, 'base_revision' => str_repeat('b', 40), 'branch' => 'mission/test',
            'publication' => ['state' => 'checks_pending', 'repository' => config('mission.staging_repository'), 'pr_number' => 12, 'pr_url' => 'https://github.com/'.config('mission.staging_repository').'/pull/12', 'head_revision' => $revision, 'head_branch' => 'mission/test', 'base_branch' => 'staging'],
        ]);
    }

    private function payload(MissionTask $task): array
    {
        return ['environment' => 'staging', 'revision' => $task->revision, 'plan_hash' => null];
    }

    private function pull(MissionTask $task): array
    {
        return ['number' => 12, 'url' => $task->publication['pr_url'], 'state' => 'OPEN', 'headRefName' => $task->branch, 'headRefOid' => $task->revision, 'baseRefName' => 'staging', 'baseRefOid' => $task->base_revision, 'isCrossRepository' => false, 'isDraft' => false, 'mergeStateStatus' => 'CLEAN', 'mergeable' => 'MERGEABLE'];
    }
}
