<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\MissionTask;
use App\Services\DesktopStatus;
use App\Services\DesktopStopGuard;
use App\Services\GitHubProcess;
use App\Services\GitRepository;
use App\Services\PreviewProcess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class DesktopStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_health_identifies_this_checkout_and_counts_reserved_work_without_sessions_or_processes(): void
    {
        Queue::fake();
        config(['mission.openai_api_key' => 'do-not-disclose-this-key', 'session.driver' => 'database', 'session.connection' => 'missing-session-database']);
        $this->mock(GitRepository::class)->shouldNotReceive('command');
        $this->mock(GitHubProcess::class)->shouldNotReceive('command', 'json');
        $this->mock(PreviewProcess::class)->shouldNotReceive('execute');
        $task = MissionTask::factory()->create(['status' => 'running']);
        AgentRun::factory()->for($task, 'task')->create(['status' => 'running']);
        $this->job('missions');
        $this->job('default', reserved: true);
        $this->job('other', reserved: true);

        $response = $this->getJson(route('desktop.health'));

        $response->assertOk()->assertJsonPath('application', 'mission-control')->assertJsonPath('repo_root', hash('sha256', realpath(base_path())))->assertJsonPath('database.ready', true)->assertJsonPath('active_tasks', 1)->assertJsonPath('active_runs', 1)->assertJsonPath('pending_jobs', 2)->assertJsonPath('reserved_jobs', 1)->assertJsonMissingPath('repository_path')->assertJsonMissingPath('api_key_present')->assertHeaderMissing('Set-Cookie');
        $this->assertStringNotContainsString('do-not-disclose-this-key', $response->getContent());
        $this->assertDatabaseCount('jobs', 3);
        $this->assertSame('running', $task->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_status_command_returns_json_with_safe_readiness_and_missing_build_labels(): void
    {
        MissionTask::factory()->create();
        config(['mission.openai_api_key' => 'private-test-key', 'mission.worker_path' => base_path('does-not-exist.js'), 'mission.preview_database.driver' => 'sqlite']);

        $this->assertSame(0, Artisan::call('mission:desktop-status', ['--json' => true]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('mission-control', $result['application']);
        $this->assertSame(realpath(base_path()), $result['repository_path']);
        $this->assertTrue($result['database']['ready']);
        $this->assertFalse($result['ready']);
        $this->assertContains('worker/dist/index.js', $result['files']['missing']);
        $this->assertTrue($result['api_key_present']);
        $this->assertTrue($result['preview_database']['ready']);
        $this->assertArrayNotHasKey('username', $result['preview_database']);
        $this->assertStringNotContainsString('private-test-key', Artisan::output());
    }

    public function test_database_failure_is_sanitized_and_never_reported_as_idle(): void
    {
        $this->partialMock(DesktopStatus::class, function ($mock): void {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('connection')->once()->andThrow(new RuntimeException('password=never-print-this host=private-host'));
        });

        $response = $this->getJson(route('desktop.health'));

        $response->assertOk()->assertJsonPath('database.ready', false)->assertJsonPath('ready', false)->assertJsonPath('active_tasks', null)->assertJsonPath('active_runs', null)->assertJsonPath('pending_jobs', null)->assertJsonPath('reserved_jobs', null);
        $this->assertStringNotContainsString('never-print-this', $response->getContent());
        $this->assertStringNotContainsString('private-host', $response->getContent());
    }

    public function test_preview_failure_returns_only_allowlisted_local_connection_fields(): void
    {
        config(['mission.preview_database' => ['driver' => 'pgsql', 'host' => '/tmp', 'port' => 5433, 'username' => 'local_user', 'password' => 'private-preview-password']]);
        $service = $this->partialMock(DesktopStatus::class, function ($mock): void {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('postgres')->once()->andThrow(new RuntimeException('private-preview-password'));
        });

        $result = $service->previewDatabase();

        $this->assertFalse($result['ready']);
        $this->assertSame('/tmp', $result['host']);
        $this->assertSame(5433, $result['port']);
        $this->assertArrayNotHasKey('username', $result);
        $this->assertStringNotContainsString('private-preview-password', json_encode($result));
    }

    public function test_health_retains_the_loopback_host_and_peer_boundary(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])->getJson(route('desktop.health'))->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->getJson('http://other.example/desktop/health')->assertForbidden();
    }

    public function test_idle_shutdown_guard_holds_the_lock_until_control_newline_then_releases(): void
    {
        MissionTask::factory()->create();
        [$input, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $announcements = [];
        try {
            $released = app(DesktopStopGuard::class)->hold($input, function (array $result) use (&$announcements, $writer): void {
                $announcements[] = $result;
                $this->assertFalse(Cache::lock('mission-workflow', 60)->get());
                fwrite($writer, "\n");
            });

            $this->assertTrue($released);
            $this->assertCount(1, $announcements);
            $this->assertTrue($announcements[0]['ready']);
            $lock = Cache::lock('mission-workflow', 60);
            $this->assertTrue($lock->get());
            $lock->release();
        } finally {
            fclose($input);
            fclose($writer);
        }
    }

    public function test_shutdown_guard_releases_when_the_launcher_closes_its_control_stream(): void
    {
        MissionTask::factory()->create();
        [$input, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        try {
            $this->assertTrue(app(DesktopStopGuard::class)->hold($input, function (array $result) use ($writer): void {
                $this->assertTrue($result['ready']);
                fclose($writer);
            }));
            $lock = Cache::lock('mission-workflow', 60);
            $this->assertTrue($lock->get());
            $lock->release();
        } finally {
            fclose($input);
            if (is_resource($writer)) {
                fclose($writer);
            }
        }
    }

    #[TestWith(['task'])]
    #[TestWith(['run'])]
    #[TestWith(['queued_job'])]
    #[TestWith(['reserved_job'])]
    public function test_shutdown_guard_refuses_active_or_reserved_work(string $work): void
    {
        if ($work === 'task') {
            MissionTask::factory()->create(['status' => 'queued']);
        } elseif ($work === 'run') {
            AgentRun::factory()->create(['status' => 'running']);
        } else {
            $this->job('missions', reserved: $work === 'reserved_job');
        }
        $input = fopen('php://temp', 'r+');
        $announcements = [];
        try {
            $released = app(DesktopStopGuard::class)->hold($input, function (array $result) use (&$announcements): void {
                $announcements[] = $result;
            });

            $this->assertFalse($released);
            $this->assertCount(1, $announcements);
            $this->assertFalse($announcements[0]['ready']);
            $lock = Cache::lock('mission-workflow', 60);
            $this->assertTrue($lock->get());
            $lock->release();
        } finally {
            fclose($input);
        }
    }

    public function test_shutdown_guard_refuses_an_unverifiable_database(): void
    {
        $this->partialMock(DesktopStatus::class, function ($mock): void {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('connection')->once()->andThrow(new RuntimeException('private-connection-error'));
        });
        $input = fopen('php://temp', 'r+');
        $announcements = [];
        try {
            $this->assertFalse(app(DesktopStopGuard::class)->hold($input, function (array $result) use (&$announcements): void {
                $announcements[] = $result;
            }));
            $this->assertFalse($announcements[0]['ready']);
            $this->assertStringNotContainsString('private-connection-error', json_encode($announcements));
        } finally {
            fclose($input);
        }
    }

    public function test_busy_stop_guard_command_exits_with_one_json_line_without_waiting(): void
    {
        $lock = Cache::lock('mission-workflow', 60);
        $lock->get();
        $this->mock(DesktopStatus::class)->shouldNotReceive('health');
        try {
            $this->assertSame(1, Artisan::call('mission:desktop-stop-guard'));
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($result['ready']);
            $this->assertTrue($lock->isOwnedByCurrentProcess());
        } finally {
            $lock->release();
        }
    }

    private function job(string $queue, bool $reserved = false): void
    {
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => $reserved ? 1 : 0, 'reserved_at' => $reserved ? time() : null, 'available_at' => time(), 'created_at' => time()]);
    }
}
