@php
    $statusLabels = ['backlog' => 'Backlog', 'queued' => 'Queued', 'running' => 'In progress', 'review' => 'Needs review', 'approved' => 'Approved', 'merged' => 'Merged locally', 'changes_requested' => 'Changes requested', 'deferred' => 'Deferred', 'rejected' => 'Rejected', 'failed' => 'Needs attention'];
    $areaLabels = ['engineering' => 'Engineering', 'content' => 'Learning & audio', 'quality' => 'Quality', 'growth' => 'Growth'];
    $reviewCount = $counts['review'] ?? 0;
    $activeCount = $counts['running'] ?? 0;
@endphp
<div class="stats-grid">
    <a href="{{ route('dashboard', ['status' => 'review']) }}" class="stat-card stat-featured"><div class="stat-label">Awaiting your decision @include('mission.icon', ['name' => 'queue', 'class' => 'size-4'])</div><div class="stat-value">{{ $reviewCount }}<span class="stat-caption">ready to review</span></div></a>
    <a href="{{ route('dashboard', ['status' => 'running']) }}" class="stat-card"><div class="stat-label">In progress @include('mission.icon', ['name' => 'clock', 'class' => 'size-4'])</div><div class="stat-value">{{ $activeCount }}<span class="stat-caption">with your team</span></div></a>
    <a href="{{ route('dashboard', ['status' => 'backlog']) }}" class="stat-card"><div class="stat-label">Backlog @include('mission.icon', ['name' => 'file', 'class' => 'size-4'])</div><div class="stat-value">{{ $counts['backlog'] ?? 0 }}<span class="stat-caption">ready to prioritize</span></div></a>
    <a href="{{ route('dashboard', ['status' => 'merged']) }}" class="stat-card"><div class="stat-label">Merged locally @include('mission.icon', ['name' => 'branch', 'class' => 'size-4'])</div><div class="stat-value">{{ $counts['merged'] ?? 0 }}<span class="stat-caption">on the local branch</span></div></a>
</div>
