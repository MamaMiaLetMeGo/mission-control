@php
    $productionPublication = $productionPublication ?? [];
    $productionState = $productionPublication['state'] ?? 'unplanned';
    $productionPlan = $productionPublication['plan'] ?? [];
    $productionHasPlan = ! empty($productionPlan['hash']);
    $productionHasPullRequest = ! empty($productionPublication['pr_number']);
    $productionMerged = $productionState === 'merged';
    $productionLabels = ['unplanned' => 'Not planned', 'planned' => 'Plan ready to review', 'draft' => 'Draft pull request', 'checks_pending' => 'Checks pending', 'checks_failed' => 'Checks failed', 'ready' => 'Ready to merge', 'merged' => 'Merged into production', 'closed' => 'Pull request closed', 'error' => 'Needs attention'];
    $productionBadge = match ($productionState) {
        'ready', 'merged' => 'status-merged',
        'checks_failed', 'error', 'closed' => 'status-failed',
        'draft', 'checks_pending' => 'status-queued',
        default => '',
    };
    $productionChecks = collect($productionPublication['checks'] ?? []);
    $productionFailedChecks = $productionChecks->contains(fn ($check) => in_array($check['state'] ?? null, ['fail', 'cancel'], true));
    $productionPendingChecks = $productionChecks->contains(fn ($check) => ($check['state'] ?? null) === 'pending');
    $productionChecksPassed = $productionChecks->isNotEmpty() && $productionChecks->every(fn ($check) => in_array($check['state'] ?? null, ['pass', 'skipping'], true)) && $productionChecks->contains(fn ($check) => ($check['state'] ?? null) === 'pass');
    $productionChecksLabel = $productionFailedChecks ? 'Needs attention' : ($productionPendingChecks ? 'In progress' : ($productionChecksPassed ? 'Passed' : ($productionChecks->isNotEmpty() ? 'Skipped' : 'Not reported')));
    $productionDeployment = $productionPublication['deployment'] ?? [];
    $productionDeploymentMatches = ! empty($productionPublication['merge_revision']) && ($productionDeployment['revision'] ?? null) === $productionPublication['merge_revision'];
    $productionDeploymentState = $productionDeployment['state'] ?? 'not_started';
    $productionDeploymentLabel = match (true) {
        ! $productionMerged, $productionDeploymentState === 'not_started' => 'Not started',
        ! $productionDeploymentMatches => 'Not verified',
        $productionDeploymentState === 'success' => 'Succeeded',
        $productionDeploymentState === 'failure' => 'Failed',
        $productionDeploymentState === 'running' => 'Running',
        $productionDeploymentState === 'pending' => 'Pending',
        default => 'Not verified',
    };
    $productionLink = fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    $productionPullRequestUrl = $productionLink($productionPublication['pr_url'] ?? null);
    $productionDeploymentUrl = $productionLink($productionDeployment['url'] ?? null);
    $productionDiffUrl = $productionLink($productionPlan['diff_url'] ?? null);
    $productionBlockers = $productionPublication['blockers'] ?? [];
    $productionConflicts = $productionPublication['conflicts'] ?? [];
    $productionCheckedAt = $productionPublication['last_checked_at'] ?? null;
    $productionChangedPaths = $productionPlan['changed_paths'] ?? [];
    $productionVerification = $productionPublication['verification'] ?? [];
    $productionVerified = $productionHasPlan && ($productionVerification['state'] ?? null) === 'passed' && ($productionVerification['plan_hash'] ?? null) === $productionPlan['hash'];
    $productionLocalChecks = $productionVerification['checks'] ?? [];
    $productionSelectedScope = old('scope', $productionPlan['scope'] ?? 'task');
    $productionAdditionalPaths = old('additional_paths', $productionPlan['additional_paths'] ?? []);
    $productionAdditionalPaths = is_array($productionAdditionalPaths) ? implode("\n", $productionAdditionalPaths) : $productionAdditionalPaths;
@endphp
<section class="panel production-publication mb-6" aria-labelledby="production-publication-heading" data-production-publication>
    <div class="panel-heading flex-wrap" data-publication-live="heading">
        <div class="flex items-center gap-2.5">@include('mission.icon', ['name' => 'branch', 'class' => 'size-4'])<h2 id="production-publication-heading">Release to production</h2></div>
        <span class="status-badge {{ $productionBadge }}">{{ $productionLabels[$productionState] ?? 'Needs attention' }}</span>
    </div>
    <div class="p-5 flex flex-col gap-5">
        <div class="production-destination"><div><p class="eyebrow mb-1">Production destination</p><strong>{{ parse_url((string) config('mission.production_url'), PHP_URL_HOST) ?: (config('mission.staging_repository') ?: 'Not configured') }}</strong></div><span class="status-badge">main</span></div>
        <p class="text-xs text-muted leading-relaxed">Choose the release scope and review its saved plan before publishing. Publishing creates a draft pull request; merging it into <code>main</code> can deploy the production site.</p>
        <ol class="publication-steps" aria-label="Production release progress" data-publication-live="progress">
            <li><span class="publication-step-number {{ $productionHasPlan ? 'publication-step-done' : '' }}">1</span><div><h3>Release plan</h3><p>{{ $productionHasPlan ? count($productionChangedPaths).' changed files' : 'Not planned' }}</p>@if($productionHasPlan)<code title="{{ $productionPlan['hash'] }}">{{ substr($productionPlan['hash'], 0, 12) }}</code>@endif</div></li>
            <li><span class="publication-step-number {{ $productionHasPullRequest ? 'publication-step-done' : '' }}">2</span><div><h3>Production PR</h3><p>{{ $productionHasPullRequest ? '#'.$productionPublication['pr_number'].' · '.($productionMerged ? 'Merged' : ($productionState === 'closed' ? 'Closed' : ($productionState === 'draft' ? 'Draft' : 'Open'))) : 'Not published' }}</p>@if($productionPullRequestUrl)<a href="{{ $productionPullRequestUrl }}" target="_blank" rel="noopener noreferrer" class="text-link text-[10px]">Open release PR @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3'])</a>@endif</div></li>
            <li><span class="publication-step-number {{ $productionChecksPassed ? 'publication-step-done' : '' }}">3</span><div><h3>Release checks</h3><p>{{ $productionChecksLabel }}</p><span class="text-[10px] text-muted">{{ $productionChecks->count() }} reported</span></div></li>
            <li><span class="publication-step-number {{ $productionMerged ? 'publication-step-done' : '' }}">4</span><div><h3>Production merge</h3><p>{{ $productionMerged ? 'Merged into main' : 'Not merged' }}</p>@if($productionPublication['merge_revision'] ?? null)<code title="{{ $productionPublication['merge_revision'] }}">{{ substr($productionPublication['merge_revision'], 0, 12) }}</code>@endif</div></li>
            <li><span class="publication-step-number {{ $productionDeploymentLabel === 'Succeeded' ? 'publication-step-done' : '' }}">5</span><div><h3>Deployment</h3><p>{{ $productionDeploymentLabel }}</p>@if($productionDeploymentUrl)<a href="{{ $productionDeploymentUrl }}" target="_blank" rel="noopener noreferrer" class="text-link text-[10px]">Production deployment @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3'])</a>@endif</div></li>
        </ol>
        @if($productionHasPlan)
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="text-sm font-semibold">{{ ($productionPlan['scope'] ?? null) === 'staging' ? 'Scope: verified staging snapshot' : 'Scope: this task and selected prerequisites' }}</h3>@if($productionDiffUrl)<a href="{{ $productionDiffUrl }}" class="text-link text-xs" target="_blank" rel="noopener noreferrer">View complete release diff @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3'])</a>@endif</div>
                <dl class="production-revisions">
                    <div><dt>Production base · main</dt><dd><code>{{ $productionPlan['main_revision'] ?? 'Not recorded' }}</code></dd></div>
                    <div><dt>Verified staging snapshot</dt><dd><code>{{ $productionPlan['staging_revision'] ?? 'Not recorded' }}</code></dd></div>
                    <div><dt>Reviewed task revision</dt><dd><code>{{ $productionPlan['task_revision'] ?? 'Not recorded' }}</code></dd></div>
                    <div><dt>Planned release revision</dt><dd><code>{{ $productionPlan['release_revision'] ?? 'Not recorded' }}</code></dd></div>
                </dl>
                <details class="publication-checks production-paths" @if(! $productionMerged) open @endif>
                    <summary>Complete release scope <span class="text-muted font-normal">{{ count($productionChangedPaths) }} files</span></summary>
                    <ul>
                        @forelse($productionChangedPaths as $changedPath)
                            <li><code class="min-w-0 break-all text-[11px]">{{ $changedPath['path'] ?? 'Unknown path' }}</code><span class="status-badge shrink-0">{{ ['A' => 'Added', 'M' => 'Modified', 'D' => 'Deleted', 'R' => 'Renamed', 'C' => 'Copied'][$changedPath['status'] ?? ''] ?? ($changedPath['status'] ?? 'Changed') }}</span></li>
                        @empty
                            <li class="text-muted">No changed files were recorded in this plan.</li>
                        @endforelse
                    </ul>
                </details>
                @if(! empty($productionPlan['additional_paths']))<p class="text-[11px] text-muted leading-relaxed">Selected prerequisite files: <span class="font-mono break-all">{{ implode(', ', $productionPlan['additional_paths']) }}</span></p>@endif
            </div>
        @endif
        <div data-publication-live="scope-form" @if(($productionHasPullRequest && $productionState !== 'closed') || $productionMerged) hidden @endif>
        @if((! $productionHasPullRequest || $productionState === 'closed') && ! $productionMerged)
            <details class="publication-checks" data-publication-detail="scope" @if(! $productionHasPlan || $productionState === 'closed') open @endif>
                <summary>{{ $productionState === 'closed' ? 'Prepare a new release plan' : ($productionHasPlan ? 'Change release scope' : 'Choose release scope') }}</summary>
                <form method="POST" action="{{ route('tasks.production.plan', $task) }}" class="p-4 border-t border-line flex flex-col gap-4" data-publication-action="plan">
                    @csrf
                    <input type="hidden" name="revision" value="{{ $task->revision }}">
                    <fieldset class="flex flex-col gap-2.5"><legend class="section-label">Include in this release</legend>
                        <label class="production-scope-option"><input type="radio" name="scope" value="task" required @checked($productionSelectedScope === 'task')><span><strong>This task and selected prerequisites</strong><span>Apply the reviewed task changes and any prerequisite files you list below to the current production base.</span></span></label>
                        <label class="production-scope-option"><input type="radio" name="scope" value="staging" required @checked($productionSelectedScope === 'staging')><span><strong>Verified staging snapshot</strong><span>Include every difference between this task’s verified staging snapshot and production, including changes from other tasks.</span></span></label>
                    </fieldset>
                    <div class="field"><label for="production-additional-paths">Prerequisite files for task scope <span class="font-normal text-muted">· optional</span></label><textarea id="production-additional-paths" name="additional_paths" rows="3" class="font-mono text-xs" placeholder="One repository-relative file path per line" spellcheck="false" aria-describedby="production-paths-help">{{ $productionAdditionalPaths }}</textarea><p id="production-paths-help" class="field-help">List any other files this release needs, one path per line. They are copied from the staging snapshot and included in the plan for review.</p></div>
                    <div class="flex flex-wrap items-center gap-3"><button type="submit" class="button button-secondary button-small" @disabled(! ($productionPublication['can_plan'] ?? false)) @if($productionBlockers) aria-describedby="production-blockers" @endif>@include('mission.icon', ['name' => 'file', 'class' => 'size-3.5'])<span data-publication-button-label>{{ $productionState === 'closed' ? 'Prepare a new release plan' : 'Plan release' }}</span></button><p class="text-[11px] text-muted">Creates a local plan for review.</p></div>
                </form>
            </details>
        @endif
        </div>
        <div data-publication-live="local-verification" @if(! $productionHasPlan) hidden @endif>
        @if($productionHasPlan)
            <section class="production-verification" aria-labelledby="production-verification-heading">
                <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="text-xs font-semibold" id="production-verification-heading">Local release checks</h3><span class="status-badge {{ $productionVerified ? 'status-merged' : '' }}">{{ $productionVerified ? 'Passed · '.count($productionLocalChecks).' checks' : 'Not verified' }}</span></div>
                @if($productionVerified)
                    <p class="text-xs text-muted leading-relaxed">The imported checks passed for this saved release plan.</p>
                    <details class="publication-checks" data-publication-detail="local-checks"><summary>Review {{ count($productionLocalChecks) }} local checks</summary><ul>@foreach($productionLocalChecks as $localCheck)<li><div class="min-w-0 w-full"><div class="flex flex-wrap items-center justify-between gap-2 text-xs"><span class="font-medium">{{ $localCheck['name'] ?? 'Local check' }}</span><span class="text-muted">Exit {{ $localCheck['exit_code'] ?? 'unknown' }}</span></div><code class="mt-1.5 block text-[10px] text-muted break-all">{{ is_array($localCheck['command'] ?? null) ? implode(' ', $localCheck['command']) : ($localCheck['command'] ?? '') }}</code></div></li>@endforeach</ul></details>
                    @if(! empty($productionVerification['receipt_digest']))<p class="text-[10px] text-muted">Evidence receipt <code title="{{ $productionVerification['receipt_digest'] }}">{{ substr($productionVerification['receipt_digest'], 0, 16) }}</code>@if(! empty($productionVerification['completed_at'])) · <time datetime="{{ $productionVerification['completed_at'] }}">{{ \Illuminate\Support\Carbon::parse($productionVerification['completed_at'])->timezone(config('app.timezone'))->format('M j, H:i:s T') }}</time>@endif</p>@endif
                @else
                    <p class="text-xs text-muted leading-relaxed">Import the check results for this release revision before marking its pull request ready.</p>
                @endif
                @if(! $productionMerged && (! $productionHasPullRequest || $productionState === 'draft' || ($productionPublication['can_verify'] ?? false)))
                    <details class="publication-checks" data-publication-detail="import-evidence" @if(! $productionVerified) open @endif><summary>{{ $productionVerified ? 'Replace check evidence' : 'Import local checks' }}</summary><form method="POST" action="{{ route('tasks.production.verify', $task) }}" class="flex flex-col gap-3 border-t border-line p-4" data-publication-action="verify">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="plan_hash" value="{{ $productionPlan['hash'] }}"><div class="field"><label for="production-evidence-file">Local checks evidence file</label><input id="production-evidence-file" name="evidence_file" value="{{ old('evidence_file') }}" required spellcheck="false" placeholder="release-checks.json" aria-describedby="production-evidence-help"><p id="production-evidence-help" class="field-help">Enter the evidence file path relative to <code>storage/app/verification</code>. Mission Control validates the recorded checks against this release plan.</p></div><div><button type="submit" class="button button-secondary button-small" @disabled(! ($productionPublication['can_verify'] ?? false)) @if($productionBlockers) aria-describedby="production-blockers" @endif>@include('mission.icon', ['name' => 'shield', 'class' => 'size-3.5'])<span data-publication-button-label>Import local checks</span></button></div></form></details>
                @endif
            </section>
        @endif
        </div>
        <div data-publication-live="remote-checks" @if($productionChecks->isEmpty()) hidden @endif>
        @if($productionChecks->isNotEmpty())
            <details class="publication-checks" data-publication-detail="remote-checks" @if($productionFailedChecks) open @endif>
                <summary>Production checks <span class="text-muted font-normal">{{ $productionChecks->count() }}</span></summary>
                <ul>@foreach($productionChecks as $productionCheck)
                    @php
                        $productionCheckState = $productionCheck['state'] ?? 'pending';
                        $productionCheckUrl = $productionLink($productionCheck['url'] ?? null);
                    @endphp
                    <li><span class="min-w-0 break-words">{{ $productionCheck['name'] ?? 'Unnamed check' }}</span><div class="flex shrink-0 items-center gap-3"><span class="status-badge {{ $productionCheckState === 'pass' ? 'status-merged' : (in_array($productionCheckState, ['fail', 'cancel'], true) ? 'status-failed' : '') }}">{{ ['pass' => 'Passed', 'pending' => 'Pending', 'fail' => 'Failed', 'skipping' => 'Skipped', 'cancel' => 'Cancelled'][$productionCheckState] ?? 'Unknown' }}</span>@if($productionCheckUrl)<a href="{{ $productionCheckUrl }}" target="_blank" rel="noopener noreferrer" class="text-link text-[10px]" aria-label="View {{ $productionCheck['name'] ?? 'production check' }} details">Details @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3'])</a>@endif</div></li>
                @endforeach</ul>
            </details>
        @endif
        </div>
        <div data-publication-live="conflicts" @if(! $productionConflicts) hidden @endif>
        @if($productionConflicts)
            <div class="production-conflicts"><h3 class="text-xs font-semibold">Other open production pull requests</h3><p class="text-xs text-muted leading-relaxed">Review these pull requests before publishing a replacement release.</p>
                @foreach($productionConflicts as $productionConflict)
                    @php
                        $productionConflictUrl = $productionLink($productionConflict['url'] ?? null);
                    @endphp
                    <div class="production-conflict-row"><div class="min-w-0"><p class="text-xs font-medium">@if($productionConflictUrl)<a class="text-link" href="{{ $productionConflictUrl }}" target="_blank" rel="noopener noreferrer">Pull request #{{ $productionConflict['number'] }} @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3'])</a>@else Pull request #{{ $productionConflict['number'] }} @endif</p><p class="text-[11px] text-muted break-all mt-1">{{ $productionConflict['head_branch'] ?? 'Unknown branch' }} · <code>{{ $productionConflict['head_revision'] ?? 'Unknown revision' }}</code></p></div>
                        @if($productionHasPlan && ! empty($productionConflict['head_revision']))<form method="POST" action="{{ route('tasks.production.close-conflict', $task) }}" data-publication-action="close-conflict">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="plan_hash" value="{{ $productionPlan['hash'] }}"><input type="hidden" name="pr_number" value="{{ $productionConflict['number'] }}"><input type="hidden" name="head_revision" value="{{ $productionConflict['head_revision'] }}"><button type="submit" class="button button-secondary button-small"><span data-publication-button-label>Close production PR #{{ $productionConflict['number'] }}</span></button></form>@endif
                    </div>
                @endforeach
            </div>
        @endif
        </div>
        <div class="flex flex-col gap-3" data-publication-live="notices" @if(empty($productionPublication['error']) && (! $productionBlockers || $productionMerged) && (! $productionMerged || $productionDeploymentLabel === 'Succeeded')) hidden @endif>
        @if(! empty($productionPublication['error']))<div class="notice notice-error text-xs" role="alert">@include('mission.icon', ['name' => 'alert', 'class' => 'size-4 shrink-0'])<p class="whitespace-pre-wrap break-words">{{ $productionPublication['error'] }}</p></div>@endif
        @if($productionBlockers && ! $productionMerged)<div class="repository-blockers" id="production-blockers">@include('mission.icon', ['name' => 'alert', 'class' => 'size-4 shrink-0 mt-0.5'])<ul>@foreach($productionBlockers as $productionBlocker)<li>{{ $productionBlocker }}</li>@endforeach</ul></div>@endif
        @if($productionMerged && $productionDeploymentLabel !== 'Succeeded')<p class="text-xs text-muted leading-relaxed">{{ $productionDeploymentLabel === 'Failed' ? 'The production merge succeeded, but its deployment failed. Open the production deployment to inspect the failure.' : ($productionDeploymentLabel === 'Not verified' ? 'No deployment result has been verified for this production merge revision. Status updates automatically while this page is open.' : 'The release is merged into main. Its production deployment has not completed yet; its status updates automatically while this page is open.') }}</p>@endif
        </div>
        <div class="publication-actions" data-publication-live="actions">
            <div class="flex flex-wrap gap-2">
                @if($productionDeploymentLabel === 'Succeeded' && config('mission.production_url'))<a href="{{ config('mission.production_url') }}" target="_blank" rel="noopener noreferrer" class="button button-primary button-small">Open production @include('mission.icon', ['name' => 'arrow', 'class' => 'size-3.5'])</a>@endif
                @if($productionHasPlan && ! $productionHasPullRequest && ! $productionMerged && $productionState !== 'closed')<form method="POST" action="{{ route('tasks.production.publish', $task) }}" data-publication-action="publish">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="plan_hash" value="{{ $productionPlan['hash'] }}"><button type="submit" class="button button-primary button-small" @disabled(! ($productionPublication['can_publish'] ?? false)) @if($productionBlockers) aria-describedby="production-blockers" @endif>@include('mission.icon', ['name' => 'arrow', 'class' => 'size-3.5'])<span data-publication-button-label>Publish reviewed plan</span></button></form>@endif
                @if($productionHasPullRequest && ! $productionMerged && $productionState !== 'closed')
                    @if($productionState === 'draft' || ($productionPublication['can_ready'] ?? false))<form method="POST" action="{{ route('tasks.production.ready', $task) }}" data-publication-action="ready">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="plan_hash" value="{{ $productionPlan['hash'] ?? '' }}"><button type="submit" class="button button-primary button-small" @disabled(! ($productionPublication['can_ready'] ?? false)) @if($productionBlockers) aria-describedby="production-blockers" @endif><span data-publication-button-label>Mark release ready</span></button></form>@endif
                    <form method="POST" action="{{ route('tasks.production.merge', $task) }}" data-publication-action="merge">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="plan_hash" value="{{ $productionPlan['hash'] ?? '' }}"><button type="submit" class="button button-primary button-small" @disabled(! ($productionPublication['can_merge'] ?? false)) @if($productionBlockers) aria-describedby="production-blockers" @endif>@include('mission.icon', ['name' => 'branch', 'class' => 'size-3.5'])<span data-publication-button-label>Merge into production</span></button></form>
                @endif
                @if($productionHasPullRequest)<form method="POST" action="{{ route('tasks.production.refresh', $task) }}" data-publication-action="refresh">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="plan_hash" value="{{ $productionPlan['hash'] ?? '' }}"><button type="submit" class="button button-secondary button-small">@include('mission.icon', ['name' => 'refresh', 'class' => 'size-3.5'])<span data-publication-button-label>Refresh production status</span></button></form>@endif
            </div>
            <p class="text-[10px] text-muted leading-relaxed">Last checked: <time @if($productionCheckedAt) datetime="{{ $productionCheckedAt }}" @endif>{{ $productionCheckedAt ? \Illuminate\Support\Carbon::parse($productionCheckedAt)->timezone(config('app.timezone'))->format('M j, H:i:s T') : 'Not checked yet' }}</time></p>
        </div>
        <p class="text-[11px] text-muted leading-relaxed" data-publication-auto-status role="status" hidden></p>
        <p class="text-xs text-accent leading-relaxed" data-publication-progress role="status" hidden></p>
    </div>
</section>
