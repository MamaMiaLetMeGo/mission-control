@extends('mission.layout')
@section('title', 'Decision journal')
@section('breadcrumb', 'Decision journal')
@section('content')
<div class="page-heading"><div><p class="eyebrow">Your judgment, with context</p><h1>Decision journal<span class="heading-dot">.</span></h1><p class="page-description">A record of what you decided, and the reasons behind it.</p></div></div>
<div class="journal-intro">@include('mission.icon', ['name' => 'profile', 'class' => 'size-6 text-accent shrink-0'])<div><h2>Good decisions leave a trail.</h2><p>Feedback is preserved with the task and reviewed revision. These are your recorded decisions; no preferences are inferred automatically.</p></div></div>
<section class="panel"><div class="panel-heading"><h2>Recorded decisions</h2><span class="count-chip">{{ $decisions->total() }}</span></div>
@forelse($decisions as $decision)
<article class="journal-entry"><span class="journal-action-icon">@include('mission.icon', ['name' => $decision->action === 'approve' ? 'check' : 'profile'])</span><div class="flex-1 min-w-0"><div class="flex items-center justify-between gap-3 flex-wrap"><h3 class="font-semibold text-sm">{{ ['approve' => 'Approved for merge', 'request_changes' => 'Changes requested', 'defer' => 'Deferred for later', 'reject' => 'Task rejected', 'merge' => 'Merged locally'][$decision->action] ?? ucfirst(str_replace('_', ' ', $decision->action)) }}</h3><time class="text-xs text-muted">{{ $decision->created_at->format('M j, Y · H:i') }}</time></div>@if($decision->task)<a class="text-link mt-2" href="{{ route('tasks.show', $decision->task) }}">{{ $decision->task->title }} @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3.5'])</a>@endif<p class="prose-text mt-3">{{ $decision->feedback ?: 'No additional feedback was recorded.' }}</p>@if($decision->revision)<code class="inline-block text-xs text-muted mt-3">Revision {{ substr($decision->revision, 0, 12) }}</code>@endif</div></article>
@empty
<div class="empty-state py-16"><span class="empty-simple-icon">@include('mission.icon', ['name' => 'profile', 'class' => 'size-8'])</span><h3>Your first decision belongs here</h3><p>Approve a completed task or leave feedback on what should change. Its context will be saved for the next time.</p><a href="{{ route('dashboard') }}" class="button button-secondary">Go to review queue @include('mission.icon', ['name' => 'arrow', 'class' => 'size-4'])</a></div>
@endforelse
@if($decisions->hasPages())<div class="p-5 border-t border-line">{{ $decisions->links() }}</div>@endif
</section>
@endsection
