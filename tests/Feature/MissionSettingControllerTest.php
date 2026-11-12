<?php

namespace Tests\Feature;

use App\Models\MissionSetting;
use App\Models\MissionTask;
use App\Services\GitRepository;
use App\Services\MissionSettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MissionSettingControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_settings_never_render_the_api_key(): void
    {
        config(['mission.openai_api_key' => 'secret-value-that-must-never-render']);

        $this->get(route('settings.edit'))->assertOk()->assertDontSee('secret-value-that-must-never-render');
    }

    public function test_saves_an_argv_array_and_ignores_unknown_settings(): void
    {
        $this->fakeGit();
        $payload = $this->payload();
        $payload['openai_api_key'] = 'do-not-store-this';

        $response = $this->post(route('settings.update'), $payload);

        $response->assertRedirect(route('settings.edit'));
        $this->assertSame(['php', 'artisan', 'test'], app(MissionSettings::class)->all()['test_command']);
        $this->assertDatabaseMissing('mission_settings', ['key' => 'openai_api_key']);
    }

    public function test_rejects_a_shell_string_instead_of_an_argv_array(): void
    {
        $response = $this->post(route('settings.update'), [...$this->payload(), 'test_command' => 'php artisan test; echo unsafe']);

        $response->assertSessionHasErrors('test_command');
        $this->assertDatabaseCount('mission_settings', 0);
    }

    public function test_rejects_runtime_longer_than_queue_reservation(): void
    {
        $response = $this->post(route('settings.update'), [...$this->payload(), 'max_runtime_seconds' => 10000]);

        $response->assertSessionHasErrors('max_runtime_seconds');
        $this->assertDatabaseCount('mission_settings', 0);
    }

    public function test_active_runs_prevent_settings_changes(): void
    {
        $this->fakeGit();
        MissionTask::factory()->create(['status' => 'running']);

        $response = $this->post(route('settings.update'), $this->payload());

        $response->assertSessionHasErrors('settings');
        $this->assertSame(0, MissionSetting::query()->count());
    }

    public function test_saves_preview_configuration_as_argv_and_local_paths(): void
    {
        $this->fakeGit();
        $payload = [...$this->payload(), 'preview_enabled' => true, 'preview_driver' => 'command', 'preview_directory' => '.', 'preview_command' => '["node","server.js","{port}"]', 'preview_paths' => '["/","/lessons?language=es"]', 'preview_ttl_seconds' => 120];

        $response = $this->post(route('settings.update'), $payload);

        $response->assertRedirect(route('settings.edit'));
        $saved = app(MissionSettings::class)->all();
        $this->assertTrue($saved['preview_enabled']);
        $this->assertSame(['node', 'server.js', '{port}'], $saved['preview_command']);
        $this->assertSame(['/', '/lessons?language=es'], $saved['preview_paths']);
    }

    #[DataProvider('invalidPreviewPaths')]
    public function test_rejects_external_or_ambiguous_screenshot_paths(string $path): void
    {
        $response = $this->post(route('settings.update'), [...$this->payload(), 'preview_enabled' => true, 'preview_paths' => [$path]]);

        $response->assertSessionHasErrors('preview_paths.0');
        $this->assertDatabaseCount('mission_settings', 0);
    }

    public static function invalidPreviewPaths(): array
    {
        return [
            'absolute URL' => ['https://example.test/'],
            'protocol relative' => ['//example.test/'],
            'backslash' => ['/\\example.test'],
            'parent traversal' => ['/one/../two'],
            'encoded parent traversal' => ['/%2e%2e/secret'],
            'encoded external authority' => ['/%2fexample.test'],
            'fragment' => ['/lessons#player'],
            'null byte' => ['/lessons%00'],
        ];
    }

    public function test_preview_command_requires_the_allocated_port_placeholder(): void
    {
        $response = $this->post(route('settings.update'), [...$this->payload(), 'preview_enabled' => true, 'preview_command' => ['php', 'artisan', 'serve']]);

        $response->assertSessionHasErrors('preview_command');
        $this->assertDatabaseCount('mission_settings', 0);
    }

    public function test_preview_directory_cannot_escape_the_worktree(): void
    {
        $response = $this->post(route('settings.update'), [...$this->payload(), 'preview_enabled' => true, 'preview_directory' => '../production']);

        $response->assertSessionHasErrors('preview_directory');
        $this->assertDatabaseCount('mission_settings', 0);
    }

    public function test_disabling_preview_preserves_the_previous_configuration(): void
    {
        $this->fakeGit();
        app(MissionSettings::class)->update(['preview_enabled' => true, 'preview_command' => ['node', 'server.js', '{port}']]);

        $response = $this->post(route('settings.update'), [...$this->payload(), 'preview_enabled' => false, 'preview_command' => 'unfinished edit']);

        $response->assertRedirect(route('settings.edit'));
        $saved = app(MissionSettings::class)->all();
        $this->assertFalse($saved['preview_enabled']);
        $this->assertSame(['node', 'server.js', '{port}'], $saved['preview_command']);
    }

    private function payload(): array
    {
        return ['repository_path' => base_path(), 'base_branch' => 'main', 'model' => '', 'test_command' => '["php","artisan","test"]', 'max_runtime_seconds' => 600, 'max_tokens' => 50000, 'daily_run_limit' => 5];
    }

    private function fakeGit(): void
    {
        $this->mock(GitRepository::class, function ($mock): void {
            $mock->shouldReceive('isValidBranch')->once()->with('main')->andReturn(true);
            $mock->shouldReceive('command')->once()->with(base_path(), ['rev-parse', '--show-toplevel'])->andReturn(base_path());
            $mock->shouldReceive('command')->once()->with(base_path(), ['rev-parse', '--verify', 'refs/heads/main'])->andReturn(str_repeat('a', 40));
        });
    }
}
