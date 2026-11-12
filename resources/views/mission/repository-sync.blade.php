@php
    $repositoryStatus = $repositoryStatus ?? [];
    $repositoryState = $repositoryStatus['state'] ?? 'unavailable';
    $repositoryStateLabels = ['unchecked' => 'Unchecked', 'up_to_date' => 'Up to date', 'behind' => 'Behind remote', 'ahead' => 'Ahead of remote', 'diverged' => 'Diverged', 'dirty' => 'Uncommitted edits', 'wrong_branch' => 'Different branch', 'unavailable' => 'Unavailable'];
    $repositoryBadgeClass = match ($repositoryState) {
        'up_to_date' => 'status-merged',
        'behind', 'ahead' => 'status-queued',
        'dirty', 'wrong_branch', 'diverged' => 'status-deferred',
        default => '',
    };
    $repositorySummary = match ($repositoryState) {
        'unchecked' => 'Check the remote to compare this local branch with its upstream.',
        'up_to_date' => 'Local and remote commits matched at the last remote check.',
        'behind' => 'The local branch can be updated with commits from its upstream.',
        'ahead' => 'The local branch contains commits that are not on its upstream.',
        'diverged' => 'Local and remote branches have both moved. Resolve the divergence before syncing.',
        'dirty' => 'This checkout has uncommitted edits. Syncing is blocked while they are present.',
        'wrong_branch' => 'The checked-out branch differs from the configured base branch.',
        default => 'Repository status could not be determined. Check the remote for details.',
    };
    $repositoryCanSync = ($repositoryStatus['can_sync'] ?? false) === true;
    $repositoryBlockers = $repositoryStatus['blockers'] ?? [];
    $repositoryLastChecked = $repositoryStatus['last_checked_at'] ?? null;
    $repositorySyncLabel = ! empty($repositoryStatus['upstream']) ? 'Sync with '.$repositoryStatus['upstream'] : 'Sync local branch';
@endphp
<section class="panel repository-panel mb-6" aria-labelledby="repository-sync-heading" data-repository-panel data-repository-status="{{ json_encode($repositoryStatus) }}" data-repository-status-url="{{ route('repository.status') }}">
    <div class="panel-heading flex-wrap">
        <div class="flex items-center gap-2.5">@include('mission.icon', ['name' => 'branch', 'class' => 'size-4 text-muted'])<h2 id="repository-sync-heading">Repository sync</h2></div>
        <span class="status-badge {{ $repositoryBadgeClass }}" data-repository-state>{{ $repositoryStateLabels[$repositoryState] ?? 'Unavailable' }}</span>
    </div>
    <div class="p-5 flex flex-col gap-4">
        <p class="text-xs text-muted leading-relaxed" data-repository-summary>{{ $repositorySummary }}</p>
        <p class="text-[10px] text-muted break-all">Repository: <code data-repository-path>{{ $repositoryStatus['repository_path'] ?? 'Not configured' }}</code></p>
        <dl class="repository-facts">
            <div><dt>Local commit <span data-repository-local-branch>{{ $repositoryStatus['current_branch'] ?? '—' }}</span></dt><dd><code data-repository-local-revision title="{{ $repositoryStatus['local_revision'] ?? '' }}">{{ isset($repositoryStatus['local_revision']) ? substr($repositoryStatus['local_revision'], 0, 12) : '—' }}</code></dd></div>
            <div><dt>Remote commit <span data-repository-upstream>{{ $repositoryStatus['upstream'] ?? '—' }}</span></dt><dd><code data-repository-remote-revision title="{{ $repositoryStatus['remote_revision'] ?? '' }}">{{ isset($repositoryStatus['remote_revision']) ? substr($repositoryStatus['remote_revision'], 0, 12) : '—' }}</code></dd></div>
            <div><dt>Branch comparison</dt><dd class="text-xs"><span data-repository-ahead>{{ is_int($repositoryStatus['ahead'] ?? null) ? $repositoryStatus['ahead'] : '—' }}</span> ahead <span class="text-line px-1">/</span> <span data-repository-behind>{{ is_int($repositoryStatus['behind'] ?? null) ? $repositoryStatus['behind'] : '—' }}</span> behind</dd></div>
            <div><dt>Working tree</dt><dd class="text-xs" data-repository-working-tree>{{ is_int($repositoryStatus['dirty_count'] ?? null) ? ($repositoryStatus['dirty_count'] === 0 ? 'Clean' : $repositoryStatus['dirty_count'].' uncommitted entries') : 'Unchecked' }}</dd></div>
        </dl>
        <div class="repository-actions">
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('repository.check') }}" data-repository-action="check">@csrf<button type="submit" class="button button-secondary button-small">@include('mission.icon', ['name' => 'refresh', 'class' => 'size-3.5'])<span data-repository-button-label>Check remote</span></button></form>
                <form method="POST" action="{{ route('repository.sync') }}" data-repository-action="sync">
                    @csrf
                    <input type="hidden" name="expected_head" value="{{ $repositoryStatus['local_revision'] ?? '' }}">
                    <input type="hidden" name="expected_remote" value="{{ $repositoryStatus['remote_revision'] ?? '' }}">
                    <input type="hidden" name="expected_base" value="{{ $repositoryStatus['base_branch'] ?? '' }}">
                    <input type="hidden" name="expected_repository" value="{{ $repositoryStatus['repository_path'] ?? '' }}">
                    <button type="submit" class="button button-primary button-small" @disabled(! $repositoryCanSync) aria-describedby="repository-sync-reason">@include('mission.icon', ['name' => 'branch', 'class' => 'size-3.5'])<span data-repository-button-label>{{ $repositorySyncLabel }}</span></button>
                </form>
            </div>
            <p class="text-[10px] text-muted leading-relaxed">Last remote check: <time data-repository-last-checked @if($repositoryLastChecked) datetime="{{ $repositoryLastChecked }}" @endif>{{ $repositoryLastChecked ? \Illuminate\Support\Carbon::parse($repositoryLastChecked)->timezone(config('app.timezone'))->format('M j, H:i:s T') : 'Not checked yet' }}</time></p>
        </div>
        <div id="repository-sync-reason" class="repository-blockers" data-repository-blockers @if($repositoryCanSync) hidden @endif>
            @include('mission.icon', ['name' => 'alert', 'class' => 'size-4 shrink-0 mt-0.5'])
            <ul data-repository-blocker-list>@forelse($repositoryBlockers as $blocker)<li>{{ $blocker }}</li>@empty<li>{{ $repositoryState === 'up_to_date' ? 'Already up to date. There is nothing to pull.' : 'Check the remote and resolve any blockers before syncing.' }}</li>@endforelse</ul>
        </div>
        <p class="text-xs text-accent leading-relaxed" data-repository-progress role="status" hidden></p>
        <p class="notice notice-success text-xs" data-repository-success role="status" hidden></p>
        <p class="notice notice-error text-xs whitespace-pre-wrap break-words" data-repository-error role="alert" hidden></p>
        <p class="text-[10px] text-muted leading-relaxed">Sync only fast-forwards the local branch. Uncommitted edits are never overwritten.</p>
    </div>
</section>
