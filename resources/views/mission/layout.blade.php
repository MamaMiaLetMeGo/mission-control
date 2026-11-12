<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Review queue') · Mission Control</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas text-ink font-sans antialiased">
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <a href="{{ route('dashboard') }}" class="brand">
            <span class="brand-mark">@include('mission.icon', ['name' => 'wave', 'class' => 'size-6'])</span>
            <span><span class="block font-semibold tracking-tight">Mission Control</span><span class="block text-xs text-muted mt-0.5">Agent review queue</span></span>
        </a>
        <div class="sidebar-workspace"><span class="workspace-avatar">F</span><span class="flex-1">Product workspace</span><span class="text-muted text-xs">01</span></div>
        <p class="nav-label">Workspace</p>
        <nav class="flex flex-col gap-1">
            <a class="nav-item {{ request()->routeIs('dashboard', 'tasks.*') ? 'nav-active' : '' }}" href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard', 'tasks.*')) aria-current="page" @endif>
                @include('mission.icon', ['name' => 'queue'])<span>Review queue</span>
                <span class="nav-count" data-review-count @if(($counts['review'] ?? 0) < 1) hidden @endif>{{ $counts['review'] ?? 0 }}</span>
            </a>
            <a class="nav-item {{ request()->routeIs('profile') ? 'nav-active' : '' }}" href="{{ route('profile') }}" @if(request()->routeIs('profile')) aria-current="page" @endif>@include('mission.icon', ['name' => 'profile'])<span>Decision journal</span></a>
            <a class="nav-item {{ request()->routeIs('settings.*') ? 'nav-active' : '' }}" href="{{ route('settings.edit') }}" @if(request()->routeIs('settings.*')) aria-current="page" @endif>@include('mission.icon', ['name' => 'settings'])<span>Settings</span></a>
        </nav>
        <div class="sidebar-team">
            <p class="nav-label">Task team</p>
            <div class="team-row"><span class="role-avatar">C</span><span><strong>Codex implementer</strong><small>Focused implementation</small></span></div>
            <div class="team-row"><span class="role-avatar role-engineering">R</span><span><strong>Independent reviewer</strong><small>Verification & findings</small></span></div>
            <p class="team-note">One focused task at a time.</p>
        </div>
        <div class="sidebar-bottom">
            <div class="local-label"><span class="local-dot"></span>Local workspace</div>
            <p class="text-xs text-muted leading-relaxed">Runs on your machine.<br>Every decision stays with the work.</p>
            <div class="sidebar-user"><span class="user-avatar">You</span><div><strong>Your workspace</strong><span>Owner · local access</span></div></div>
        </div>
    </aside>
    <div class="main-shell">
        <header class="topbar">
            <div class="flex items-center gap-3 min-w-0">
                <button class="icon-button mobile-nav-button" type="button" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false" data-toggle-nav>@include('mission.icon', ['name' => 'menu'])</button>
                <span class="text-muted text-sm hidden sm:inline">Workspace</span><span class="text-line hidden sm:inline">/</span><span class="text-sm font-medium truncate">@yield('breadcrumb', 'Review queue')</span>
            </div>
            <div class="flex items-center gap-2 text-xs text-muted"><span class="local-dot"></span><span>Local only</span></div>
        </header>
        <main id="main-content" class="page-content">
            @if(session('status'))
                <div class="notice notice-success mb-6" role="status">@include('mission.icon', ['name' => 'check'])<span>{{ session('status') }}</span></div>
            @endif
            @if(session('success'))
                <div class="notice notice-success mb-6" role="status">@include('mission.icon', ['name' => 'check'])<span>{{ session('success') }}</span></div>
            @endif
            @if($errors->any())
                <div class="notice notice-error mb-6" role="alert"><span class="shrink-0">@include('mission.icon', ['name' => 'alert'])</span><div><strong class="block mb-1">Please check the following</strong><ul class="list-disc pl-4 space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
            @endif
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
