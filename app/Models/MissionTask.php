<?php

namespace App\Models;

use Database\Factories\MissionTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['title', 'request', 'acceptance_criteria', 'lead_id', 'priority', 'status', 'recommendation', 'review_data', 'branch', 'worktree_path', 'revision', 'base_revision', 'artifact_path', 'error', 'publication', 'production_publication', 'goal_id', 'goal_position', 'depends_on', 'signal', 'signal_reason'])]
class MissionTask extends Model
{
    /** @use HasFactory<MissionTaskFactory> */
    use HasFactory;

    public const STATUSES = ['backlog', 'queued', 'running', 'review', 'approved', 'merged', 'changes_requested', 'deferred', 'rejected', 'failed'];

    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    protected function casts(): array
    {
        return ['review_data' => 'array', 'publication' => 'array', 'production_publication' => 'array', 'depends_on' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * Tasks this one depends on that have not been merged yet.
     *
     * @return Collection<int, MissionTask>
     */
    public function waitingOn(): Collection
    {
        if (empty($this->depends_on)) {
            return new Collection;
        }

        return static::query()->whereIn('id', $this->depends_on)->where('status', '!=', 'merged')->get();
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
