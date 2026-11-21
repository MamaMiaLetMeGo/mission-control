<?php

use App\Models\MissionTask;
use App\Services\QueueSignal;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MissionTask::query()->chunkById(100, function ($tasks): void {
            foreach ($tasks as $task) {
                $signal = QueueSignal::for($task);
                MissionTask::query()->whereKey($task->id)->toBase()->update(['signal' => $signal['signal'], 'signal_reason' => $signal['reason']]);
            }
        });
    }

    public function down(): void {}
};
