@php
    $statusLabels = ['backlog' => 'Backlog', 'queued' => 'Queued', 'running' => 'In progress', 'review' => 'Needs review', 'approved' => 'Approved', 'merged' => 'Merged locally', 'changes_requested' => 'Changes requested', 'deferred' => 'Deferred', 'rejected' => 'Rejected', 'failed' => 'Needs attention'];
    $areaLabels = ['engineering' => 'Engineering', 'content' => 'Learning & audio', 'quality' => 'Quality', 'growth' => 'Growth'];
    $reviewCount = $counts['review'] ?? 0;
    $activeCount = $counts['running'] ?? 0;
@endphp
        @if($tasks->count())
            <div class="task-list">
                @foreach($tasks as $task)
                    <a href="{{ route('tasks.show', $task) }}" class="task-row">
                        <span class="task-symbol status-symbol-{{ $task->status }}">@include('mission.icon', ['name' => $task->status === 'merged' ? 'check' : ($task->status === 'running' ? 'clock' : 'file')])</span>
                        <span class="flex-1 min-w-0"><span class="flex items-center gap-2 flex-wrap"><span class="task-title">{{ $task->title }}</span>@if(in_array($task->priority, ['urgent', 'high']))<span class="priority priority-{{ $task->priority }}">{{ ucfirst($task->priority) }}</span>@endif</span><span class="task-meta"><span>MC-{{ str_pad((string) $task->id, 3, '0', STR_PAD_LEFT) }}</span><span>·</span><span>{{ $areaLabels[$task->area] ?? ucfirst($task->area) }}</span><span>·</span><span>{{ $task->created_at->diffForHumans() }}</span></span></span>
                        <span class="status-badge status-{{ $task->status }}">{{ $statusLabels[$task->status] ?? ucfirst($task->status) }}</span>
                        <span class="text-muted task-row-arrow">@include('mission.icon', ['name' => 'chevron', 'class' => 'size-4'])</span>
                    </a>
                @endforeach
            </div>
        @else
            <div class="empty-state">
                <div class="empty-illustration" aria-hidden="true"><div class="empty-orbit"></div><div class="empty-mini-card"><span></span><span></span><span></span></div><div class="empty-check">@include('mission.icon', ['name' => 'check', 'class' => 'size-5'])</div></div>
                <p class="eyebrow">A little focus goes a long way</p>
                <h3>{{ request()->filled('status') || request()->filled('area') ? 'Nothing here just yet' : 'Your next improvement starts here' }}</h3>
                <p>{{ request()->filled('status') || request()->filled('area') ? 'No tasks match these filters. Try another view or add something new.' : 'Give your team a focused task. The implementation, evidence, and final decision will come together here.' }}</p>
                <a href="#new-task" class="button button-primary" data-open-task>@include('mission.icon', ['name' => 'plus', 'class' => 'size-4'])Create a task</a>
            </div>
        @endif
        @if($tasks->count() || $tasks->currentPage() > 1)<div class="px-5 py-4">{{ $tasks->links() }}</div>@endif
