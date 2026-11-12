@extends('mission.layout')
@section('title', 'Review queue')
@section('content')
@php
    $statusLabels = ['backlog' => 'Backlog', 'queued' => 'Queued', 'running' => 'In progress', 'review' => 'Needs review', 'approved' => 'Approved', 'merged' => 'Merged locally', 'changes_requested' => 'Changes requested', 'deferred' => 'Deferred', 'rejected' => 'Rejected', 'failed' => 'Needs attention'];
    $areaLabels = ['engineering' => 'Engineering', 'content' => 'Learning & audio', 'quality' => 'Quality', 'growth' => 'Growth'];
    $reviewCount = $counts['review'] ?? 0;
    $activeCount = $counts['running'] ?? 0;
@endphp
<div data-queue-monitor data-status-url="{{ route('queue.status') }}">
<div class="page-heading">
    <div><p class="eyebrow">Local workspace</p><h1>Review queue<span class="heading-dot">.</span></h1><p class="page-description">One place for the work. A clear next decision for you.</p></div>
    <a class="button button-primary shrink-0" href="#new-task" data-open-task>@include('mission.icon', ['name' => 'plus', 'class' => 'size-4'])New task</a>
</div>
<div data-queue-stats>@include('mission.queue-stats')</div>
<div class="flex flex-wrap justify-between gap-2 text-[11px] text-muted mb-5"><p data-queue-update-status role="status">Queue updates automatically.</p><time data-queue-checked></time></div>
@include('mission.repository-sync')
@if(! ($readiness['ready'] ?? false))
<div class="setup-notice">
    <span class="notice-symbol">@include('mission.icon', ['name' => 'settings'])</span>
    <div class="flex-1 min-w-0"><strong>Let’s get your team ready</strong><p>You can add tasks now. Complete setup before starting a Codex run.</p></div>
    <a class="text-link shrink-0" href="{{ route('settings.edit') }}">Finish setup @include('mission.icon', ['name' => 'arrow', 'class' => 'size-4'])</a>
</div>
@endif
<div class="dashboard-grid">
    <section class="panel overflow-hidden" aria-labelledby="tasks-title">
        <div class="panel-heading"><div class="flex items-center gap-2"><h2 id="tasks-title">Your tasks</h2><span class="count-chip" data-queue-total>{{ array_sum($counts) }}</span></div><span class="text-xs text-muted">Urgent work first</span></div>
        <form class="filter-bar" action="{{ route('dashboard') }}" method="GET" aria-label="Filter tasks">
            <div class="filter-field"><label for="status-filter" class="sr-only">Task status</label><select id="status-filter" name="status"><option value="">All statuses</option>@foreach($statusLabels as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="filter-field"><label for="area-filter" class="sr-only">Focus area</label><select id="area-filter" name="area"><option value="">All focus areas</option>@foreach($areaLabels as $value => $label)<option value="{{ $value }}" @selected(request('area') === $value)>{{ $label }}</option>@endforeach</select></div>
            <button type="submit" class="button button-small button-quiet">Filter</button>
            @if(request()->filled('status') || request()->filled('area'))<a href="{{ route('dashboard') }}" class="text-xs text-muted underline underline-offset-4">Clear</a>@endif
        </form>
        <div data-queue-list>@include('mission.queue-list')</div>
        <div class="panel-footnote">@include('mission.icon', ['name' => 'shield', 'class' => 'size-4'])Your approval is tied to the exact code you review.</div>
    </section>
    <aside class="flex flex-col gap-5">
        <section class="workflow-card"><div class="flex items-center justify-between mb-5"><p class="eyebrow mb-0">From idea to approved</p>@include('mission.icon', ['name' => 'spark', 'class' => 'size-4 text-accent'])</div><h2>A small, dependable<br>loop.</h2><p class="text-sm text-muted leading-relaxed mt-3">You set the direction.<br>Your team brings back the evidence.</p><ol class="workflow-steps"><li><span>01</span><div><strong>Define the task</strong><p>A clear outcome and acceptance criteria.</p></div></li><li><span>02</span><div><strong>Build & verify</strong><p>Isolated work, tests, and an independent review.</p></div></li><li><span>03</span><div><strong>Make the call</strong><p>Approve, request changes, or revisit later.</p></div></li></ol></section>
        <section class="small-note"><span class="text-accent">@include('mission.icon', ['name' => 'profile'])</span><div><h3>Good judgment compounds</h3><p>Your decisions and their reasons are saved with each task.</p><a href="{{ route('profile') }}" class="text-link mt-3">Open decision journal @include('mission.icon', ['name' => 'arrow', 'class' => 'size-4'])</a></div></section>
    </aside>
</div>
<details id="new-task" class="panel new-task-panel mt-6" @if(old('title') || $errors->has('title') || $errors->has('request') || $errors->has('acceptance_criteria')) open @endif>
    <summary class="panel-heading cursor-pointer"><span class="flex items-center gap-2">@include('mission.icon', ['name' => 'plus', 'class' => 'size-4 text-accent'])<strong>Create a task</strong></span><span class="text-xs text-muted">Start with one clear outcome</span></summary>
    <form action="{{ route('tasks.store') }}" method="POST" class="p-6 space-y-5">
        @csrf
        <div class="field"><label for="task-title">Task title</label><input id="task-title" name="title" value="{{ old('title') }}" maxlength="200" required placeholder="e.g. Make lesson progress easier to understand"></div>
        <div class="grid gap-5 md:grid-cols-2"><div class="field"><label for="task-request">What should change?</label><textarea id="task-request" name="request" rows="5" maxlength="20000" required placeholder="Describe the problem, who it affects, and the outcome you want.">{{ old('request') }}</textarea></div><div class="field"><label for="task-criteria">Acceptance criteria</label><textarea id="task-criteria" name="acceptance_criteria" rows="5" maxlength="10000" required placeholder="What must be true for this task to be done? Include examples or checks.">{{ old('acceptance_criteria') }}</textarea></div></div>
        <div class="grid gap-5 md:grid-cols-2"><div class="field"><label for="task-area">Focus area</label><select id="task-area" name="area">@foreach($areaLabels as $value => $label)<option value="{{ $value }}" @selected(old('area', 'engineering') === $value)>{{ $label }}</option>@endforeach</select></div><div class="field"><label for="task-priority">Priority</label><select id="task-priority" name="priority">@foreach(['low', 'normal', 'high', 'urgent'] as $priority)<option value="{{ $priority }}" @selected(old('priority', 'normal') === $priority)>{{ ucfirst($priority) }}</option>@endforeach</select></div></div>
        <div class="flex flex-wrap items-center justify-between gap-4 pt-2"><p class="text-xs text-muted">Saved to your backlog. You choose when work starts.</p><button class="button button-primary" type="submit">Add to backlog @include('mission.icon', ['name' => 'arrow', 'class' => 'size-4'])</button></div>
    </form>
</details>
</div>
@endsection
