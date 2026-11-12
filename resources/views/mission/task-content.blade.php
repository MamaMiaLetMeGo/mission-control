@php
    $statusLabels = ['backlog' => 'Backlog', 'queued' => 'Queued', 'running' => 'In progress', 'review' => 'Needs review', 'approved' => 'Approved for merge', 'merged' => 'Merged locally', 'changes_requested' => 'Changes requested', 'deferred' => 'Deferred', 'rejected' => 'Rejected', 'failed' => 'Needs attention'];
    $review = $task->review_data ?? [];
    $testEvidence = $review['tests'] ?? null;
    $independentReview = $review['review'] ?? null;
    $canRun = in_array($task->status, ['backlog', 'failed', 'changes_requested', 'deferred']);
    $canDecide = ! in_array($task->status, ['queued', 'running', 'merged', 'rejected']);
    $canRequestChanges = in_array($task->status, ['review', 'approved', 'failed']);
    $latestRun = $task->runs->first();
    $runSettings = $latestRun?->settings ?? [];
    $browser = $review['browser'] ?? [];
    $captureRequested = (bool) ($runSettings['preview_enabled'] ?? false);
    $captures = collect($browser['screenshots'] ?? [])->filter(fn ($capture) => is_array($capture) && isset($capture['file'], $capture['path'], $capture['phase'], $capture['viewport']) && in_array($capture['phase'], ['before', 'after'], true) && in_array($capture['viewport'], ['desktop', 'mobile'], true));
    $capturePaths = collect($captureRequested ? ($runSettings['preview_paths'] ?? ['/']) : [])->merge($captures->pluck('path'))->filter(fn ($path) => is_string($path))->unique()->values();
    $browserRevisionMatches = $task->revision && ($browser['revision'] ?? null) === $task->revision && ($browser['baseRevision'] ?? null) === $task->base_revision;
    $capturesComplete = $capturePaths->isNotEmpty() && $capturePaths->every(function ($path) use ($captures, $task) {
        foreach (['desktop', 'mobile'] as $viewport) {
            foreach (['before', 'after'] as $phase) {
                $revision = $phase === 'before' ? $task->base_revision : $task->revision;
                if (! $captures->contains(fn ($capture) => $capture['path'] === $path && $capture['viewport'] === $viewport && $capture['phase'] === $phase && ($capture['revision'] ?? null) === $revision)) {
                    return false;
                }
            }
        }

        return true;
    });
    $browserPassed = ($browser['status'] ?? null) === 'passed' && $browserRevisionMatches && $capturesComplete;
    $canApprove = in_array($task->status, ['review', 'deferred']) && $task->revision && ($testEvidence['exitCode'] ?? null) === 0 && ($independentReview['verdict'] ?? null) === 'pass' && (! $captureRequested || $browserPassed);
    $canPreview = $captureRequested && $task->revision && in_array($task->status, ['review', 'approved', 'deferred', 'failed'], true);
    $livePreview = $canPreview && ($preview['revision'] ?? null) === $task->revision ? $preview : null;
@endphp
<div data-task-content data-run-id="{{ $latestRun?->id ?? '' }}" data-task-status="{{ $task->status }}" data-revision="{{ $task->revision ?? '' }}">
<a href="{{ route('dashboard') }}" class="text-link text-muted mb-7">@include('mission.icon', ['name' => 'back', 'class' => 'size-4'])Back to queue</a>
<div class="page-heading items-start" data-task-live="heading">
    <div class="min-w-0"><div class="flex flex-wrap items-center gap-3 mb-3"><p class="eyebrow mb-0">MC-{{ str_pad((string) $task->id, 3, '0', STR_PAD_LEFT) }}</p><span class="status-badge status-{{ $task->status }}">{{ $statusLabels[$task->status] ?? ucfirst($task->status) }}</span></div><h1 class="task-page-title">{{ $task->title }}</h1><p class="page-description">{{ ucfirst($task->area) }} <span class="px-1 text-line">/</span> {{ ucfirst($task->priority) }} priority <span class="px-1 text-line">/</span> Added {{ $task->created_at->diffForHumans() }}</p></div>
    @if(in_array($task->status, ['queued', 'running']))<a href="{{ route('tasks.show', $task) }}" class="button button-secondary shrink-0">@include('mission.icon', ['name' => 'refresh', 'class' => 'size-4'])Refresh status</a>@endif
</div>
<div data-task-live="phase">
@if(in_array($task->status, ['queued', 'running']))
    @php
        $latestPhase = collect($latestRun?->events ?? [])->last(fn ($event) => ($event['type'] ?? null) === 'phase');
    @endphp
    <div class="notice mb-6"><span class="shrink-0">@include('mission.icon', ['name' => 'clock', 'class' => 'size-4'])</span><p>{{ $latestPhase['message'] ?? ($task->status === 'queued' ? 'Waiting for the local worker to start.' : 'Your team is working on this task.') }}</p></div>
@endif
</div>
@if($task->error)<div class="notice notice-error mb-6" role="alert"><span class="shrink-0">@include('mission.icon', ['name' => 'alert'])</span><div><strong class="block mb-1">This task needs attention</strong><p class="whitespace-pre-wrap break-words">{{ $task->error }}</p></div></div>@endif
@if($task->status === 'merged')
<div data-publication-monitor data-poll-url="{{ route('tasks.publications.poll', $task) }}" data-revision="{{ $task->revision }}" data-plan-hash="{{ $productionPublication['plan']['hash'] ?? '' }}">
    @include('mission.staging-publication')
    @if(($publication['state'] ?? null) === 'merged' || ! empty($task->production_publication))
        @include('mission.production-publication')
    @endif
    <script type="application/json" data-publication-polling>@json($publicationPolling ?? [])</script>
</div>
@endif
<div class="task-detail-grid">
    <div class="flex flex-col gap-6 min-w-0">
        <section class="panel"><div class="panel-heading"><h2>The task</h2><span class="text-muted">@include('mission.icon', ['name' => 'file', 'class' => 'size-4'])</span></div><div class="p-6 flex flex-col gap-6"><div><h3 class="section-label">Requested outcome</h3><p class="prose-text">{{ $task->request }}</p></div><div class="criteria-block"><h3 class="section-label">Acceptance criteria</h3><p class="prose-text">{{ $task->acceptance_criteria }}</p></div></div></section>
        <section class="panel"><div class="panel-heading"><h2>Review package</h2><span class="text-muted">@include('mission.icon', ['name' => 'shield', 'class' => 'size-4'])</span></div>
            @if($task->recommendation || $review)
            <div class="p-6 flex flex-col gap-6">
                @if($task->recommendation || ! empty($review['summary']))
                    <details class="evidence-details">
                        <summary>Implementation notes</summary>
                        <div class="mt-3"><p class="text-[11px] text-muted mb-2">Written before automated verification.</p><p class="prose-text">{{ $task->recommendation ?: $review['summary'] }}</p></div>
                    </details>
                @endif
                @if($independentReview)
                <div class="evidence-block"><div class="flex items-center justify-between gap-4"><h3 class="font-semibold text-sm">Independent review</h3><span class="status-badge {{ ($independentReview['verdict'] ?? '') === 'pass' ? 'status-merged' : 'status-changes_requested' }}">{{ ($independentReview['verdict'] ?? '') === 'pass' ? 'Passed' : 'Changes requested' }}</span></div><p class="prose-text mt-3">{{ $independentReview['summary'] ?? 'No review summary was provided.' }}</p>@if(! empty($independentReview['findings']))<ul class="mt-3 text-sm list-disc pl-5 space-y-2 text-muted">@foreach($independentReview['findings'] as $finding)<li>{{ is_string($finding) ? $finding : json_encode($finding) }}</li>@endforeach</ul>@endif</div>
                @else<p class="text-sm text-muted">No independent review has been recorded.</p>@endif
                @if($testEvidence)
                <div class="evidence-block"><div class="flex items-center justify-between gap-4"><h3 class="font-semibold text-sm">Test verification</h3><span class="status-badge {{ ($testEvidence['exitCode'] ?? null) === 0 ? 'status-merged' : 'status-failed' }}">{{ ($testEvidence['exitCode'] ?? null) === 0 ? 'Passed' : 'Not passing' }}</span></div><code class="block text-xs text-muted break-all mt-3">{{ implode(' ', $testEvidence['command'] ?? []) }}</code><details class="evidence-details mt-4"><summary>View test output</summary><pre class="log-output mt-3">{{ $testEvidence['output'] ?? 'No test output was captured.' }}</pre></details></div>
                @else<p class="text-sm text-muted">No test results have been recorded.</p>@endif
                @if($task->artifact_path && $task->revision)
                <div class="flex flex-wrap gap-2">
                    <a class="button button-secondary button-small" href="{{ route('tasks.artifact', ['task' => $task, 'file' => 'diff.patch']) }}">@include('mission.icon', ['name' => 'file', 'class' => 'size-3.5'])Download code diff</a>
                    @if($testEvidence)<a class="button button-secondary button-small" href="{{ route('tasks.artifact', ['task' => $task, 'file' => 'tests.txt']) }}">Test output</a>@endif
                    @if($independentReview)<a class="button button-secondary button-small" href="{{ route('tasks.artifact', ['task' => $task, 'file' => 'review.json']) }}">Review record</a>@endif
                </div>
                @endif
                @if($task->revision)<div class="flex flex-col gap-2"><h3 class="section-label mb-0">Reviewed revision</h3><code class="revision-code">{{ $task->revision }}</code></div>@endif
            </div>
            @else
            <div class="p-8 text-center"><span class="mx-auto mb-4 flex size-11 items-center justify-center rounded-xl bg-canvas text-muted">@include('mission.icon', ['name' => 'queue'])</span><h3 class="font-semibold text-sm">Evidence will arrive here</h3><p class="mx-auto max-w-sm mt-2 text-sm text-muted leading-relaxed">When the task finishes, you’ll see the recommendation, test results, and independent reviewer’s findings.</p></div>
            @endif
        </section>
        <section class="panel overflow-hidden" aria-labelledby="screenshots-title">
            <div class="panel-heading">
                <div><h2 id="screenshots-title">Before & after</h2><p class="text-xs text-muted mt-1">Desktop and mobile, tied to the reviewed code.</p></div>
                @if($browserPassed)
                    <span class="status-badge status-merged">Captured</span>
                @elseif($captureRequested && $review)
                    <span class="status-badge status-failed">Capture incomplete</span>
                @else
                    <span class="status-badge">{{ $captureRequested ? 'Pending' : ($latestRun ? 'Not requested' : 'No run yet') }}</span>
                @endif
            </div>
            <div class="p-6 flex flex-col gap-5">
                @if($captureRequested && $review && ! $browserPassed)
                    <div class="notice notice-error text-xs" role="status">
                        <span class="shrink-0">@include('mission.icon', ['name' => 'alert', 'class' => 'size-4'])</span>
                        <div><strong class="block">Browser verification needs attention</strong><p class="whitespace-pre-wrap break-words mt-1">{{ $browser['error'] ?? (! $browserRevisionMatches && ($browser['status'] ?? null) === 'passed' ? 'These captures do not match the current task revision. Run the task again to capture its current code.' : 'The run did not capture all required screenshots. Any available images are shown below.') }}</p></div>
                    </div>
                @elseif(! $captureRequested)
                    <p class="text-sm text-muted leading-relaxed">{{ $latestRun ? 'Screenshots and a working preview were not requested for this run.' : 'Before-and-after screenshots will appear after a run with browser capture enabled.' }} <a href="{{ route('settings.edit') }}#browser-settings" class="text-link">Configure browser capture</a></p>
                @endif
                @if($captures->isNotEmpty() || ($captureRequested && $review))
                    @foreach($capturePaths as $capturePath)
                        <div class="capture-route">
                            <div class="flex items-center justify-between gap-3 mb-3"><h3 class="text-sm font-semibold min-w-0 break-all">{{ $capturePath }}</h3><span class="text-[10px] text-muted shrink-0">{{ $captures->where('path', $capturePath)->count() }} captures</span></div>
                            @foreach(['desktop', 'mobile'] as $viewport)
                                <details class="capture-viewport" @if($viewport === 'desktop') open @endif>
                                    <summary><span>{{ ucfirst($viewport) }}</span><span class="flex items-center gap-2 text-[10px] font-normal text-muted">Before / After @include('mission.icon', ['name' => 'chevron', 'class' => 'size-3 capture-expand'])</span></summary>
                                    <div class="capture-pair">
                                        @foreach(['before', 'after'] as $phase)
                                            @php
                                                $capture = $captures->first(fn ($item) => $item['path'] === $capturePath && $item['viewport'] === $viewport && $item['phase'] === $phase);
                                                $captureRevision = $phase === 'before' ? $task->base_revision : $task->revision;
                                                $captureMatchesRevision = $capture && ($capture['revision'] ?? null) === $captureRevision;
                                            @endphp
                                            <figure class="capture-card">
                                                <figcaption class="capture-caption"><strong>{{ ucfirst($phase) }}</strong><span class="text-muted font-mono">{{ substr($capture['revision'] ?? $captureRevision ?? '', 0, 8) }}</span></figcaption>
                                                @if($capture && $captureMatchesRevision)
                                                    <a class="capture-image-link {{ $viewport === 'mobile' ? 'capture-mobile' : '' }}" href="{{ route('tasks.screenshot', ['task' => $task, 'file' => $capture['file'], 'sha256' => $capture['sha256'] ?? null]) }}" target="_blank" rel="noopener noreferrer" aria-label="Open {{ $phase }} {{ $viewport }} screenshot of {{ $capturePath }} in a new tab">
                                                        <img src="{{ route('tasks.screenshot', ['task' => $task, 'file' => $capture['file'], 'sha256' => $capture['sha256'] ?? null]) }}" alt="{{ ucfirst($phase) }} changes: {{ $viewport }} view of {{ $capturePath }}" loading="lazy" decoding="async" @if(isset($capture['width'], $capture['height'])) width="{{ (int) $capture['width'] }}" height="{{ (int) $capture['height'] }}" @endif>
                                                    </a>
                                                    <div class="capture-image-meta"><span>{{ $capture['width'] ?? '—' }} × {{ $capture['height'] ?? '—' }}</span><a href="{{ route('tasks.screenshot', ['task' => $task, 'file' => $capture['file'], 'sha256' => $capture['sha256'] ?? null]) }}" target="_blank" rel="noopener noreferrer" class="text-link text-[10px]" aria-label="Open full {{ $phase }} {{ $viewport }} screenshot of {{ $capturePath }}">Open full image @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3'])</a></div>
                                                @else
                                                    <div class="capture-missing">@include('mission.icon', ['name' => 'file', 'class' => 'size-6'])<p>{{ $capture ? 'Capture belongs to a different revision' : 'Screenshot not captured' }}</p></div>
                                                @endif
                                            </figure>
                                        @endforeach
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    @endforeach
                @endif
                @if($browserPassed)<p class="text-[11px] text-muted leading-relaxed">Before: <code>{{ substr($task->base_revision ?? '', 0, 12) }}</code> · After: <code>{{ substr($task->revision ?? '', 0, 12) }}</code>. Open an image to inspect it at full size.</p>@endif
            </div>
        </section>
        <section class="panel" data-task-live="runs"><div class="panel-heading"><h2>Run history</h2><span class="count-chip">{{ $task->runs->count() }}</span></div>
            @forelse($task->runs as $run)
            <details class="run-row" data-task-detail="run-{{ $run->id }}"><summary><span class="flex items-center gap-3 min-w-0"><span class="role-avatar role-engineering">E</span><span><strong class="block text-sm">{{ ucwords(str_replace('_', ' ', $run->role ?? 'Engineering')) }}</strong><span class="text-xs text-muted">{{ $run->started_at?->diffForHumans() ?? 'Waiting to start' }}</span></span></span><span class="status-badge status-{{ $run->status }}">{{ ucfirst(str_replace('_', ' ', $run->status)) }}</span></summary><div class="run-detail">@if($run->thread_id)<p class="text-xs text-muted break-all mb-3">Session {{ $run->thread_id }}</p>@endif @if($run->error)<p class="text-sm text-danger whitespace-pre-wrap mb-3">{{ $run->error }}</p>@endif @if($run->usage)<div class="flex gap-4 flex-wrap text-xs text-muted mb-3"><span>Input tokens: {{ number_format($run->usage['input_tokens'] ?? 0) }}</span><span>Output tokens: {{ number_format($run->usage['output_tokens'] ?? 0) }}</span></div>@endif<pre class="log-output" data-task-log="run-{{ $run->id }}">@forelse(($run->events ?? []) as $event){{ $event['message'] ?? ($event['type'] ?? 'Event') }}{{ "\n" }}@empty
No run events recorded.
@endforelse</pre></div></details>
            @empty<p class="px-6 py-5 text-sm text-muted">No runs yet. Start the task when you’re ready.</p>@endforelse
        </section>
        @if($task->decisions->count())<section class="panel"><div class="panel-heading"><h2>Decision history</h2></div><div class="divide-y divide-line">@foreach($task->decisions as $decision)<div class="p-6"><div class="flex items-center justify-between gap-4"><strong class="text-sm">{{ ucwords(str_replace('_', ' ', $decision->action)) }}</strong><time class="text-xs text-muted">{{ $decision->created_at->diffForHumans() }}</time></div>@if($decision->feedback)<p class="prose-text mt-2">{{ $decision->feedback }}</p>@endif @if($decision->revision)<p class="text-xs text-muted mt-3 font-mono break-all">{{ substr($decision->revision, 0, 12) }}</p>@endif</div>@endforeach</div></section>@endif
    </div>
    <aside class="flex flex-col gap-5">
        <section class="panel decision-panel" data-task-live="next-step"><div class="panel-heading"><h2>Your next step</h2></div><div class="p-5 flex flex-col gap-4">
            @if($canRun)
                <p class="text-sm text-muted leading-relaxed">Start a focused Codex run. The team will return the changes and evidence for your review.</p>
                <form method="POST" action="{{ route('tasks.run', $task) }}">@csrf<button type="submit" class="button button-primary w-full" @disabled(! ($readiness['ready'] ?? false))>@include('mission.icon', ['name' => 'spark', 'class' => 'size-4']){{ $task->runs->count() ? 'Start a new run' : 'Start task' }}</button></form>
                @if(! ($readiness['ready'] ?? false))<a href="{{ route('settings.edit') }}" class="text-link text-xs">Complete setup to start @include('mission.icon', ['name' => 'arrow', 'class' => 'size-4'])</a>@endif
            @elseif(in_array($task->status, ['queued', 'running']))
                <div class="flex gap-3 items-start"><span class="text-accent">@include('mission.icon', ['name' => 'clock'])</span><div><strong class="block text-sm">{{ $task->status === 'queued' ? 'Waiting for the worker' : 'Your team is working' }}</strong><p class="text-sm text-muted leading-relaxed mt-2">{{ $task->status === 'queued' ? 'The local queue worker will pick up this task.' : 'Progress and the completed review appear automatically here.' }}</p></div></div>
            @elseif($task->status === 'merged')
                <span class="success-icon">@include('mission.icon', ['name' => 'check'])</span><strong class="text-sm">Merged locally</strong><p class="text-sm text-muted leading-relaxed">This task’s approved changes are now on the local base branch.</p>
            @elseif($task->status === 'rejected')
                <strong class="text-sm">Task closed</strong><p class="text-sm text-muted leading-relaxed">Your decision and feedback are preserved in the journal.</p>
            @endif
            @if($canApprove)
                <div><p class="eyebrow">Decision needed</p><h3 class="text-lg font-semibold tracking-tight">Ready to approve?</h3><p class="text-sm text-muted leading-relaxed mt-2">Review the evidence and approve this revision for a local merge.</p></div>
                <form method="POST" action="{{ route('tasks.decide', $task) }}">@csrf<input type="hidden" name="action" value="approve"><input type="hidden" name="revision" value="{{ $task->revision }}"><button type="submit" class="button button-primary w-full">@include('mission.icon', ['name' => 'check', 'class' => 'size-4'])Approve for merge</button></form>
                <p class="text-xs text-muted leading-relaxed">Approval is recorded first. Merging is a separate action.</p>
            @elseif($task->status === 'approved')
                <div><span class="status-badge status-approved">Approval recorded</span><h3 class="text-lg font-semibold tracking-tight mt-3">Merge when you’re ready</h3><p class="text-sm text-muted leading-relaxed mt-2">Add this exact revision to the local {{ $runSettings['base_branch'] ?? $settings['base_branch'] ?? 'base' }} branch.</p></div>
                <form method="POST" action="{{ route('tasks.merge', $task) }}">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><button type="submit" class="button button-primary w-full">@include('mission.icon', ['name' => 'branch', 'class' => 'size-4'])Merge locally</button></form>
                <p class="text-xs text-muted leading-relaxed">The app checks the revision, base branch, and working tree before merging. No deployment is triggered.</p>
            @endif
            @if($canDecide)
                <div class="border-t border-line pt-4"><form method="POST" action="{{ route('tasks.decide', $task) }}" class="flex flex-col gap-3">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><div class="field"><label for="decision-action">Another decision</label><select id="decision-action" name="action" data-decision-action>@if($canRequestChanges)<option value="request_changes">Request changes</option>@endif<option value="defer">Defer for later</option><option value="reject">Reject task</option></select></div><div class="field"><label for="decision-feedback">Your feedback</label><textarea id="decision-feedback" name="feedback" rows="4" @required($canRequestChanges) maxlength="10000" data-decision-feedback placeholder="{{ $canRequestChanges ? 'What needs to change, and why?' : 'Optional: when should we revisit this?' }}">{{ old('feedback') }}</textarea></div><button type="submit" class="button button-secondary w-full">Save decision</button></form></div>
            @endif
        </div></section>
        <section class="panel" aria-labelledby="working-preview-title" @if($canPreview) data-working-preview data-preview-status-url="{{ route('tasks.preview.status', $task) }}" data-preview-revision="{{ $task->revision }}" @endif>
            <div class="panel-heading"><h2 id="working-preview-title">Working preview</h2><span class="status-badge {{ $livePreview ? 'status-merged' : '' }}" data-preview-state aria-live="polite">{{ $livePreview ? 'Live' : ($canPreview ? 'Offline' : 'Not available') }}</span></div>
            <div class="p-5 flex flex-col gap-4">
                @if($canPreview)
                    <p class="text-sm text-muted leading-relaxed">Try the reviewed change in its own local preview.</p>
                    <p class="flex items-start gap-2 text-xs text-accent leading-relaxed" data-preview-action-progress role="status" hidden>@include('mission.icon', ['name' => 'clock', 'class' => 'size-4 shrink-0 mt-0.5'])<span data-preview-action-progress-text></span></p>
                    <p class="rounded-lg border border-[#ead4d9] bg-[#fcf2f4] p-3 text-xs text-danger leading-relaxed whitespace-pre-wrap break-words" data-preview-action-error role="alert" hidden></p>
                    <div class="flex flex-col gap-3" data-preview-live @if(! $livePreview) hidden @endif>
                        <a @if($livePreview) href="{{ $livePreview['url'] }}" @endif class="button button-primary w-full" target="_blank" rel="noopener noreferrer" data-preview-link>Open working preview @include('mission.icon', ['name' => 'arrow', 'class' => 'size-4'])</a>
                        <p class="text-[11px] text-muted leading-relaxed">Expires <time data-preview-expires @if($livePreview) datetime="{{ $livePreview['expiresAt'] }}" @endif>{{ $livePreview ? \Illuminate\Support\Carbon::parse($livePreview['expiresAt'])->timezone(config('app.timezone'))->format('M j, H:i T') : '' }}</time>.</p>
                        <form method="POST" action="{{ route('tasks.preview.stop', $task) }}" data-preview-action="stop">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><button type="submit" class="button button-secondary button-small w-full"><span data-preview-button-label>Stop preview</span></button></form>
                    </div>
                    <div class="flex flex-col gap-3" data-preview-offline @if($livePreview) hidden @endif>
                        <p class="text-xs text-muted leading-relaxed" data-preview-message>The preview is stopped or has expired. Start it again from the reviewed revision.</p>
                        <form method="POST" action="{{ route('tasks.preview.start', $task) }}" data-preview-action="start">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><button type="submit" class="button button-primary w-full"><span data-preview-button-label>Start preview</span> @include('mission.icon', ['name' => 'arrow', 'class' => 'size-4'])</button></form>
                    </div>
                    <p class="text-[10px] text-muted break-all">Revision <code>{{ substr($task->revision, 0, 12) }}</code></p>
                @elseif(! $captureRequested)
                    <p class="text-sm text-muted leading-relaxed">{{ $latestRun ? 'A working preview was not requested for this run.' : 'A working preview becomes available when a task finishes with browser capture enabled.' }}</p>
                @else
                    <p class="text-sm text-muted leading-relaxed">A preview is available once the run has a stable revision ready for review.</p>
                @endif
            </div>
        </section>
        <section class="panel"><div class="panel-heading"><h2>Task details</h2></div><dl class="detail-list"><div><dt>Accountable lead</dt><dd>Engineering</dd></div><div><dt>Focus area</dt><dd>{{ ucfirst($task->area) }}</dd></div><div><dt>Priority</dt><dd>{{ ucfirst($task->priority) }}</dd></div><div><dt>Created</dt><dd>{{ $task->created_at->format('M j, Y') }}</dd></div>@if($task->branch)<div class="flex-col items-start"><dt>Task branch</dt><dd class="break-all text-xs font-mono mt-1">{{ $task->branch }}</dd></div>@endif</dl></section>
    </aside>
</div>
</div>
