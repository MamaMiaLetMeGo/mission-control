@extends('mission.layout')
@section('title', 'Brain')
@section('breadcrumb', 'Brain')
@push('head')
@if($run && in_array($run->status, ['queued', 'learning'], true))<meta http-equiv="refresh" content="5">@endif
@endpush
@section('content')
@php
    $actionLabels = ['approve' => 'Approved for merge', 'request_changes' => 'Changes requested', 'defer' => 'Deferred for later', 'reject' => 'Task rejected', 'merge' => 'Merged locally'];
    $runLabels = ['queued' => 'Waiting', 'learning' => 'Learning', 'completed' => 'Done', 'failed' => 'Needs attention'];
    $runClasses = ['queued' => 'status-queued', 'learning' => 'status-running', 'completed' => 'status-completed', 'failed' => 'status-failed'];
    $usage = $run?->usage;
    $tokens = is_array($usage) ? (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0) : null;
    $formFor = fn ($marker) => (string) old('brain_form') === (string) $marker;
@endphp
<div class="page-heading"><div><p class="eyebrow">What your team has learned</p><h1>Brain<span class="heading-dot">.</span></h1><p class="page-description">What your team has learned about how you like things done. Everything here is added to your agents' instructions.</p></div></div>

<section class="panel mb-6" aria-labelledby="learning-title">
    <div class="panel-heading"><span class="flex items-center gap-2">@include('mission.icon', ['name' => 'spark', 'class' => 'size-4 text-accent'])<h2 id="learning-title">Learning</h2></span>@if($run)<span class="status-badge {{ $runClasses[$run->status] ?? '' }}">{{ $runLabels[$run->status] ?? ucfirst($run->status) }}</span>@endif</div>
    <div class="p-6 space-y-4">
        @if($run)
            @if($run->status === 'failed' && $run->error)<p class="text-sm text-[#a55d68]" role="alert">{{ $run->error }}</p>@endif
            @if($run->summary)<p class="text-sm leading-relaxed whitespace-pre-line">{{ $run->summary }}</p>@endif
            <p class="text-xs text-muted">@if($run->finished_at ?? $run->started_at)Last run {{ ($run->finished_at ?? $run->started_at)->diffForHumans() }}@else Last run queued {{ $run->created_at->diffForHumans() }}@endif @if($tokens !== null)· {{ number_format($tokens) }} tokens used @endif</p>
        @else
            <p class="text-sm text-muted">The Brain has not learned anything yet.</p>
        @endif
        <form action="{{ route('brain.learn') }}" method="POST" class="flex flex-wrap items-center justify-between gap-4">@csrf
            <p class="text-xs text-muted">{{ $waiting }} of {{ $learnAfter }} decisions before the next learning run</p>
            <button class="button button-primary" type="submit">Learn now</button>
        </form>
    </div>
</section>

<section class="panel mb-6" aria-labelledby="add-title">
    <div class="panel-heading"><h2 id="add-title">Add a preference</h2></div>
    <form action="{{ route('brain.store') }}" method="POST" class="p-6 space-y-4">@csrf
        <input type="hidden" name="brain_form" value="new">
        <div class="field"><label for="new-preference-text">Preference</label><input id="new-preference-text" name="text" value="{{ $formFor('new') ? old('text') : '' }}" maxlength="300" required placeholder="e.g. Keep copy short and in plain words.">@if($formFor('new') && $errors->has('text'))<p class="text-xs text-[#a55d68]" role="alert">{{ $errors->first('text') }}</p>@endif</div>
        <div class="field"><label for="new-preference-lead">Applies to</label><select id="new-preference-lead" name="lead"><option value="">All leads</option>@foreach($leads as $lead)<option value="{{ $lead->key }}" @selected($formFor('new') && old('lead') === $lead->key)>{{ $lead->name }}</option>@endforeach</select>@if($formFor('new') && $errors->has('lead'))<p class="text-xs text-[#a55d68]" role="alert">{{ $errors->first('lead') }}</p>@endif</div>
        <div class="flex justify-end"><button class="button button-primary" type="submit">Add preference</button></div>
    </form>
</section>

<section class="panel mb-6" aria-labelledby="active-title">
    <div class="panel-heading"><h2 id="active-title">Active</h2><span class="count-chip">{{ $active->count() }} of {{ $maxActive }}</span></div>
    @forelse($active as $preference)
        @include('mission.brain-preference', ['preference' => $preference, 'off' => false])
    @empty
        <p class="p-6 text-sm text-muted">No active preferences yet. Add one above, or let the Brain learn them from your decisions.</p>
    @endforelse
</section>

@if($off->isNotEmpty())
<section class="panel mb-6" aria-labelledby="off-title">
    <div class="panel-heading"><h2 id="off-title">Switched off</h2><span class="count-chip">{{ $off->count() }}</span></div>
    @foreach($off as $preference)
        @include('mission.brain-preference', ['preference' => $preference, 'off' => true])
    @endforeach
</section>
@endif
@endsection
