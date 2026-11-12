<?php

namespace App\Models;

use Database\Factories\DecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['mission_task_id', 'action', 'feedback', 'revision'])]
class Decision extends Model
{
    /** @use HasFactory<DecisionFactory> */
    use HasFactory;

    public function task(): BelongsTo
    {
        return $this->belongsTo(MissionTask::class, 'mission_task_id');
    }
}
