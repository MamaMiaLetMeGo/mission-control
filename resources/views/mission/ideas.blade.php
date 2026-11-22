@extends('mission.layout')
@section('title', 'Ideas')
@section('breadcrumb', 'Ideas')
@push('head')
@if($run && in_array($run->status, ['queued', 'planning'], true))<meta http-equiv="refresh" content="5">@endif
@endpush
@section('content')
@php
    $isWorking = $run && in_array($run->status, ['queued', 'planning'], true);
    $statusLabels = ['backlog' => 'Backlog', 'queued' => 'Queued', 'running' => 'In progress', 'review' => 'Needs review', 'approved' => 'Approved', 'merged' => 'Merged locally', 'changes_requested' => 'Changes requested', 'deferred' => 'Deferred', 'rejected' => 'Rejected', 'failed' => 'Needs attention'];
@endphp
<div class="page-heading">
    <div><p class="eyebrow">From your leads</p><h1>Ideas<span class="heading-dot">.</span></h1><p class="page-description">Small pieces of work your leads propose. They are built after your own goals and wait for your review.</p></div>
    <form action="{{ route('ideas.store') }}" method="POST" class="shrink-0">@csrf<button class="button button-primary" type="submit">@include('mission.icon', ['name' => 'bulb', 'class' => 'size-4'])Get ideas now</button></form>
</div>
@if(! $ideasEnabled)
<p class="text-xs text-muted mb-6">Daily ideas are switched off in Settings.</p>
@endif
@if($run)
    @if(in_array($run->status, ['queued', 'planning'], true))
    <p class="text-xs text-muted mb-6" role="status">The leads are looking at the project. This page refreshes automatically.</p>
    @elseif($run->status === 'failed')
    <div class="setup-notice"><div class="flex-1 min-w-0"><strong>The last ideas run needs attention</strong>@if($run->error)<p>{{ $run->error }}</p>@endif</div></div>
    @elseif($run->status === 'planned')
    <section class="panel mb-6"><div class="panel-heading"><h2>Latest ideas</h2><span class="text-xs text-muted">{{ $run->created_at->diffForHumans() }}</span></div>@if($run->overview)<p class="p-6 text-sm leading-relaxed whitespace-pre-line">{{ $run->overview }}</p>@endif</section>
    @endif
@endif
<section class="panel overflow-hidden mb-6">
    <div class="panel-heading"><h2>Recent ideas</h2><span class="count-chip">{{ $tasks->count() }}</span></div>
    @if($tasks->isEmpty())
    <p class="p-6 text-sm text-muted">No ideas yet. Your leads propose some once a day.</p>
    @else
    <div class="task-list">
        @foreach($tasks as $task)
        <div class="task-row flex-wrap">
            <a href="{{ route('tasks.show', $task) }}" class="flex-1 min-w-0"><span class="task-title">{{ $task->title }}</span><span class="task-meta"><span>MC-{{ str_pad((string) $task->id, 3, '0', STR_PAD_LEFT) }}</span>@if($task->lead)<span>·</span><span>{{ $task->lead->name }}</span>@endif</span></a>
            @if($task->signal)<span class="signal-dot signal-{{ $task->signal }}" title="{{ $task->signal_reason }}"><span class="sr-only">{{ ucfirst($task->signal) }}: {{ $task->signal_reason }}</span></span>@endif
            <span class="status-badge status-{{ $task->status }}">{{ $statusLabels[$task->status] ?? ucfirst($task->status) }}</span>
            @if($task->status === 'backlog' && ! $isWorking)
            <form action="{{ route('tasks.decide', $task) }}" method="POST" class="flex items-center gap-2">@csrf
                <input type="hidden" name="action" value="reject"><input type="hidden" name="idea" value="{{ $task->id }}">
                <div class="field"><label for="dismiss-feedback-{{ $task->id }}" class="sr-only">Why not?</label><input id="dismiss-feedback-{{ $task->id }}" name="feedback" value="{{ (string) old('idea') === (string) $task->id ? old('feedback') : '' }}" maxlength="10000" required placeholder="Why not? This teaches the Brain."></div>
                <button class="button button-small button-quiet" type="submit">Dismiss</button>
            </form>
            <form action="{{ route('tasks.run', $task) }}" method="POST">@csrf<button class="button button-small button-secondary" type="submit">Start now</button></form>
            @endif
        </div>
        @endforeach
    </div>
    @endif
</section>
@endsection
