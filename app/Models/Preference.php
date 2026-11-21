<?php

namespace App\Models;

use Database\Factories\PreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['text', 'lead_id', 'status', 'source', 'evidence', 'note', 'seen_at'])]
class Preference extends Model
{
    /** @use HasFactory<PreferenceFactory> */
    use HasFactory;

    public const STATUSES = ['active', 'off'];

    public const SOURCES = ['learned', 'manual'];

    public const MAX_ACTIVE = 20;

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'seen_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @param  Builder<Preference>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function isNew(): bool
    {
        return $this->source === 'learned' && $this->seen_at === null;
    }
}
