<?php

namespace App\Models;

use Database\Factories\BrainRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['status', 'summary', 'error', 'usage', 'events', 'settings', 'decision_ids', 'last_decision_id', 'started_at', 'finished_at'])]
class BrainRun extends Model
{
    /** @use HasFactory<BrainRunFactory> */
    use HasFactory;

    public const STATUSES = ['queued', 'learning', 'completed', 'failed'];

    protected function casts(): array
    {
        return [
            'usage' => 'array',
            'events' => 'array',
            'settings' => 'array',
            'decision_ids' => 'array',
            'last_decision_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
