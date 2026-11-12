<?php

namespace App\Services;

use App\Models\MissionTask;
use Illuminate\Validation\ValidationException;

class BrowserEvidence
{
    public function assertComplete(MissionTask $task): void
    {
        $run = $task->runs()->first();
        if (! ($run?->settings['preview_enabled'] ?? false)) {
            return;
        }
        $browser = data_get($task->review_data, 'browser');
        $paths = $run->settings['preview_paths'] ?? ['/'];
        if (! is_string($task->revision) || ! preg_match('/^[a-f0-9]{40,64}$/', $task->revision) || ! is_string($task->base_revision) || ! preg_match('/^[a-f0-9]{40,64}$/', $task->base_revision) || ! is_array($browser) || ($browser['status'] ?? null) !== 'passed' || ($browser['revision'] ?? null) !== $task->revision || ($browser['baseRevision'] ?? null) !== $task->base_revision || ! is_array($browser['screenshots'] ?? null) || count($browser['screenshots']) !== count($paths) * 4) {
            $this->invalidate($task);
        }
        $seen = [];
        $files = [];
        foreach ($browser['screenshots'] as $screenshot) {
            if (! is_array($screenshot) || ! in_array($screenshot['path'] ?? null, $paths, true) || ! in_array($screenshot['phase'] ?? null, ['before', 'after'], true) || ! in_array($screenshot['viewport'] ?? null, ['desktop', 'mobile'], true) || ! $this->verifiedPath($task, $screenshot)) {
                $this->invalidate($task);
            }
            $key = json_encode([$screenshot['path'], $screenshot['phase'], $screenshot['viewport']]);
            if (isset($seen[$key]) || isset($files[$screenshot['file']])) {
                $this->invalidate($task);
            }
            $seen[$key] = true;
            $files[$screenshot['file']] = true;
        }
    }

    public function screenshotPath(MissionTask $task, string $file): ?string
    {
        $screenshots = data_get($task->review_data, 'browser.screenshots', []);
        if (! is_array($screenshots)) {
            return null;
        }
        foreach ($screenshots as $screenshot) {
            if (is_array($screenshot) && ($screenshot['file'] ?? null) === $file) {
                return $this->verifiedPath($task, $screenshot);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $screenshot */
    private function verifiedPath(MissionTask $task, array $screenshot): ?string
    {
        $file = $screenshot['file'] ?? null;
        $hash = $screenshot['sha256'] ?? null;
        $phase = $screenshot['phase'] ?? null;
        if (! is_string($file) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,150}\.png$/', $file) || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/', $hash) || ! in_array($phase, ['before', 'after'], true) || ($screenshot['revision'] ?? null) !== ($phase === 'before' ? $task->base_revision : $task->revision)) {
            return null;
        }
        $root = realpath(config('mission.artifact_root'));
        $directory = $task->artifact_path ? realpath($task->artifact_path) : false;
        if (! $root || ! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }
        $path = realpath($directory.DIRECTORY_SEPARATOR.$file);
        if (! $path || ! is_file($path) || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR) || filesize($path) > 30_000_000) {
            return null;
        }
        $signature = file_get_contents($path, false, null, 0, 8);
        $size = @getimagesize($path);
        if ($signature !== "\x89PNG\r\n\x1a\n" || ! is_array($size) || ($size['mime'] ?? null) !== 'image/png' || ($screenshot['width'] ?? null) !== $size[0] || ($screenshot['height'] ?? null) !== $size[1] || ! hash_equals($hash, hash_file('sha256', $path))) {
            return null;
        }

        return $path;
    }

    private function invalidate(MissionTask $task): never
    {
        $message = 'The required desktop and mobile screenshots are missing, changed, or belong to another revision. Run a fresh verification before approval or merge.';
        $task->update(['status' => 'changes_requested', 'error' => $message]);
        throw ValidationException::withMessages(['task' => $message]);
    }
}
