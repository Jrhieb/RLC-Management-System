@props(['name' => 'arrow'])
<svg {{ $attributes }} width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16" />
            @break
        @case('faith')
            <path d="M12 5c-3-2-6-2-9-1v15c3-1 6-1 9 1 3-2 6-2 9-1V4c-3-1-6-1-9 1Zm0 0v15M6 7h3M6 10h3M15 7h3M15 10h3" />
            @break
        @case('learning')
            <path d="m2 9 10-5 10 5-10 5L2 9Zm4 3v6c4 3 8 3 12 0v-6M22 9v8" />
            @break
        @case('heart')
            <path d="M20.8 5.6a5.5 5.5 0 0 0-8 .2l-.8.9-.8-.9a5.5 5.5 0 0 0-8-.2c-2 2.2-1.6 5.8.5 7.9L12 22l8.3-8.5c2.1-2.1 2.5-5.7.5-7.9Z" transform="translate(0 -1)" />
            @break
        @case('community')
            <circle cx="12" cy="7" r="3" /><path d="M6 21v-4a6 6 0 0 1 12 0v4H6ZM4 11a3 3 0 0 1 0-6m16 6a3 3 0 0 0 0-6M3 14c-1.5 1-2 2-2 4v2h2m18-6c1.5 1 2 2 2 4v2h-2" />
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2" /><path d="M7 3v4m10-4v4M3 11h18M8 15h2m4 0h2m-8 3h2" />
            @break
        @default
            <path d="M5 12h14m-6-6 6 6-6 6" />
    @endswitch
</svg>
