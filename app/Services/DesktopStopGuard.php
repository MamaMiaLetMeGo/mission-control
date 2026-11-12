<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DesktopStopGuard
{
    public function __construct(private DesktopStatus $status) {}

    /** @param resource $input
     * @param  Closure(array{ready: bool, message: string}): void  $announce
     */
    public function hold($input, Closure $announce): bool
    {
        try {
            $lock = Cache::lock('mission-workflow', 60);
            if (! $lock->get()) {
                $announce(['ready' => false, 'message' => 'Mission Control is busy. Wait for the current operation before stopping.']);

                return false;
            }
        } catch (Throwable) {
            $announce(['ready' => false, 'message' => 'The shutdown guard is unavailable. Mission Control will remain running.']);

            return false;
        }

        try {
            $health = $this->status->health();
            if (! $health['database']['ready'] || $health['active_tasks'] !== 0 || $health['active_runs'] !== 0 || $health['pending_jobs'] !== 0) {
                $announce(['ready' => false, 'message' => 'Mission Control still has active or queued work, or its database cannot be verified. It will remain running.']);

                return false;
            }
            if (! is_resource($input)) {
                $announce(['ready' => false, 'message' => 'The shutdown guard needs an open control stream. Mission Control will remain running.']);

                return false;
            }
            $announce(['ready' => true, 'message' => 'No active work. The shutdown guard is held.']);
            $deadline = microtime(true) + 30;
            while (microtime(true) < $deadline) {
                $read = [$input];
                $write = $except = null;
                if (stream_select($read, $write, $except, (int) ceil($deadline - microtime(true))) !== 1) {
                    return false;
                }
                $message = fread($input, 1024);
                if (feof($input) || (is_string($message) && str_contains($message, "\n"))) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        } finally {
            $lock->release();
        }
    }
}
