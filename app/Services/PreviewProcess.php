<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

class PreviewProcess
{
    public function __construct(private CodexProcess $redactor) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function execute(string $mode, array $payload): array
    {
        if (! in_array($mode, ['start', 'status', 'stop'], true) || ! is_file(config('mission.preview_cli_path'))) {
            throw ValidationException::withMessages(['preview' => 'The preview service is not installed. Build the local worker first.']);
        }
        $this->reserveExecutionTime($mode);
        $process = new Process([config('mission.node_binary'), config('mission.preview_cli_path'), $mode], base_path(), $this->environment());
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout(match ($mode) {
            'start' => 180,
            'stop' => 25,
            default => 5,
        });
        try {
            $process->run();
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['preview' => 'The preview service did not respond: '.mb_substr($this->redactor->redact($exception->getMessage()), 0, 1000)]);
        }
        $result = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($result) || ($result['success'] ?? false) !== true || ! array_key_exists('preview', $result)) {
            $message = is_string($result['error'] ?? null) ? $result['error'] : 'The preview service could not complete this action.';
            throw ValidationException::withMessages(['preview' => mb_substr($this->redactor->redact($message), 0, 1000)]);
        }

        return $result;
    }

    private function reserveExecutionTime(string $mode): void
    {
        $seconds = match ($mode) {
            'start' => 240,
            'stop' => 45,
            default => 30,
        };
        if (function_exists('set_time_limit') && @set_time_limit($seconds)) {
            return;
        }
        $currentLimit = (int) ini_get('max_execution_time');
        if ($currentLimit !== 0 && $currentLimit < $seconds) {
            throw ValidationException::withMessages(['preview' => 'PHP cannot extend its execution limit for this preview operation. Allow set_time_limit or increase max_execution_time to '.$seconds.' seconds.']);
        }
    }

    /** @return array<string, string|false> */
    private function environment(): array
    {
        $environment = array_fill_keys(array_unique([...array_keys($_SERVER), ...array_keys($_ENV), ...array_keys(getenv())]), false);
        foreach (['PATH', 'TMPDIR', 'LANG', 'LC_ALL', 'SYSTEMROOT'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }
        $environment['GIT_CONFIG_NOSYSTEM'] = '1';
        $environment['GIT_CONFIG_GLOBAL'] = '/dev/null';

        return $environment;
    }
}
