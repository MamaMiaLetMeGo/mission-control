@extends('mission.layout')
@section('title', 'Settings')
@section('breadcrumb', 'Settings')
@section('content')
@php
    $testCommandValue = old('test_command', $settings['test_command'] ?? []);
    if (is_array($testCommandValue)) {
        $testCommandValue = json_encode($testCommandValue, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
    $previewCommandValue = old('preview_command', $settings['preview_command'] ?? []);
    if (is_array($previewCommandValue)) {
        $previewCommandValue = json_encode($previewCommandValue, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
    $previewPathsValue = old('preview_paths', $settings['preview_paths'] ?? ['/']);
    if (is_array($previewPathsValue)) {
        $previewPathsValue = json_encode($previewPathsValue, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
    $previewEnabled = (bool) old('preview_enabled', $settings['preview_enabled'] ?? false);
@endphp
<div class="page-heading"><div><p class="eyebrow">Your local workspace</p><h1>Settings<span class="heading-dot">.</span></h1><p class="page-description">Connect the project and set the boundaries for your team.</p></div><span class="status-badge {{ ($readiness['ready'] ?? false) ? 'status-merged' : 'status-deferred' }}">{{ ($readiness['ready'] ?? false) ? 'Ready to run' : 'Setup in progress' }}</span></div>
@include('mission.repository-sync')
<div class="settings-grid">
    <form method="POST" action="{{ route('settings.update') }}" class="flex flex-col gap-6 min-w-0">@csrf
        <section class="panel"><div class="panel-heading"><h2>Project connection</h2>@include('mission.icon', ['name' => 'branch', 'class' => 'size-4 text-muted'])</div><div class="p-6 flex flex-col gap-5"><div class="field"><label for="repository-path">Project repository</label><input id="repository-path" name="repository_path" value="{{ old('repository_path', $settings['repository_path'] ?? '') }}" required placeholder="/Users/you/project" spellcheck="false"><p class="field-help">The absolute path to the local Git repository.</p></div><div class="field"><label for="base-branch">Base branch</label><input id="base-branch" name="base_branch" value="{{ old('base_branch', $settings['base_branch'] ?? 'main') }}" required spellcheck="false"><p class="field-help">New tasks start from this local branch. Merges require it to be checked out and clean.</p></div><div class="field"><label for="test-command">Verification command</label><textarea id="test-command" name="test_command" rows="3" class="font-mono text-xs" required spellcheck="false" placeholder='["php", "backend/artisan", "test"]'>{{ $testCommandValue }}</textarea><p class="field-help">A JSON array of arguments, run from the task’s repository root. For example: <code>["npm", "test"]</code>. Shell operators are not supported.</p></div></div></section>
        <section class="panel" id="browser-settings">
            <div class="panel-heading"><h2>Screenshots & preview</h2>@include('mission.icon', ['name' => 'queue', 'class' => 'size-4 text-muted'])</div>
            <div class="p-6 flex flex-col gap-5">
                <input type="hidden" name="preview_enabled" value="0">
                <label class="capture-setting-toggle" for="preview-enabled">
                    <input type="checkbox" id="preview-enabled" name="preview_enabled" value="1" @checked($previewEnabled) data-preview-enabled aria-controls="browser-capture-settings">
                    <span><strong>Capture before and after each task</strong><span>Attach desktop and mobile screenshots, and make the reviewed change available to try locally.</span></span>
                </label>
                <div class="flex flex-col gap-5" id="browser-capture-settings" data-preview-settings>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="field"><label for="preview-driver">Preview application</label><select id="preview-driver" name="preview_driver" data-preview-driver><option value="laravel" @selected(old('preview_driver', $settings['preview_driver'] ?? 'laravel') === 'laravel')>Laravel</option><option value="command" @selected(old('preview_driver', $settings['preview_driver'] ?? 'laravel') === 'command')>Custom server command</option></select></div>
                        <div class="field"><label for="preview-directory">App folder within the repository</label><input id="preview-directory" name="preview_directory" value="{{ old('preview_directory', $settings['preview_directory'] ?? 'backend') }}" placeholder="backend" spellcheck="false"><p class="field-help">Use <code>.</code> when the app is at the repository root.</p></div>
                    </div>
                    <div class="field" data-preview-command-field><label for="preview-command">Server command</label><textarea id="preview-command" name="preview_command" rows="4" class="font-mono text-xs" spellcheck="false" placeholder='["npm", "run", "dev", "--", "--host", "127.0.0.1", "--port", "{port}"]'>{{ $previewCommandValue }}</textarea><p class="field-help">A JSON array of arguments. Include <code>{port}</code> so each preview uses its assigned local port. The command runs in the app folder; shell operators are not supported.</p></div>
                    <div class="preview-driver-note" data-preview-laravel-note>@include('mission.icon', ['name' => 'shield', 'class' => 'size-4 shrink-0'])<p>Laravel previews use a disposable local database. Each preview starts from the exact revision being reviewed.</p></div>
                    <div class="field"><label for="preview-paths">Pages to capture</label><textarea id="preview-paths" name="preview_paths" rows="4" class="font-mono text-xs" spellcheck="false" placeholder='["/", "/lessons"]'>{{ $previewPathsValue }}</textarea><p class="field-help">A JSON list of page paths, beginning with <code>/</code>. Each page is captured before and after the change at desktop and mobile sizes.</p></div>
                    <div class="field"><label for="preview-lifetime">Preview lifetime, seconds</label><input type="number" id="preview-lifetime" name="preview_ttl_seconds" min="60" max="3600" value="{{ old('preview_ttl_seconds', $settings['preview_ttl_seconds'] ?? 1800) }}"><p class="field-help">Previews stop automatically after this time. You can restart one from its review card.</p></div>
                    <p class="text-[11px] text-muted leading-relaxed">Saved settings apply to new runs. Existing review cards keep the capture requirements used for their run.</p>
                </div>
            </div>
        </section>
        <section class="panel"><div class="panel-heading"><h2>Codex & operating limits</h2>@include('mission.icon', ['name' => 'settings', 'class' => 'size-4 text-muted'])</div><div class="p-6 flex flex-col gap-5"><div class="field"><label for="model">Model <span class="font-normal text-muted">optional</span></label><input id="model" name="model" value="{{ old('model', $settings['model'] ?? '') }}" placeholder="Use the configured Codex default" spellcheck="false"><p class="field-help">Leave blank to use the Codex default.</p></div><div class="grid gap-5 sm:grid-cols-2"><div class="field"><label for="runtime">Run timeout, seconds</label><input type="number" id="runtime" name="max_runtime_seconds" min="60" max="720" value="{{ old('max_runtime_seconds', $settings['max_runtime_seconds'] ?? 600) }}" required><p class="field-help">Stops work that exceeds the time limit.</p></div><div class="field"><label for="daily-limit">Runs per day</label><input type="number" id="daily-limit" name="daily_run_limit" min="1" max="100" value="{{ old('daily_run_limit', $settings['daily_run_limit'] ?? 5) }}" required><p class="field-help">One active task is allowed at a time.</p></div></div><div class="field"><label for="token-limit">Token threshold per run</label><input type="number" id="token-limit" name="max_tokens" min="1000" max="500000" step="1000" value="{{ old('max_tokens', $settings['max_tokens'] ?? 50000) }}" required><p class="field-help">Checked between agent turns. This is a soft threshold, not a guaranteed spending cap.</p></div></div></section>
        <div class="flex justify-end"><button type="submit" class="button button-primary">Save settings @include('mission.icon', ['name' => 'check', 'class' => 'size-4'])</button></div>
    </form>
    <aside class="flex flex-col gap-5"><section class="panel"><div class="panel-heading"><h2>Setup checklist</h2></div><ul class="checklist">@foreach(['api_key' => 'OpenAI API key', 'repository' => 'Git repository', 'worker' => 'Codex worker', 'tests' => 'Verification command'] as $key => $label)<li><span class="checklist-icon {{ ($readiness[$key] ?? false) ? 'checklist-ready' : '' }}">@include('mission.icon', ['name' => ($readiness[$key] ?? false) ? 'check' : 'clock', 'class' => 'size-4'])</span><span class="flex-1">{{ $label }}</span><span class="text-xs text-muted">{{ ($readiness[$key] ?? false) ? 'Ready' : 'Needed' }}</span></li>@endforeach</ul>@if(! empty($readiness['issues']))<div class="px-5 pb-5"><ul class="text-xs text-muted leading-relaxed list-disc pl-4 space-y-2">@foreach($readiness['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul></div>@endif</section><section class="small-note"><span class="text-accent">@include('mission.icon', ['name' => 'shield'])</span><div><h3>Your key stays local</h3><p>Add your API key to <code>OPENAI_API_KEY</code> in this app’s <code>.env</code> file. The dashboard never displays it.</p><p class="mt-3">Refresh this page after updating the file. Restart the queue worker if it is already running.</p></div></section><section class="small-note"><span class="text-accent">@include('mission.icon', ['name' => 'branch'])</span><div><h3>Room for focused work</h3><p>Each run gets a separate Git worktree. Your decisions stay attached to the exact revision reviewed.</p></div></section></aside>
</div>
@endsection
