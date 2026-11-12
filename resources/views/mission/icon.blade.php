<svg class="{{ $class ?? 'size-5' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('wave')
            <path d="M3 10v4m4-8v12m5-15v18m5-15v12m4-8v4" />
            @break
        @case('queue')
            <rect x="3" y="4" width="18" height="16" rx="3" /><path d="M3 12h5l2 3h4l2-3h5M8 8h8" />
            @break
        @case('profile')
            <path d="M8 4H5a2 2 0 0 0-2 2v14h14a2 2 0 0 0 2-2v-3M8 9h3m-3 5h7" /><path d="m14 5 3 3 4-4M16 3l-4 4" />
            @break
        @case('settings')
            <path d="M4 7h16M4 17h16" /><circle cx="9" cy="7" r="3" fill="currentColor" stroke="none" /><circle cx="15" cy="17" r="3" fill="currentColor" stroke="none" />
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('arrow')
            <path d="M5 12h14m-5-5 5 5-5 5" />
            @break
        @case('back')
            <path d="M19 12H5m5-5-5 5 5 5" />
            @break
        @case('check')
            <path d="m5 12 4 4L19 6" />
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
            @break
        @case('branch')
            <circle cx="6" cy="5" r="2" /><circle cx="6" cy="19" r="2" /><circle cx="18" cy="6" r="2" /><path d="M6 7v10m12-9c0 6-12 3-12 8" />
            @break
        @case('terminal')
            <rect x="3" y="4" width="18" height="16" rx="3" /><path d="m7 9 3 3-3 3m6 0h4" />
            @break
        @case('spark')
            <path d="m12 3 2.4 6.6L21 12l-6.6 2.4L12 21l-2.4-6.6L3 12l6.6-2.4L12 3Z" />
            @break
        @case('alert')
            <circle cx="12" cy="12" r="9" /><path d="M12 7v6m0 4h.01" />
            @break
        @case('chevron')
            <path d="m9 5 7 7-7 7" />
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16" />
            @break
        @case('file')
            <path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z" /><path d="M14 3v6h6M8 13h8m-8 4h5" />
            @break
        @case('refresh')
            <path d="M20 7v5h-5M4 17v-5h5" /><path d="M6 6a8 8 0 0 1 13 3M5 15a8 8 0 0 0 13 3" />
            @break
        @case('shield')
            <path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z" /><path d="m8 12 3 3 5-6" />
            @break
        @default
            <circle cx="12" cy="12" r="8" />
    @endswitch
</svg>
