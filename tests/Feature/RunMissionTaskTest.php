<?php

namespace Tests\Feature;

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use App\Models\MissionTask;
use App\Services\CodexProcess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RunMissionTaskTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_completed_run_retains_evidence_and_waits_for_human_review(): void
    {
        $this->freezeTime();
        config(['mission.openai_api_key' => 'test-key']);
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create();
        $result = ['type' => 'result', 'success' => true, 'summary' => 'Improved the lesson controls.', 'revision' => str_repeat('a', 40), 'baseRevision' => str_repeat('b', 40), 'review' => ['verdict' => 'pass'], 'tests' => ['exitCode' => 0], 'usage' => ['input_tokens' => 25]];
        $worker = $this->mock(CodexProcess::class, function ($mock) use ($result): void {
            $mock->shouldReceive('run')->once()->andReturnUsing(function ($run, $callback) use ($result) {
                $callback(['type' => 'thread', 'threadId' => 'thread-123', 'role' => 'implementation']);

                return $result;
            });
        });

        (new RunMissionTask($run->id))->handle($worker);

        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame('thread-123', $run->thread_id);
        $this->assertSame($result, $run->result);
        $this->assertSame('review', $run->task->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_a_failed_verification_never_enters_the_review_queue(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create();
        $worker = $this->mock(CodexProcess::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturn(['success' => true, 'tests' => ['exitCode' => 1], 'review' => ['verdict' => 'pass']]);
        });

        (new RunMissionTask($run->id))->handle($worker);

        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame('failed', $run->task->status);
    }

    public function test_a_duplicate_delivery_does_not_repeat_an_agent_run(): void
    {
        $run = AgentRun::factory()->create(['status' => 'completed']);
        $worker = $this->mock(CodexProcess::class, fn ($mock) => $mock->shouldNotReceive('run'));

        (new RunMissionTask($run->id))->handle($worker);

        $this->assertSame('completed', $run->refresh()->status);
    }

    public function test_worker_failure_redacts_credentials_from_persisted_errors(): void
    {
        config(['mission.openai_api_key' => 'private-test-credential']);
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'running']), 'task')->create(['status' => 'running']);

        (new RunMissionTask($run->id))->failed(new RuntimeException('Authentication failed: private-test-credential'));

        $this->assertSame('Authentication failed: [redacted]', $run->refresh()->error);
        $this->assertSame('failed', $run->task->status);
    }

    public function test_an_overlapping_delivery_does_not_fail_the_active_run(): void
    {
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create();
        $lock = Cache::lock('mission-execution', 60);
        $lock->get();
        $worker = $this->mock(CodexProcess::class, fn ($mock) => $mock->shouldNotReceive('run'));

        try {
            (new RunMissionTask($run->id))->handle($worker);

            $this->assertSame('queued', $run->refresh()->status);
            $this->assertSame('queued', $run->task->status);
        } finally {
            $lock->release();
        }
    }

    public function test_a_late_result_cannot_replace_a_recovered_run(): void
    {
        config(['mission.openai_api_key' => 'test-key']);
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create();
        $worker = $this->mock(CodexProcess::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturnUsing(function ($run) {
                $run->update(['status' => 'failed', 'error' => 'Previously recovered']);
                $run->task->update(['status' => 'failed']);

                return ['success' => true, 'tests' => ['exitCode' => 0], 'review' => ['verdict' => 'pass']];
            });
        });

        (new RunMissionTask($run->id))->handle($worker);

        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame('Previously recovered', $run->error);
        $this->assertSame('failed', $run->task->status);
    }

    public function test_recovery_never_changes_a_stale_run_with_a_live_worker_pid(): void
    {
        $this->freezeTime();
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'running']), 'task')->create(['status' => 'running', 'updated_at' => now()->subMinutes(30), 'settings' => ['repository_path' => base_path()]]);
        $root = storage_path('framework/testing/recovery-'.Str::uuid());
        $directory = $root.'/.locks/'.str_repeat('a', 64);
        mkdir($directory, 0700, true);
        $ownerFile = $directory.'/owner.json';
        file_put_contents($ownerFile, json_encode(['taskId' => $run->mission_task_id, 'repositoryPath' => base_path(), 'pid' => getmypid()]));
        config(['mission.worktree_root' => $root]);

        try {
            $this->artisan('mission:recover')->assertExitCode(0);

            $this->assertSame('running', $run->refresh()->status);
            $this->assertSame('running', $run->task->status);
            $this->assertFileExists($ownerFile);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_recovery_fails_only_stale_runs_and_does_not_retry(): void
    {
        $this->freezeTime();
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'running']), 'task')->create(['status' => 'running', 'updated_at' => now()->subMinutes(30)]);
        $fresh = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'running']), 'task')->create(['status' => 'running']);

        $this->artisan('mission:recover')->assertExitCode(0);

        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame('running', $fresh->refresh()->status);
        $this->assertDatabaseCount('agent_runs', 2);
    }
}
