<?php

namespace App\Models;

use Database\Factories\MissionTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'request', 'acceptance_criteria', 'area', 'priority', 'status', 'recommendation', 'review_data', 'branch', 'worktree_path', 'revision', 'base_revision', 'artifact_path', 'error', 'publication', 'production_publication'])]
class MissionTask extends Model
{
    /** @use HasFactory<MissionTaskFactory> */
    use HasFactory;

    public const STATUSES = ['backlog', 'queued', 'running', 'review', 'approved', 'merged', 'changes_requested', 'deferred', 'rejected', 'failed'];

    public const AREAS = ['engineering', 'content', 'quality', 'growth'];

    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    protected function casts(): array
    {
        return ['review_data' => 'array', 'publication' => 'array', 'production_publication' => 'array'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class)->latest('id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class)->latest('id');
    }
}
