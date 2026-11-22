<?php

use App\Jobs\LearnPreferences;
use App\Jobs\PlanGoal;
use App\Jobs\RunMissionTask;
use App\Models\AgentRun;
use App\Models\BrainRun;
use App\Models\Goal;
use App\Services\ChiefOfStaff;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('mission:recover {--older-than=20 : Only mark runs older than this many minutes as failed}', function (): void {
    $minutes = max(15, (int) $this->option('older-than'));
    $recovered = 0;
    $jobPending = DB::table(config('queue.connections.database.table', 'jobs'))->where('queue', 'missions')->exists();
    $statuses = $jobPending ? ['running'] : ['queued', 'running'];
    $runs = AgentRun::query()->whereIn('status', $statuses)->where('updated_at', '<', now()->subMinutes($minutes))->get();
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
    $brainRuns = BrainRun::query()->whereIn('status', $jobPending ? ['learning'] : ['queued', 'learning'])->where('updated_at', '<', now()->subMinutes($minutes))->pluck('id');
    foreach ($brainRuns as $brainRunId) {
        (new LearnPreferences($brainRunId))->failed(new RuntimeException('Learning was interrupted. Nothing was changed.'));
        $recovered++;
    }
    $this->info('Marked '.$recovered.' interrupted runs as failed. No tasks were restarted.');
    $goals = Goal::query()->whereIn('status', $jobPending ? ['planning'] : ['queued', 'planning'])->where('updated_at', '<', now()->subMinutes($minutes))->pluck('source', 'id');
    foreach ($goals as $goalId => $source) {
        (new PlanGoal($goalId))->failed(new RuntimeException($source === 'ideas' ? PlanGoal::INTERRUPTED_IDEAS : 'Planning was interrupted. Give the goal again.'));
    }
    $this->info('Marked '.$goals->count().' interrupted goal plan(s) as failed.');
})->purpose('Recover stale mission runs without repeating external actions');

Artisan::command('mission:dispatch', function (ChiefOfStaff $chief): void {
    $this->info('Started '.$chief->dispatch().' goal task(s).');
})->purpose('Start goal tasks that are ready while an agent is free');

Artisan::command('mission:ideas', function (ChiefOfStaff $chief): void {
    try {
        $goal = $chief->propose();
    } catch (LockTimeoutException) {
        $goal = null;
    }
    $this->info($goal ? 'Asked the leads for ideas.' : 'No ideas run is due.');
})->purpose('Ask the leads for ideas once a day');

Schedule::command('mission:dispatch')->everyMinute()->withoutOverlapping(5);
Schedule::command('mission:ideas')->hourly()->withoutOverlapping(5);
