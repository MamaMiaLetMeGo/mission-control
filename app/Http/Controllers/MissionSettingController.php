<?php

namespace App\Http\Controllers;

use App\Models\MissionTask;
use App\Services\GitRepository;
use App\Services\MissionSettings;
use App\Services\RepositorySync;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MissionSettingController extends Controller
{
    public function edit(MissionSettings $settings, RepositorySync $repository): View
    {
        return view('mission.settings', ['settings' => $settings->all(), 'readiness' => $settings->readiness(), 'repositoryStatus' => $repository->status()]);
    }

    public function update(Request $request, MissionSettings $settings, GitRepository $git): RedirectResponse
    {
        $current = $settings->all();
        foreach (MissionSettings::PREVIEW_KEYS as $key) {
            if (! $request->exists($key)) {
                $request->merge([$key => $current[$key]]);
            }
        }
        foreach (['test_command', 'preview_command', 'preview_paths'] as $key) {
            if (is_string($request->input($key))) {
                $request->merge([$key => json_decode($request->input($key), true)]);
            }
        }
        $previewEnabled = $request->boolean('preview_enabled');
        $previewRule = Rule::excludeIf(! $previewEnabled);
        $data = $request->validate([
            'repository_path' => ['required', 'string', 'max:4096'],
            'base_branch' => ['required', 'string', 'max:200'],
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'test_command' => ['present', 'array', 'list', 'max:30'],
            'test_command.*' => ['required', 'string', 'max:2000', 'not_regex:/\x00/'],
            'max_runtime_seconds' => ['required', 'integer', 'min:60', 'max:720'],
            'max_tokens' => ['required', 'integer', 'min:1000', 'max:500000'],
            'daily_run_limit' => ['required', 'integer', 'min:1', 'max:100'],
            'preview_enabled' => ['required', 'boolean'],
            'preview_driver' => [$previewRule, 'required', Rule::in(['laravel', 'command'])],
            'preview_directory' => [$previewRule, 'required', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! preg_match('~^(?:\.|[a-zA-Z0-9._-]+(?:/[a-zA-Z0-9._-]+)*)$~', $value) || in_array('..', explode('/', $value), true) || ($value !== '.' && in_array('.', explode('/', $value), true))) {
                    $fail('The preview directory must be a relative folder without parent traversal.');
                }
            }],
            'preview_command' => [$previewRule, 'required', 'array', 'list', 'min:1', 'max:30'],
            'preview_command.*' => [$previewRule, 'required', 'string', 'max:2000', 'not_regex:/\x00/'],
            'preview_paths' => [$previewRule, 'required', 'array', 'list', 'min:1', 'max:5'],
            'preview_paths.*' => [$previewRule, 'required', 'string', 'max:1000', 'distinct:strict', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! $this->isPreviewPath($value)) {
                    $fail('Screenshot paths must be local URLs starting with /, without fragments or parent traversal.');
                }
            }],
            'preview_ttl_seconds' => [$previewRule, 'required', 'integer', 'min:60', 'max:3600'],
        ]);
        $data['preview_enabled'] = $previewEnabled;
        if ($previewEnabled) {
            if (! collect($data['preview_command'])->contains(fn (string $argument): bool => str_contains($argument, '{port}'))) {
                throw ValidationException::withMessages(['preview_command' => 'The preview command must include {port} so each preview gets its own local port.']);
            }
            $data['preview_ttl_seconds'] = (int) $data['preview_ttl_seconds'];
        }
        $path = realpath($data['repository_path']);
        if (! $path || ! is_dir($path) || ! file_exists($path.'/.git')) {
            throw ValidationException::withMessages(['repository_path' => 'Choose an existing local Git repository.']);
        }
        if (! $git->isValidBranch($data['base_branch'])) {
            throw ValidationException::withMessages(['base_branch' => 'Enter a valid Git branch name.']);
        }
        if (realpath($git->command($path, ['rev-parse', '--show-toplevel'])) !== $path) {
            throw ValidationException::withMessages(['repository_path' => 'Choose the root folder of the Git repository.']);
        }
        $git->command($path, ['rev-parse', '--verify', 'refs/heads/'.$data['base_branch']]);
        $data['repository_path'] = $path;
        $data['model'] = $data['model'] ?? '';
        foreach (['max_runtime_seconds', 'max_tokens', 'daily_run_limit'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        Cache::lock('mission-workflow', 30)->block(5, function () use ($settings, $data): void {
            if (MissionTask::query()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['settings' => 'Wait for the active task to finish before changing settings.']);
            }
            $settings->update($data);
        });

        return to_route('settings.edit')->with('status', 'Settings saved.');
    }

    private function isPreviewPath(string $value): bool
    {
        $decoded = $value;
        for ($pass = 0; $pass < 3; $pass++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        if (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//') || str_contains($decoded, '\\') || preg_match('/[\x00-\x20\x7f]/', $decoded) || str_contains($decoded, '#')) {
            return false;
        }
        $parts = parse_url($decoded);
        if (! is_array($parts) || array_diff(array_keys($parts), ['path', 'query'])) {
            return false;
        }
        $segments = explode('/', $parts['path'] ?? '');

        return ! in_array('..', $segments, true) && ! in_array('.', $segments, true);
    }
}
