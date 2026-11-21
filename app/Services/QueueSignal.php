<?php

namespace App\Services;

use App\Models\MissionTask;
use Illuminate\Support\Str;

class QueueSignal
{
    private const SENSITIVE_PREFIXES = ['database/migrations/', '.github/', 'config/', 'routes/'];

    /**
     * Rate a task red, yellow or green from its own attributes only.
     *
     * @return array{signal: ?string, reason: ?string}
     */
    public static function for(MissionTask $task): array
    {
        $status = $task->status;
        $error = trim((string) $task->error);

        if ($status === 'failed') {
            return self::red($error !== '' ? Str::substr($error, 0, 200) : 'The run failed.');
        }
        if (in_array($status, ['changes_requested', 'review', 'approved', 'deferred'], true) && $error !== '') {
            return self::red(Str::substr($error, 0, 255));
        }
        if (! in_array($status, ['review', 'approved', 'deferred'], true)) {
            return ['signal' => null, 'reason' => null];
        }

        $data = is_array($task->review_data) ? $task->review_data : [];
        $diff = is_array($data['diff'] ?? null) ? $data['diff'] : [];
        $lines = (int) ($diff['additions'] ?? 0) + (int) ($diff['deletions'] ?? 0);

        $reason = match (true) {
            ! isset($data['security']) || ! is_array($data['security']) => 'Reviewed before security checks existed.',
            ! empty($data['security']['findings']) => 'The security check left notes.',
            ($data['mode'] ?? null) === 'refresh' => 'Re-checked after the base moved.',
            $lines > 400 => "Large change ({$lines} lines).",
            self::touchesSensitiveFiles($diff['files'] ?? []) => 'Touches sensitive files.',
            (int) ($data['reviewRounds'] ?? 0) > 0 => 'Needed reviewer fixes.',
            default => null,
        };

        if ($reason !== null) {
            return ['signal' => 'yellow', 'reason' => $reason];
        }

        return ['signal' => 'green', 'reason' => 'Passed tests, review and security check.'];
    }

    /** @return array{signal: string, reason: string} */
    private static function red(string $reason): array
    {
        return ['signal' => 'red', 'reason' => $reason];
    }

    private static function touchesSensitiveFiles(mixed $files): bool
    {
        foreach (is_array($files) ? $files : [] as $file) {
            if (! is_string($file)) {
                continue;
            }
            if (Str::startsWith($file, self::SENSITIVE_PREFIXES) || Str::contains($file, 'auth', true)) {
                return true;
            }
        }

        return false;
    }
}
