<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\MissionTask;
use App\Services\CodexProcess;
use App\Services\GitHubProcess;
use App\Services\GitRepository;
use App\Services\MissionPreview;
use App\Services\PreviewProcess;
use App\Services\RepositorySync;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MissionProgressTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_same_run_progress_updates_events_then_delivers_the_complete_review_package(): void
    {
        $this->freezeTime();
        $this->forbidExternalWork();
        config(['mission.openai_api_key' => 'private-configuration-key']);
        $task = MissionTask::factory()->create(['status' => 'queued']);
        $run = AgentRun::factory()->for($task, 'task')->create(['settings' => ['preview_enabled' => true, 'preview_paths' => ['/'], 'openai_api_key' => 'private-run-key']]);
        $url = route('tasks.progress', ['task' => $task, 'run_id' => $run->id]);

        $this->getJson($url)->assertOk()->assertJsonPath('status', 'queued')->assertJsonPath('run_id', $run->id)->assertJsonPath('active', true)->assertJsonPath('revision', null);
        DB::transaction(function () use ($task, $run): void {
            $run->update(['status' => 'running', 'started_at' => now(), 'events' => [['type' => 'phase', 'message' => 'Implementing the requested change']]]);
            $task->update(['status' => 'running']);
        });
        $response = $this->getJson($url)->assertOk()->assertJsonPath('status', 'running');
        $this->assertStringContainsString('Implementing the requested change', $response->json('html'));
        $unchangedTaskTime = $task->fresh()->updated_at->toISOString();
        $this->travel(2)->seconds();
        $run->update(['events' => [['type' => 'phase', 'message' => 'Independent review in progress']]]);
        $response = $this->getJson($url)->assertOk();
        $this->assertStringContainsString('Independent review in progress', $response->json('html'));
        $this->assertSame($unchangedTaskTime, $task->fresh()->updated_at->toISOString());
        $result = $this->reviewResult();
        DB::transaction(function () use ($task, $run, $result): void {
            $run->update(['status' => 'completed', 'result' => $result, 'finished_at' => now()]);
            $task->update(['status' => 'review', 'revision' => $result['revision'], 'base_revision' => $result['baseRevision'], 'review_data' => $result, 'recommendation' => 'Available Languages is ready', 'artifact_path' => storage_path('app/artifacts/test')]);
        });
        $before = $task->fresh()->toArray();
        $response = $this->getJson($url);

        $response->assertOk()->assertJsonPath('active', false)->assertJsonPath('status', 'review')->assertJsonPath('revision', $result['revision'])->assertJsonPath('poll_after_ms', 5000)->assertJsonMissingPath('settings');
        $html = $response->json('html');
        $this->assertStringContainsString('Available Languages is ready', $html);
        $this->assertStringContainsString('Approve', $html);
        $this->assertStringContainsString('name="revision" value="'.$result['revision'].'"', $html);
        $this->assertStringContainsString('screenshots/after-mobile-0.png', $html);
        $this->assertStringContainsString('Test output', $html);
        $this->assertStringContainsString('Review record', $html);
        $this->assertStringNotContainsString('private-configuration-key', $response->getContent());
        $this->assertStringNotContainsString('private-run-key', $response->getContent());
        $this->assertSame($before, $task->fresh()->toArray());
        $this->assertSame(0, $task->decisions()->count());
        Queue::assertNothingPushed();
    }

    public function test_interrupted_run_arrives_as_failed_with_its_error_without_a_review_package(): void
    {
        $this->forbidExternalWork();
        $task = MissionTask::factory()->create(['status' => 'failed', 'error' => 'Worker interrupted <script>unsafe()</script>']);
        $run = AgentRun::factory()->for($task, 'task')->create(['status' => 'failed', 'error' => 'Worker interrupted', 'finished_at' => now()]);

        $response = $this->getJson(route('tasks.progress', ['task' => $task, 'run_id' => $run->id]));

        $response->assertOk()->assertJsonPath('active', false)->assertJsonPath('status', 'failed')->assertJsonPath('revision', null);
        $this->assertStringContainsString('Worker interrupted', $response->json('html'));
        $this->assertStringContainsString('&lt;script&gt;', $response->json('html'));
        $this->assertStringNotContainsString('<script>unsafe()', $response->json('html'));
        $this->assertNull($task->fresh()->review_data);
        Queue::assertNothingPushed();
    }

    public function test_a_newer_run_requires_reload_instead_of_switching_the_review_identity(): void
    {
        $this->forbidExternalWork();
        $task = MissionTask::factory()->create(['status' => 'running']);
        $old = AgentRun::factory()->for($task, 'task')->create(['status' => 'failed']);
        AgentRun::factory()->for($task, 'task')->create(['status' => 'running']);

        $this->getJson(route('tasks.progress', ['task' => $task, 'run_id' => $old->id]))->assertStatus(409)->assertJsonPath('reload', true)->assertJsonMissingPath('html');

        $this->assertSame('running', $task->fresh()->status);
        Queue::assertNothingPushed();
    }

    #[TestWith(['approved', 'completed'])]
    #[TestWith(['merged', 'completed'])]
    #[TestWith(['changes_requested', 'completed'])]
    #[TestWith(['running', 'queued'])]
    public function test_unexpected_decisions_or_inconsistent_run_states_require_reload(string $status, string $runStatus): void
    {
        $this->forbidExternalWork();
        $task = MissionTask::factory()->create(['status' => $status]);
        $run = AgentRun::factory()->for($task, 'task')->create(['status' => $runStatus]);

        $this->getJson(route('tasks.progress', ['task' => $task, 'run_id' => $run->id]))->assertStatus(409)->assertJsonPath('reload', true)->assertJsonMissingPath('html');
        Queue::assertNothingPushed();
    }

    public function test_review_evidence_from_a_different_result_is_not_displayed_as_the_same_run(): void
    {
        $this->forbidExternalWork();
        $result = $this->reviewResult();
        $task = MissionTask::factory()->create(['status' => 'review', 'revision' => $result['revision'], 'review_data' => [...$result, 'summary' => 'Different result']]);
        $run = AgentRun::factory()->for($task, 'task')->create(['status' => 'completed', 'result' => $result]);

        $this->getJson(route('tasks.progress', ['task' => $task, 'run_id' => $run->id]))->assertStatus(409)->assertJsonPath('reload', true)->assertJsonMissingPath('html');
        Queue::assertNothingPushed();
    }

    public function test_progress_requires_an_integer_run_id_and_a_real_task(): void
    {
        $this->forbidExternalWork();
        $task = MissionTask::factory()->create();

        $this->getJson(route('tasks.progress', $task))->assertUnprocessable()->assertJsonValidationErrors('run_id');
        $this->getJson(route('tasks.progress', ['task' => $task, 'run_id' => 'not-an-id']))->assertUnprocessable()->assertJsonValidationErrors('run_id');
        $this->getJson(route('tasks.progress', ['task' => 99999, 'run_id' => 1]))->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_queue_poll_preserves_filters_pagination_and_global_counts_without_external_work(): void
    {
        $this->forbidExternalWork();
        foreach (range(1, 16) as $index) {
            MissionTask::factory()->create(['title' => sprintf('Target %02d', $index), 'status' => 'review', 'area' => 'quality']);
        }
        MissionTask::factory()->create(['title' => 'Target wrong area', 'status' => 'review', 'area' => 'engineering']);
        MissionTask::factory()->create(['title' => 'Target failed', 'status' => 'failed', 'area' => 'quality']);
        MissionTask::factory()->create(['title' => 'Other backlog']);
        $response = $this->getJson(route('queue.status', ['q' => 'Target', 'status' => 'review', 'area' => 'quality', 'page' => 2]));

        $response->assertOk()->assertJsonPath('counts.review', 17)->assertJsonPath('counts.failed', 1)->assertJsonPath('counts.backlog', 1)->assertJsonPath('active', true)->assertJsonPath('poll_after_ms', 15000)->assertJsonMissingPath('settings');
        $html = $response->json('html.queue');
        $this->assertStringContainsString('Target 01', $html);
        $this->assertStringNotContainsString('Target 02', $html);
        $this->assertStringNotContainsString('Target wrong area', $html);
        $this->assertStringNotContainsString('Target failed', $html);
        $this->assertStringContainsString('status=review', $html);
        $this->assertStringContainsString('area=quality', $html);
        $this->assertStringContainsString('q=Target', $html);
        $this->assertStringContainsString('page=1', $html);
        $this->assertStringNotContainsString('/queue/status?', $html);
        $this->assertDatabaseCount('mission_tasks', 19);
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_empty_selected_page_retains_navigation_when_filter_results_shrink(): void
    {
        $this->forbidExternalWork();
        MissionTask::factory()->count(15)->create(['status' => 'review']);

        $response = $this->getJson(route('queue.status', ['status' => 'review', 'page' => 2]))->assertOk()->assertJsonPath('counts.review', 15);

        $this->assertStringContainsString('page=1', $response->json('html.queue'));
        Queue::assertNothingPushed();
    }

    public function test_queue_escapes_titles_and_preserves_status_priority_order(): void
    {
        $this->forbidExternalWork();
        MissionTask::factory()->create(['title' => 'Review urgent', 'status' => 'review', 'priority' => 'urgent']);
        MissionTask::factory()->create(['title' => '<script>failure()</script>', 'status' => 'failed']);
        MissionTask::factory()->create(['title' => 'Running high', 'status' => 'running', 'priority' => 'high']);
        MissionTask::factory()->create(['title' => 'Backlog normal']);

        $response = $this->getJson(route('queue.status'))->assertOk()->assertJsonPath('poll_after_ms', 5000);
        $html = $response->json('html.queue');

        $this->assertStringContainsString('&lt;script&gt;failure()', $html);
        $this->assertStringNotContainsString('<script>failure()', $html);
        $this->assertLessThan(strpos($html, 'Review urgent'), strpos($html, '&lt;script&gt;failure()'));
        $this->assertLessThan(strpos($html, 'Running high'), strpos($html, 'Review urgent'));
        $this->assertLessThan(strpos($html, 'Backlog normal'), strpos($html, 'Running high'));
        Queue::assertNothingPushed();
    }

    public function test_queue_rejects_invalid_filters_and_nonlocal_requests(): void
    {
        $this->forbidExternalWork();

        $this->getJson(route('queue.status', ['status' => 'unexpected', 'area' => 'other', 'q' => str_repeat('x', 201), 'page' => 0]))->assertUnprocessable()->assertJsonValidationErrors(['status', 'area', 'q', 'page']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->getJson(route('queue.status'))->assertForbidden();
        Queue::assertNothingPushed();
    }

    private function forbidExternalWork(): void
    {
        Queue::fake();
        $this->mock(GitRepository::class)->shouldNotReceive('command', 'rawCommand', 'assertReviewedRevision', 'fetchUpstream');
        $this->mock(GitHubProcess::class)->shouldNotReceive('command', 'json');
        $this->mock(RepositorySync::class)->shouldNotReceive('status', 'check', 'sync');
        $this->mock(MissionPreview::class)->shouldNotReceive('status', 'start', 'stop');
        $this->mock(PreviewProcess::class)->shouldNotReceive('execute');
        $this->mock(CodexProcess::class)->shouldNotReceive('run');
    }

    private function reviewResult(): array
    {
        $revision = str_repeat('a', 40);
        $base = str_repeat('b', 40);
        $screenshots = [];
        foreach (['before', 'after'] as $phase) {
            foreach (['desktop', 'mobile'] as $viewport) {
                $screenshots[] = ['file' => $phase.'-'.$viewport.'-0.png', 'phase' => $phase, 'viewport' => $viewport, 'path' => '/', 'width' => 390, 'height' => 844, 'revision' => $phase === 'before' ? $base : $revision, 'sha256' => str_repeat('c', 64)];
            }
        }

        return ['success' => true, 'revision' => $revision, 'baseRevision' => $base, 'summary' => 'Available Languages is ready', 'tests' => ['command' => ['php', '-l', 'heading.php'], 'exitCode' => 0, 'output' => 'No syntax errors'], 'review' => ['verdict' => 'pass', 'summary' => 'The requested change is complete', 'findings' => []], 'browser' => ['status' => 'passed', 'revision' => $revision, 'baseRevision' => $base, 'screenshots' => $screenshots]];
    }
}
