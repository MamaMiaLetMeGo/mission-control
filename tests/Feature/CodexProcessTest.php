<?php

namespace Tests\Feature;

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
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
}
