@extends('mission.layout')
@section('title', $task->title)
@section('breadcrumb', 'Task details')
@section('content')
<div @if($taskProgress['active'] ?? false) data-task-monitor data-status-url="{{ route('tasks.progress', $task) }}" data-run-id="{{ $taskProgress['run_id'] }}" data-revision="{{ $taskProgress['revision'] ?? '' }}" @endif>
    @if($taskProgress['active'] ?? false)<div class="flex flex-wrap justify-between gap-2 text-[11px] text-muted mb-5"><p data-task-update-status role="status">Progress updates automatically.</p><time data-task-checked></time></div>@endif
    @include('mission.task-content')
</div>
@endsection
