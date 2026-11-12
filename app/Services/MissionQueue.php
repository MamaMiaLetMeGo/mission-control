<?php

namespace App\Services;

use App\Models\MissionTask;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class MissionQueue
{
    /** @param array<string, mixed> $filters
     * @return array{tasks: LengthAwarePaginator, counts: array<string, int>}
     */
    public function data(array $filters): array
    {
        $query = MissionTask::query()->select(['id', 'title', 'area', 'priority', 'status', 'created_at'])->withCount(['runs', 'decisions']);
        foreach (['status', 'area'] as $filter) {
            if (! empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (! empty($filters['q'])) {
            $query->where('title', 'like', '%'.$filters['q'].'%');
        }
        $tasks = $query->orderByRaw("CASE WHEN status = 'failed' THEN 0 WHEN status IN ('review', 'approved') THEN 1 WHEN status IN ('running', 'queued') THEN 2 WHEN status IN ('backlog', 'changes_requested') THEN 3 ELSE 4 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->latest('id')->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withPath(route('dashboard'))->appends(Arr::except($filters, ['page']));
        $counts = array_fill_keys(MissionTask::STATUSES, 0);
        foreach (MissionTask::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->get() as $row) {
            $counts[$row->status] = (int) $row->aggregate;
        }

        return compact('tasks', 'counts');
    }
}
