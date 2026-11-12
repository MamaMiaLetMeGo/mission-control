<?php

namespace App\Models;

use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['mission_task_id', 'role', 'status', 'thread_id', 'usage', 'events', 'result', 'settings', 'error', 'started_at', 'finished_at'])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['usage' => 'array', 'events' => 'array', 'result' => 'array', 'settings' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MissionTask::class, 'mission_task_id');
    }
}
