<?php

use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

Artisan::command('mission:recover {--older-than=20 : Only mark runs older than this many minutes as failed}', function (): void {
    $minutes = max(15, (int) $this->option('older-than'));
    $lock = Cache::lock('mission-execution', 30);
    if (! $lock->get()) {
        $this->error('A worker still holds the execution lock. Wait for it to stop before recovering.');

        return;
    }
    try {
        $recovered = 0;
        $runs = AgentRun::query()->whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes($minutes))->get();
        foreach ($runs as $run) {
            $ownedLocks = [];
            $canRecover = true;
            foreach (glob(config('mission.worktree_root').'/.locks/*/owner.json') ?: [] as $ownerFile) {
                if (is_link($ownerFile) || is_link(dirname($ownerFile))) {
                    continue;
                }
                $owner = json_decode(file_get_contents($ownerFile), true);
                if (! is_array($owner) || ($owner['taskId'] ?? null) !== $run->mission_task_id || realpath($owner['repositoryPath'] ?? '') !== realpath($run->settings['repository_path'] ?? '')) {
                    continue;
                }
                $pid = $owner['pid'] ?? null;
                if (! is_int($pid) || $pid < 1 || ! function_exists('posix_kill') || @posix_kill($pid, 0) || posix_get_last_error() !== 3) {
                    $canRecover = false;
                    break;
                }
                $ownedLocks[] = $ownerFile;
            }
            if (! $canRecover) {
                $this->warn('Skipped task '.$run->mission_task_id.': its worker process may still be alive.');

                continue;
            }
            foreach ($ownedLocks as $ownerFile) {
                unlink($ownerFile);
                @rmdir(dirname($ownerFile));
            }
            (new RunMissionTask($run->id))->failed(new RuntimeException('Run marked interrupted by mission:recover. Evidence is retained; retry manually.'));
            $recovered++;
        }
        $this->info('Marked '.$recovered.' interrupted runs as failed. No tasks were restarted.');
    } finally {
        $lock->release();
    }
})->purpose('Recover stale mission runs without repeating external actions');
