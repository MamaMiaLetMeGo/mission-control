<?php

namespace Tests\Feature;

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use App\Models\Lead;
use App\Models\MissionTask;
use App\Services\CodexProcess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
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
}
