<?php

namespace Tests\Feature;

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use App\Models\MissionTask;
use App\Services\CodexProcess;
use App\Services\RunSlots;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        config(['mission.max_parallel_runs' => 1]);
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create();
        $lock = Cache::lock('mission-execution:1', 60);
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

    public function test_a_run_waits_when_every_slot_is_busy(): void
    {
        config(['mission.openai_api_key' => 'test-key', 'mission.max_parallel_runs' => 1]);
        $held = app(RunSlots::class)->acquire(60);
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create();
        $worker = $this->mock(CodexProcess::class, fn ($mock) => $mock->shouldNotReceive('run'));
        $job = (new RunMissionTask($run->id))->withFakeQueueInteractions();

        $job->handle($worker);

        $job->assertReleased(15);
        $job->assertNotFailed();
        $this->assertSame('queued', $run->refresh()->status);
        $this->assertSame('queued', $run->task->status);
        $held->release();
    }

    public function test_two_runs_execute_side_by_side(): void
    {
        config(['mission.openai_api_key' => 'test-key', 'mission.max_parallel_runs' => 2]);
        $held = Cache::lock('mission-execution:1', 60);
        $this->assertTrue($held->get());
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create();
        $worker = $this->mock(CodexProcess::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturn(['success' => true, 'tests' => ['exitCode' => 0], 'review' => ['verdict' => 'pass']]);
        });
        $job = (new RunMissionTask($run->id))->withFakeQueueInteractions();

        $job->handle($worker);

        $job->assertNotReleased();
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame('review', $run->task->status);
        $this->assertNotNull(app(RunSlots::class)->acquire(60), 'The second slot is released when the job finishes.');
        $held->release();
    }

    public function test_a_waiting_run_does_not_start_once_the_daily_token_limit_is_reached(): void
    {
        $this->freezeTime();
        config(['mission.openai_api_key' => 'test-key', 'mission.max_parallel_runs' => 1, 'mission.daily_token_limit' => 10000]);
        AgentRun::factory()->create(['status' => 'completed', 'usage' => ['input_tokens' => 8000, 'output_tokens' => 2000]]);
        $run = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create();
        $worker = $this->mock(CodexProcess::class, function ($mock): void {
            $mock->shouldNotReceive('run');
            $mock->shouldReceive('redact')->andReturnUsing(fn (string $message): string => $message);
        });
        $job = (new RunMissionTask($run->id))->withFakeQueueInteractions();

        $job->handle($worker);

        $message = 'The daily token limit was reached before this run could start. Start it again tomorrow or raise the limit in Settings.';
        $job->assertNotReleased();
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame($message, $run->error);
        $this->assertSame('failed', $run->task->status);
        $this->assertSame($message, $run->task->error);
        $slot = app(RunSlots::class)->acquire(60);
        $this->assertNotNull($slot);
        $slot->release();
    }

    public function test_a_waiting_run_retries_for_six_hours_instead_of_a_fixed_number_of_attempts(): void
    {
        $this->freezeTime();
        $job = new RunMissionTask(1);

        $this->assertSame(0, $job->tries);
        $this->assertSame(1, $job->maxExceptions);
        $this->assertSame(now()->addHours(6)->getTimestamp(), $job->retryUntil()->getTimestamp());
    }

    public function test_recovery_leaves_a_waiting_run_with_a_pending_job_and_fails_a_dead_running_run(): void
    {
        $this->freezeTime();
        $root = storage_path('framework/testing/recovery-'.Str::uuid());
        config(['mission.worktree_root' => $root]);
        $waiting = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create(['status' => 'queued', 'updated_at' => now()->subMinutes(30)]);
        $running = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'running']), 'task')->create(['status' => 'running', 'updated_at' => now()->subMinutes(30)]);
        DB::table('jobs')->insert(['queue' => 'missions', 'payload' => '{}', 'attempts' => 3, 'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->subMinutes(30)->getTimestamp()]);

        Artisan::call('mission:recover');

        $this->assertSame('queued', $waiting->refresh()->status);
        $this->assertSame('queued', $waiting->task->status);
        $this->assertSame('failed', $running->refresh()->status);
        $this->assertSame('failed', $running->task->status);
    }

    public function test_recovery_fails_a_stale_queued_run_once_no_job_is_pending(): void
    {
        $this->freezeTime();
        $waiting = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'queued']), 'task')->create(['status' => 'queued', 'updated_at' => now()->subMinutes(30)]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);

        Artisan::call('mission:recover');

        $this->assertSame('failed', $waiting->refresh()->status);
    }

    public function test_recovery_ignores_a_cache_lock_left_by_a_busy_slot(): void
    {
        $this->freezeTime();
        $lock = Cache::lock('mission-execution:1', 60);
        $lock->get();
        $running = AgentRun::factory()->for(MissionTask::factory()->state(['status' => 'running']), 'task')->create(['status' => 'running', 'updated_at' => now()->subMinutes(30)]);

        try {
            Artisan::call('mission:recover');

            $this->assertSame('failed', $running->refresh()->status);
        } finally {
            $lock->release();
        }
    }
}
