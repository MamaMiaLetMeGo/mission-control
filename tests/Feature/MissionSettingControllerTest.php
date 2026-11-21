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

    public function test_saves_auto_start_and_stores_false_when_the_checkbox_is_unchecked(): void
    {
        $this->fakeGit();
        $this->post(route('settings.update'), [...$this->payload(), 'auto_start' => '1'])->assertRedirect(route('settings.edit'));
        $this->assertTrue(app(MissionSettings::class)->all()['auto_start']);

        $this->fakeGit();
        $this->post(route('settings.update'), $this->payload())->assertRedirect(route('settings.edit'));
        $this->assertFalse(app(MissionSettings::class)->all()['auto_start']);
    }

    public function test_auto_start_defaults_on(): void
    {
        $this->assertTrue(app(MissionSettings::class)->all()['auto_start']);
    }

    public function test_saves_review_rounds_and_defaults_to_two(): void
    {
        $this->assertSame(2, app(MissionSettings::class)->all()['review_rounds']);
        $this->fakeGit();

        $this->post(route('settings.update'), [...$this->payload(), 'review_rounds' => '3'])->assertRedirect(route('settings.edit'));

        $this->assertSame(3, app(MissionSettings::class)->all()['review_rounds']);
    }

    #[DataProvider('invalidReviewRounds')]
    public function test_rejects_review_rounds_outside_zero_to_three(mixed $rounds): void
    {
        $response = $this->post(route('settings.update'), [...$this->payload(), 'review_rounds' => $rounds]);

        $response->assertSessionHasErrors('review_rounds');
        $this->assertDatabaseCount('mission_settings', 0);
    }

    public static function invalidReviewRounds(): array
    {
        return ['negative' => [-1], 'too many' => [4], 'fraction' => [1.5], 'missing' => ['']];
    }

    public function test_per_run_token_allowance_accepts_up_to_one_million(): void
    {
        $this->post(route('settings.update'), [...$this->payload(), 'max_tokens' => '1000000'])->assertRedirect(route('settings.edit'));
        $this->assertSame(1000000, app(MissionSettings::class)->all()['max_tokens']);

        $this->post(route('settings.update'), [...$this->payload(), 'max_tokens' => '1000001'])->assertSessionHasErrors('max_tokens');
    }

    public function test_settings_page_shows_the_review_rounds_field(): void
    {
        $this->get(route('settings.edit'))->assertOk()->assertSee('Review fix rounds')->assertSee("How many times a sub-agent may fix the reviewer's findings before the run stops for you.", false)->assertSee('name="review_rounds"', false);
    }

    public function test_brain_learning_defaults_on_after_five_decisions(): void
    {
        $settings = app(MissionSettings::class)->all();

        $this->assertTrue($settings['brain_enabled']);
        $this->assertSame(5, $settings['brain_learn_after']);
    }

    public function test_saves_brain_settings_and_stores_false_when_the_checkbox_is_unchecked(): void
    {
        $this->fakeGit();
        $this->post(route('settings.update'), [...$this->payload(), 'brain_enabled' => '1', 'brain_learn_after' => '12'])->assertRedirect(route('settings.edit'));
        $saved = app(MissionSettings::class)->all();
        $this->assertTrue($saved['brain_enabled']);
        $this->assertSame(12, $saved['brain_learn_after']);

        $this->fakeGit();
        $this->post(route('settings.update'), $this->payload())->assertRedirect(route('settings.edit'));
        $this->assertFalse(app(MissionSettings::class)->all()['brain_enabled']);
    }

    #[DataProvider('invalidLearnAfter')]
    public function test_rejects_decisions_before_learning_outside_one_to_fifty(mixed $count): void
    {
        $response = $this->post(route('settings.update'), [...$this->payload(), 'brain_learn_after' => $count]);

        $response->assertSessionHasErrors('brain_learn_after');
        $this->assertDatabaseCount('mission_settings', 0);
    }

    public static function invalidLearnAfter(): array
    {
        return ['zero' => [0], 'too many' => [51], 'fraction' => [2.5], 'missing' => ['']];
    }

    public function test_settings_page_shows_the_brain_settings(): void
    {
        $this->get(route('settings.edit'))->assertOk()
            ->assertSee('Let the Brain learn from my decisions')
            ->assertSee('After enough decisions with feedback, a read-only agent turns them into preferences you can edit on the Brain page.')
            ->assertSee('Decisions before the Brain learns')
            ->assertSee('name="brain_enabled"', false)
            ->assertSee('name="brain_learn_after"', false);
    }

    private function payload(): array
    {
        return ['repository_path' => base_path(), 'base_branch' => 'main', 'model' => '', 'test_command' => '["php","artisan","test"]', 'max_runtime_seconds' => 600, 'max_tokens' => 50000, 'daily_run_limit' => 5, 'max_parallel_runs' => 3, 'daily_token_limit' => 2000000, 'review_rounds' => 2, 'brain_learn_after' => 5];
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
