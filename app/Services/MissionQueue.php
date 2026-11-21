<?php

namespace App\Services;

use App\Models\MissionTask;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class MissionQueue
{
    public function __construct(private RunSlots $slots, private TokenBudget $tokens) {}

    /** @param array<string, mixed> $filters
     * @return array{tasks: LengthAwarePaginator, counts: array<string, int>, capacity: array{busy: int, slots: int, tokensUsed: int, tokenLimit: int}}
     */
    public function data(array $filters): array
    {
        $query = MissionTask::query()->select(['id', 'title', 'lead_id', 'goal_id', 'goal_position', 'signal', 'signal_reason', 'priority', 'status', 'created_at'])->with('lead:id,key,name')->withCount(['runs', 'decisions']);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['lead'])) {
            $query->whereHas('lead', fn ($lead) => $lead->where('key', $filters['lead']));
        }
        if (! empty($filters['q'])) {
            $query->where('title', 'like', '%'.$filters['q'].'%');
        }
        $tasks = $query->orderByRaw("CASE signal WHEN 'red' THEN 0 WHEN 'yellow' THEN 1 WHEN 'green' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE WHEN status = 'failed' THEN 0 WHEN status IN ('review', 'approved') THEN 1 WHEN status IN ('running', 'queued') THEN 2 WHEN status IN ('backlog', 'changes_requested') THEN 3 ELSE 4 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByRaw('CASE WHEN goal_position IS NULL THEN 1 ELSE 0 END')->orderBy('goal_position')
            ->latest('id')->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withPath(route('dashboard'))->appends(Arr::except($filters, ['page']));
        $counts = array_fill_keys(MissionTask::STATUSES, 0);
        foreach (MissionTask::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->get() as $row) {
            $counts[$row->status] = (int) $row->aggregate;
        }

        $capacity = ['busy' => $this->slots->busy(), 'slots' => $this->slots->limit(), 'tokensUsed' => $this->tokens->usedToday(), 'tokenLimit' => $this->tokens->limit()];

        return compact('tasks', 'counts', 'capacity');
    }
}
