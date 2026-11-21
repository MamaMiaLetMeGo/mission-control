@extends('mission.layout')
@section('title', 'Goal')
@section('breadcrumb', 'Goal')
@push('head')
@if(in_array($goal->status, ['queued', 'planning'], true))<meta http-equiv="refresh" content="5">@endif
@endpush
@section('content')
@php
    $statusLabels = ['backlog' => 'Backlog', 'queued' => 'Queued', 'running' => 'In progress', 'review' => 'Needs review', 'approved' => 'Approved', 'merged' => 'Merged locally', 'changes_requested' => 'Changes requested', 'deferred' => 'Deferred', 'rejected' => 'Rejected', 'failed' => 'Needs attention'];
    $goalLabels = ['queued' => 'Waiting', 'planning' => 'Planning', 'planned' => 'Planned', 'failed' => 'Needs attention'];
    $usage = $goal->usage;
    $tokens = is_array($usage) ? (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0) : null;
    $groups = $tasks->groupBy(fn ($task) => $task->lead?->name ?? 'Unassigned');
@endphp
<a href="{{ route('dashboard') }}" class="text-link text-muted mb-7">@include('mission.icon', ['name' => 'back', 'class' => 'size-4'])Back to queue</a>
<div class="page-heading items-start">
    <div class="min-w-0"><div class="flex flex-wrap items-center gap-3 mb-3"><p class="eyebrow mb-0">Goal</p><span class="status-badge status-{{ $goal->status }}">{{ $goalLabels[$goal->status] ?? ucfirst($goal->status) }}</span></div><h1 class="task-page-title">{{ $goal->request }}</h1>@if($tokens !== null)<p class="page-description">{{ number_format($tokens) }} tokens used planning</p>@endif</div>
</div>
@if($goal->status === 'failed' && $goal->error)
<div class="setup-notice"><div class="flex-1 min-w-0"><strong>The plan needs attention</strong><p>{{ $goal->error }}</p></div></div>
@endif
@if($goal->overview)
<section class="panel mb-6"><div class="panel-heading"><h2>Overview</h2></div><p class="p-6 text-sm leading-relaxed whitespace-pre-line">{{ $goal->overview }}</p></section>
@endif
@if(in_array($goal->status, ['queued', 'planning'], true))
<p class="text-xs text-muted mb-6" role="status">The Chief of Staff is working on this. This page refreshes automatically.</p>
@endif
@foreach($groups as $leadName => $leadTasks)
<section class="panel overflow-hidden mb-6">
    <div class="panel-heading"><h2>{{ $leadName }}</h2><span class="count-chip">{{ $leadTasks->count() }}</span></div>
    <div class="task-list">
        @foreach($leadTasks as $task)
        <a href="{{ route('tasks.show', $task) }}" class="task-row">
            <span class="flex-1 min-w-0"><span class="task-title">{{ $task->title }}</span><span class="task-meta"><span>MC-{{ str_pad((string) $task->id, 3, '0', STR_PAD_LEFT) }}</span>@foreach($task->waitingOn() as $dependency)<span>·</span><span>Waiting for MC-{{ str_pad((string) $dependency->id, 3, '0', STR_PAD_LEFT) }}</span>@endforeach</span></span>
            @if($task->signal)<span class="signal-dot signal-{{ $task->signal }}" title="{{ $task->signal_reason }}"><span class="sr-only">{{ ucfirst($task->signal) }}: {{ $task->signal_reason }}</span></span>@endif
            <span class="status-badge status-{{ $task->status }}">{{ $statusLabels[$task->status] ?? ucfirst($task->status) }}</span>
            <span class="text-muted task-row-arrow">@include('mission.icon', ['name' => 'chevron', 'class' => 'size-4'])</span>
        </a>
        @endforeach
    </div>
</section>
@endforeach
@endsection
