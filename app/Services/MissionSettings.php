<?php

namespace App\Services;

use App\Models\MissionSetting;
use Illuminate\Support\Facades\DB;

class MissionSettings
{
    public const KEYS = ['repository_path', 'base_branch', 'model', 'test_command', 'max_runtime_seconds', 'max_tokens', 'daily_run_limit', 'preview_enabled', 'preview_driver', 'preview_directory', 'preview_command', 'preview_paths', 'preview_ttl_seconds'];

    public const PREVIEW_KEYS = ['preview_enabled', 'preview_driver', 'preview_directory', 'preview_command', 'preview_paths', 'preview_ttl_seconds'];

    /** @param array<string, mixed> $settings
     * @return array{enabled: bool, driver: string, directory: string, command: array, paths: array, ttlSeconds: int}
     */
    public function previewConfiguration(array $settings): array
    {
        return [
            'enabled' => (bool) ($settings['preview_enabled'] ?? false),
            'driver' => $settings['preview_driver'] ?? 'laravel',
            'directory' => $settings['preview_directory'] ?? 'backend',
            'command' => $settings['preview_command'] ?? ['php', 'artisan', 'serve', '--host=127.0.0.1', '--port={port}', '--no-reload'],
            'paths' => $settings['preview_paths'] ?? ['/'],
            'ttlSeconds' => (int) ($settings['preview_ttl_seconds'] ?? 1800),
        ];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $settings[$key] = config('mission.'.$key);
        }
        foreach (MissionSetting::query()->whereIn('key', self::KEYS)->get() as $setting) {
            $settings[$setting->key] = $setting->value;
        }

        return $settings;
    }

    /** @param array<string, mixed> $settings */
    public function update(array $settings): void
    {
        DB::transaction(function () use ($settings): void {
            foreach (self::KEYS as $key) {
                if (array_key_exists($key, $settings)) {
                    MissionSetting::query()->updateOrCreate(['key' => $key], ['value' => $settings[$key]]);
                }
            }
        });
    }

    /** @return array{api_key: bool, repository: bool, worker: bool, tests: bool, ready: bool, issues: list<string>} */
    public function readiness(): array
    {
        $settings = $this->all();
        $path = $settings['repository_path'];
        $checks = [
            'api_key' => is_string(config('mission.openai_api_key')) && trim(config('mission.openai_api_key')) !== '',
            'repository' => is_string($path) && is_dir($path) && file_exists($path.'/.git'),
            'worker' => is_file(config('mission.worker_path')),
            'tests' => is_array($settings['test_command']) && count($settings['test_command']) > 0,
        ];
        $messages = [
            'api_key' => 'Add the OpenAI API key to this app’s .env file, then restart the queue worker.',
            'repository' => 'Choose an existing local Git repository in Settings.',
            'worker' => 'Build the Codex worker before starting a task.',
            'tests' => 'Set a verification command in Settings before starting a task.',
        ];
        $issues = [];
        foreach ($checks as $key => $ready) {
            if (! $ready) {
                $issues[] = $messages[$key];
            }
        }

        return [...$checks, 'ready' => count($issues) === 0, 'issues' => $issues];
    }
}
