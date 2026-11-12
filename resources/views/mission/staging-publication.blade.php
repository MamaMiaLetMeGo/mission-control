@php
    $publication = $publication ?? [];
    $publicationState = $publication['state'] ?? 'not_published';
    $publicationLabels = ['not_published' => 'Not published', 'publishing' => 'Publication incomplete', 'open' => 'Pull request open', 'checks_pending' => 'Checks pending', 'checks_failed' => 'Checks failed', 'ready' => 'Ready to merge', 'merged' => 'Merged into staging', 'closed' => 'Pull request closed', 'error' => 'Needs attention'];
    $publicationBadge = match ($publicationState) {
        'ready', 'merged' => 'status-merged',
        'checks_failed', 'error', 'closed' => 'status-failed',
        'open', 'checks_pending' => 'status-queued',
        'publishing' => 'status-deferred',
        default => '',
    };
    $publicationChecks = collect($publication['checks'] ?? []);
    $hasFailedChecks = $publicationChecks->contains(fn ($check) => in_array($check['state'] ?? null, ['fail', 'cancel'], true));
    $hasPendingChecks = $publicationChecks->contains(fn ($check) => ($check['state'] ?? null) === 'pending');
    $checksPassed = $publicationChecks->isNotEmpty() && $publicationChecks->every(fn ($check) => in_array($check['state'] ?? null, ['pass', 'skipping'], true)) && $publicationChecks->contains(fn ($check) => ($check['state'] ?? null) === 'pass');
    $checksLabel = $hasFailedChecks ? 'Needs attention' : ($hasPendingChecks ? 'In progress' : ($checksPassed ? 'Passed' : ($publicationChecks->isNotEmpty() ? 'Skipped' : 'Not reported')));
    $stagingMerged = $publicationState === 'merged';
    $deployment = $publication['deployment'] ?? [];
    $deploymentMatchesRevision = ! empty($publication['merge_revision']) && ($deployment['revision'] ?? null) === $publication['merge_revision'];
    $deploymentState = $deployment['state'] ?? 'not_started';
    $deploymentLabel = match (true) {
        ! $stagingMerged => 'Not started',
        $deploymentState === 'not_started' => 'Not started',
        ! $deploymentMatchesRevision => 'Not verified',
        $deploymentState === 'success' => 'Succeeded',
        $deploymentState === 'failure' => 'Failed',
        $deploymentState === 'running' => 'Running',
        $deploymentState === 'pending' => 'Pending',
        default => 'Not verified',
    };
    $publicationCanPublish = ($publication['can_publish'] ?? false) === true;
    $publicationCanMerge = ($publication['can_merge'] ?? false) === true;
    $publicationBlockers = $publication['blockers'] ?? [];
    $publicationCheckedAt = $publication['last_checked_at'] ?? null;
    $publicationLink = fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    $hasPullRequest = ! empty($publication['pr_number']);
    $pullRequestUrl = $publicationLink($publication['pr_url'] ?? null);
    $deploymentUrl = $publicationLink($deployment['url'] ?? null);
@endphp
<section class="panel staging-publication mb-6" aria-labelledby="staging-publication-heading" data-staging-publication>
    <div class="panel-heading flex-wrap" data-publication-live="heading">
        <div class="flex items-center gap-2.5">@include('mission.icon', ['name' => 'branch', 'class' => 'size-4 text-accent'])<h2 id="staging-publication-heading">Publish to staging</h2></div>
        <span class="status-badge {{ $publicationBadge }}">{{ $publicationLabels[$publicationState] ?? 'Needs attention' }}</span>
    </div>
    <div class="p-5 flex flex-col gap-5">
        <p class="text-xs text-muted leading-relaxed">The change is merged locally. Publishing opens a pull request for the approved revision; merging that pull request adds it to staging.</p>
        <ol class="publication-steps" aria-label="Staging publication progress" data-publication-live="progress">
            <li><span class="publication-step-number publication-step-done">@include('mission.icon', ['name' => 'check', 'class' => 'size-3'])</span><div><h3>Local merge</h3><p>Merged locally</p><code>{{ substr($task->revision ?? '', 0, 8) }}</code></div></li>
            <li><span class="publication-step-number {{ ! empty($publication['pr_number']) ? 'publication-step-done' : '' }}">2</span><div><h3>Pull request</h3><p>{{ ! empty($publication['pr_number']) ? '#'.$publication['pr_number'].' · '.($stagingMerged ? 'Merged' : ($publicationState === 'closed' ? 'Closed' : 'Open')) : 'Not published' }}</p>@if($pullRequestUrl)<a href="{{ $pullRequestUrl }}" target="_blank" rel="noopener noreferrer" class="text-link text-[10px]">Open pull request @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3'])</a>@endif</div></li>
            <li><span class="publication-step-number {{ $checksPassed ? 'publication-step-done' : '' }}">3</span><div><h3>Checks</h3><p>{{ $checksLabel }}</p><span class="text-[10px] text-muted">{{ $publicationChecks->count() }} reported</span></div></li>
            <li><span class="publication-step-number {{ $stagingMerged ? 'publication-step-done' : '' }}">4</span><div><h3>Staging merge</h3><p>{{ $stagingMerged ? 'Merged' : 'Not merged' }}</p>@if($publication['merge_revision'] ?? null)<code>{{ substr($publication['merge_revision'], 0, 8) }}</code>@else<span class="text-[10px] text-muted">{{ $publication['base_branch'] ?? 'staging' }}</span>@endif</div></li>
            <li><span class="publication-step-number {{ $deploymentLabel === 'Succeeded' ? 'publication-step-done' : '' }}">5</span><div><h3>Deployment</h3><p>{{ $deploymentLabel }}</p>@if($deploymentUrl)<a href="{{ $deploymentUrl }}" target="_blank" rel="noopener noreferrer" class="text-link text-[10px]">Deployment details @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3'])</a>@endif</div></li>
        </ol>
        <div data-publication-live="remote-checks" @if($publicationChecks->isEmpty()) hidden @endif>
        @if($publicationChecks->isNotEmpty())
            <details class="publication-checks" data-publication-detail="remote-checks" @if($hasFailedChecks) open @endif>
                <summary>Review checks <span class="text-muted font-normal">{{ $publicationChecks->count() }}</span></summary>
                <ul>
                    @foreach($publicationChecks as $check)
                        @php
                            $checkState = $check['state'] ?? 'pending';
                            $checkUrl = $publicationLink($check['url'] ?? null);
                        @endphp
                        <li><span class="min-w-0 break-words">{{ $check['name'] ?? 'Unnamed check' }}</span><div class="flex shrink-0 items-center gap-3"><span class="status-badge {{ $checkState === 'pass' ? 'status-merged' : (in_array($checkState, ['fail', 'cancel'], true) ? 'status-failed' : '') }}">{{ ['pass' => 'Passed', 'pending' => 'Pending', 'fail' => 'Failed', 'skipping' => 'Skipped', 'cancel' => 'Cancelled'][$checkState] ?? 'Unknown' }}</span>@if($checkUrl)<a href="{{ $checkUrl }}" target="_blank" rel="noopener noreferrer" class="text-link text-[10px]" aria-label="View {{ $check['name'] ?? 'check' }} details">Details @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3'])</a>@endif</div></li>
                    @endforeach
                </ul>
            </details>
        @endif
        </div>
        <div class="flex flex-col gap-3" data-publication-live="notices" @if(empty($publication['error']) && (! $publicationBlockers || $stagingMerged) && (! $stagingMerged || $deploymentLabel === 'Succeeded')) hidden @endif>
        @if(! empty($publication['error']))<div class="notice notice-error text-xs" role="alert"><span class="shrink-0">@include('mission.icon', ['name' => 'alert', 'class' => 'size-4'])</span><p class="whitespace-pre-wrap break-words">{{ $publication['error'] }}</p></div>@endif
        @if($publicationBlockers && ! $stagingMerged)
            <div class="repository-blockers" id="publication-blockers">@include('mission.icon', ['name' => 'alert', 'class' => 'size-4 shrink-0 mt-0.5'])<ul>@foreach($publicationBlockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
        @endif
        @if($stagingMerged && $deploymentLabel !== 'Succeeded')
            <p class="text-xs text-muted leading-relaxed">{{ $deploymentLabel === 'Failed' ? 'The staging merge succeeded, but its deployment failed. Open deployment details to inspect the failure.' : ($deploymentLabel === 'Not verified' ? 'No deployment result has been verified for this staging merge revision. Status updates automatically while this page is open.' : 'The change is on the staging branch. Its deployment has not completed yet; its status updates automatically while this page is open.') }}</p>
        @endif
        </div>
        <div class="publication-actions" data-publication-live="actions">
            <div class="flex flex-wrap gap-2">
                @if($deploymentLabel === 'Succeeded' && config('mission.staging_url'))
                    <a href="{{ config('mission.staging_url') }}" target="_blank" rel="noopener noreferrer" class="button button-primary button-small">Open staging @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3.5'])</a>
                @endif
                @if(! $hasPullRequest && ! $stagingMerged && $publicationState !== 'closed')
                    <form method="POST" action="{{ route('tasks.publish', $task) }}" data-publication-action="publish">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><button type="submit" class="button button-primary button-small" @disabled(! $publicationCanPublish) @if($publicationBlockers) aria-describedby="publication-blockers" @endif>@include('mission.icon', ['name' => 'arrow', 'class' => 'size-3.5'])<span data-publication-button-label>Publish approved change</span></button></form>
                @endif
                @if($hasPullRequest && ! $stagingMerged && $publicationState !== 'closed')
                    <form method="POST" action="{{ route('tasks.publication.merge', $task) }}" data-publication-action="merge">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><button type="submit" class="button button-primary button-small" @disabled(! $publicationCanMerge) @if($publicationBlockers) aria-describedby="publication-blockers" @endif>@include('mission.icon', ['name' => 'branch', 'class' => 'size-3.5'])<span data-publication-button-label>Merge into staging</span></button></form>
                @endif
                @if($hasPullRequest)
                <form method="POST" action="{{ route('tasks.publication.refresh', $task) }}" data-publication-action="refresh">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><button type="submit" class="button button-secondary button-small">@include('mission.icon', ['name' => 'refresh', 'class' => 'size-3.5'])<span data-publication-button-label>Refresh staging status</span></button></form>
                @endif
            </div>
            <p class="text-[10px] text-muted leading-relaxed">Last checked: <time @if($publicationCheckedAt) datetime="{{ $publicationCheckedAt }}" @endif>{{ $publicationCheckedAt ? \Illuminate\Support\Carbon::parse($publicationCheckedAt)->timezone(config('app.timezone'))->format('M j, H:i:s T') : 'Not checked yet' }}</time></p>
        </div>
        <p class="text-[11px] text-muted leading-relaxed" data-publication-auto-status role="status" hidden></p>
        <p class="text-xs text-accent leading-relaxed" data-publication-progress role="status" hidden></p>
        <p class="text-[10px] text-muted leading-relaxed">Publishing pushes only the approved task branch. Merging its pull request can trigger the staging deployment workflow.</p>
    </div>
</section>
