<?php

namespace Tests\Feature;

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\MissionTask;
use App\Services\CodexProcess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CodexProcessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_worker_results_preserve_task_paths_while_redacting_credentials(): void
    {
        $directory = storage_path('framework/testing/codex-process-'.Str::uuid());
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/fixture.php', <<<'PHP'
<?php
stream_get_contents(STDIN);
$attempt = 'task-3-4acba984-1481-4f8a-9b34-5f56a65e800c';
echo json_encode(['type' => 'message', 'message' => 'Starting '.$attempt.' with '.getenv('OPENAI_API_KEY')])."\n";
echo json_encode([
    'type' => 'result',
    'success' => false,
    'branch' => 'mission/'.$attempt,
    'worktreePath' => '/storage/app/worktrees/'.$attempt,
    'artifactPath' => '/storage/app/artifacts/'.$attempt,
    'error' => 'Failure in '.$attempt.': '.getenv('OPENAI_API_KEY').' and sk-proj-abcdefghijklmnopqrstuvwxyz',
])."\n";
PHP);
        config([
            'mission.openai_api_key' => 'configured-private-test-key',
            'mission.node_binary' => PHP_BINARY,
            'mission.worker_path' => $directory.'/fixture.php',
        ]);
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create([
            'settings' => ['repository_path' => '/fixture/repository', 'base_branch' => 'main', 'test_command' => ['php', '-v'], 'max_runtime_seconds' => 60, 'max_tokens' => 1000],
        ]);

        try {
            (new RunMissionTask($run->id))->handle(app(CodexProcess::class));

            $run->refresh();
            $this->assertSame('failed', $run->status);
            $this->assertSame('mission/task-3-4acba984-1481-4f8a-9b34-5f56a65e800c', $run->task->branch);
            $this->assertSame('/storage/app/worktrees/task-3-4acba984-1481-4f8a-9b34-5f56a65e800c', $run->task->worktree_path);
            $this->assertSame('/storage/app/artifacts/task-3-4acba984-1481-4f8a-9b34-5f56a65e800c', $run->task->artifact_path);
            $this->assertSame('Failure in task-3-4acba984-1481-4f8a-9b34-5f56a65e800c: [redacted] and [redacted]', $run->error);
            $this->assertSame([['type' => 'message', 'message' => 'Starting task-3-4acba984-1481-4f8a-9b34-5f56a65e800c with [redacted]']], $run->events);
            $this->assertSame($run->result, $run->task->review_data);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_the_tasks_lead_is_sent_to_the_worker(): void
    {
        $directory = storage_path('framework/testing/codex-process-'.Str::uuid());
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/fixture.php', <<<'PHP'
<?php
file_put_contents(__DIR__.'/payload.json', stream_get_contents(STDIN));
echo json_encode(['type' => 'result', 'success' => false, 'error' => 'stopped'])."\n";
PHP);
        config(['mission.node_binary' => PHP_BINARY, 'mission.worker_path' => $directory.'/fixture.php']);
        $settings = ['repository_path' => '/fixture/repository', 'base_branch' => 'main', 'test_command' => ['php', '-v'], 'max_runtime_seconds' => 60, 'max_tokens' => 1000];
        $designLead = Lead::query()->where('key', 'design')->firstOrFail();
        $withLead = AgentRun::factory()->for(MissionTask::factory()->for($designLead)->state(['status' => 'queued']), 'task')->create(['settings' => $settings]);
        $withoutLead = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued', 'lead_id' => null]), 'task')->create(['settings' => $settings]);

        try {
            app(CodexProcess::class)->run($withLead, fn (array $event) => null);
            $payload = json_decode(file_get_contents($directory.'/payload.json'), true);
            $this->assertSame(['name' => 'Design Lead', 'instructions' => $designLead->instructions], $payload['lead']);

            app(CodexProcess::class)->run($withoutLead, fn (array $event) => null);
            $this->assertArrayNotHasKey('lead', json_decode(file_get_contents($directory.'/payload.json'), true));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_refresh_runs_send_the_reviewed_revision_and_summary_to_the_worker(): void
    {
        $directory = storage_path('framework/testing/codex-process-'.Str::uuid());
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/fixture.php', <<<'PHP'
<?php
file_put_contents(__DIR__.'/payload.json', stream_get_contents(STDIN));
echo json_encode(['type' => 'result', 'success' => false, 'error' => 'stopped'])."\n";
PHP);
        config(['mission.node_binary' => PHP_BINARY, 'mission.worker_path' => $directory.'/fixture.php']);
        $settings = ['repository_path' => '/fixture/repository', 'base_branch' => 'main', 'test_command' => ['php', '-v'], 'max_runtime_seconds' => 60, 'max_tokens' => 1000];
        $refresh = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create(['settings' => [...$settings, 'mode' => 'refresh', 'source_revision' => str_repeat('a', 40), 'previous_summary' => 'Improved the lesson.']]);
        $implement = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create(['settings' => $settings]);

        try {
            app(CodexProcess::class)->run($refresh, fn (array $event) => null);
            $payload = json_decode(file_get_contents($directory.'/payload.json'), true);
            $this->assertSame('refresh', $payload['mode']);
            $this->assertSame(str_repeat('a', 40), $payload['sourceRevision']);
            $this->assertSame('Improved the lesson.', $payload['previousSummary']);

            app(CodexProcess::class)->run($implement, fn (array $event) => null);
            $payload = json_decode(file_get_contents($directory.'/payload.json'), true);
            $this->assertArrayNotHasKey('mode', $payload);
            $this->assertArrayNotHasKey('sourceRevision', $payload);
            $this->assertArrayNotHasKey('previousSummary', $payload);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_planning_sends_the_goal_and_active_leads_and_returns_the_plan_event(): void
    {
        $directory = storage_path('framework/testing/codex-process-'.Str::uuid());
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/fixture.php', <<<'PHP'
<?php
file_put_contents(__DIR__.'/payload.json', stream_get_contents(STDIN));
echo json_encode(['type' => 'message', 'message' => 'Reading.'])."\n";
echo json_encode(['type' => 'plan', 'success' => true, 'overview' => 'Plan.', 'briefs' => []])."\n";
PHP);
        config(['mission.node_binary' => PHP_BINARY, 'mission.worker_path' => $directory.'/fixture.php', 'mission.worktree_root' => '/fixture/worktrees']);
        Lead::query()->where('key', 'design')->update(['is_active' => false]);
        $settings = ['repository_path' => '/fixture/repository', 'base_branch' => 'main', 'max_runtime_seconds' => 60, 'max_tokens' => 1000, 'model' => 'test-model'];
        $goal = Goal::factory()->queued()->create(['request' => 'Improve onboarding.', 'settings' => $settings]);
        $events = [];

        try {
            $result = app(CodexProcess::class)->plan($goal, function (array $event) use (&$events): void {
                $events[] = $event;
            });

            $payload = json_decode(file_get_contents($directory.'/payload.json'), true);
            $this->assertSame('plan', $payload['mode']);
            $this->assertSame($goal->id, $payload['goalId']);
            $this->assertSame('Improve onboarding.', $payload['goal']);
            $this->assertSame('/fixture/repository', $payload['repositoryPath']);
            $this->assertSame('main', $payload['baseBranch']);
            $this->assertSame('/fixture/worktrees', $payload['worktreeRoot']);
            $this->assertSame(60, $payload['maxRuntimeSeconds']);
            $this->assertSame(1000, $payload['maxTokens']);
            $this->assertSame('test-model', $payload['model']);
            $expected = Lead::query()->active()->get()->map(fn (Lead $lead): array => ['key' => $lead->key, 'name' => $lead->name, 'instructions' => $lead->instructions])->all();
            $this->assertSame($expected, $payload['leads']);
            $this->assertNotContains('design', array_column($payload['leads'], 'key'));
            $this->assertSame('plan', $result['type']);
            $this->assertSame('Plan.', $result['overview']);
            $this->assertSame([['type' => 'message', 'message' => 'Reading.']], $events);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_planning_without_a_plan_event_fails_with_the_worker_output(): void
    {
        $directory = storage_path('framework/testing/codex-process-'.Str::uuid());
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/fixture.php', <<<'PHP'
<?php
stream_get_contents(STDIN);
echo json_encode(['type' => 'result', 'success' => true])."\n";
fwrite(STDERR, 'boom');
PHP);
        config(['mission.node_binary' => PHP_BINARY, 'mission.worker_path' => $directory.'/fixture.php']);
        $goal = Goal::factory()->queued()->create(['settings' => ['repository_path' => '/r', 'base_branch' => 'main', 'max_runtime_seconds' => 60, 'max_tokens' => 1000]]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The planner stopped without a plan. boom');
            app(CodexProcess::class)->plan($goal, fn (array $event) => null);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
