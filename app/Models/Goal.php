<?php

namespace App\Models;

use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['request', 'source', 'status', 'overview', 'error', 'usage', 'events', 'settings', 'started_at', 'finished_at'])]
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    public const STATUSES = ['queued', 'planning', 'planned', 'failed'];

    public const SOURCES = ['owner', 'ideas'];

    protected function casts(): array
    {
        return [
            'usage' => 'array',
            'events' => 'array',
            'settings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(MissionTask::class)->orderBy('goal_position')->orderBy('id');
    }
}
