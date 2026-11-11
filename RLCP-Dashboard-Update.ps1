# RLCP dashboard redesign. Run in Windows PowerShell from the Laravel project.
param([string]$ProjectPath = 'C:\rlcsystem\app')
$ErrorActionPreference = 'Stop'
$utf8 = [System.Text.UTF8Encoding]::new($false)
$dashboardPath = Join-Path $ProjectPath 'resources\views\dashboard.blade.php'
$sidebarPath = Join-Path $ProjectPath 'resources\views\layouts\app\sidebar.blade.php'
$appCssPath = Join-Path $ProjectPath 'resources\css\app.css'
$headPath = Join-Path $ProjectPath 'resources\views\partials\head.blade.php'
$iconPath = Join-Path $ProjectPath 'resources\views\components\rlcp-icon.blade.php'
$designCssPath = Join-Path $ProjectPath 'resources\css\rlcp-dashboard.css'
foreach ($required in @($dashboardPath,$sidebarPath,$appCssPath,$headPath,(Join-Path $ProjectPath 'artisan'))) {
    if (-not (Test-Path -LiteralPath $required)) { throw "Required file missing: $required" }
}
$sidebar = [System.IO.File]::ReadAllText($sidebarPath).Replace("`r`n", "`n")
$appCss = [System.IO.File]::ReadAllText($appCssPath)
$head = [System.IO.File]::ReadAllText($headPath)
$dashboardCode = @'
<x-layouts::app :title="'Dashboard'">
    <div class="rlc-dashboard">
        @php
            $reportDates = ['date_from' => now('Asia/Manila')->subDays(6)->toDateString(), 'date_to' => now('Asia/Manila')->toDateString()];
            $cards = [
                ['Active Beneficiaries', $activeBeneficiariesCount, 'Excludes archived and inactive profiles', 'users', 'violet', route('beneficiaries.index')],
                ['Program Activities', $activitiesCount, 'All recorded activities', 'calendar', 'green', route('activities.index')],
                ['Attendance Check-ins', $attendanceCount, 'All recorded attendance entries', 'radio', 'blue', route('reports.index')],
                ['Assistance Releases', $assistanceReleasesCount, 'All recorded release transactions', 'document', 'orange', route('reports.index')],
            ];
            $chartMax = max(4, (int) $attendanceTrend->max('count'));
        @endphp

        <div class="rlc-toolbar rlc-enter">
            <form method="GET" action="{{ route('beneficiaries.index') }}" class="rlc-search">
                <x-rlcp-icon name="search" />
                <label class="sr-only" for="dashboard-beneficiary-search">Search beneficiaries by name or ID</label>
                <input id="dashboard-beneficiary-search" name="search" type="search" maxlength="100" placeholder="Search beneficiaries or ID...">
                <button type="submit" aria-label="Search beneficiaries"><x-rlcp-icon name="arrow" /></button>
            </form>
            <button type="button" class="rlc-theme-button" x-data x-on:click="$flux.dark = ! $flux.dark" aria-label="Toggle light or dark mode">
                <span class="dark:hidden"><x-rlcp-icon name="moon" /></span>
                <span class="hidden dark:block"><x-rlcp-icon name="sun" /></span>
            </button>
            <div class="rlc-date"><x-rlcp-icon name="calendar" /><span>{{ now('Asia/Manila')->format('D, M d, Y') }}</span></div>
        </div>

        <header class="rlc-welcome rlc-enter">
            <div class="rlc-landscape" aria-hidden="true">
                <svg viewBox="0 0 1000 180" preserveAspectRatio="xMidYMax slice">
                    <path class="rlc-mountain-back" d="M0 170 130 115 190 130 290 65 340 88 450 30 530 72 600 50 700 108 775 60 855 100 920 72 1000 110V180H0Z"/>
                    <path class="rlc-mountain-front" d="M0 180 190 148 300 120 395 152 520 94 600 132 710 108 780 142 890 125 1000 151V180Z"/>
                    <g class="rlc-church"><path d="M918 174v-70h40v70z M910 104l28-22 28 22z M935 82V60h6v22 M927 67h22v5h-22 M931 117h14v21h-14 M930 151h16v23h-16"/></g>
                </svg>
            </div>
            <div class="rlc-welcome-copy">
                <p class="rlc-eyebrow">Risen Lord Church Phils., Inc.</p>
                <h1>Welcome back, {{ auth()->user()->name }} <span class="rlc-wave" aria-hidden="true">&#128075;</span></h1>
                <p>Here's an overview of your beneficiaries and program activities.</p>
            </div>
        </header>

        <div class="rlc-stats">
            @foreach ($cards as [$label, $count, $description, $icon, $tone, $href])
                <a href="{{ $href }}" class="rlc-panel rlc-stat rlc-enter rlc-interactive rlc-tone-{{ $tone }}" style="--rlc-delay: {{ $loop->index * 45 }}ms">
                    <div class="rlc-stat-top">
                        <span class="rlc-icon-box"><x-rlcp-icon :name="$icon" /></span>
                        <div><h2>{{ $label }}</h2><p class="rlc-stat-number">{{ number_format($count) }}</p></div>
                    </div>
                    <div class="rlc-stat-bottom"><p>{{ $description }}</p><x-rlcp-icon name="arrow" /></div>
                </a>
            @endforeach
        </div>

        <div class="rlc-panels">
            <section class="rlc-panel rlc-attendance rlc-enter" style="--rlc-delay: 120ms">
                <div class="rlc-panel-heading">
                    <div class="rlc-heading-title"><x-rlcp-icon name="calendar" /><div><h2>Attendance Overview</h2><p>Last 7 days &middot; Philippine time</p></div></div>
                    <a class="rlc-button rlc-button-small" href="{{ route('reports.index', $reportDates) }}">View details <x-rlcp-icon name="arrow" /></a>
                </div>
                <div class="rlc-chart" role="img" aria-label="Attendance during the last seven days: {{ $attendanceTrend->map(fn ($day) => $day['label'].' '.$day['date'].': '.$day['count'])->implode('; ') }}">
                    <div class="rlc-y-axis" aria-hidden="true">
                        @for ($tick = 4; $tick >= 0; $tick--)
                            <span>{{ rtrim(rtrim(number_format($chartMax * $tick / 4, 1), '0'), '.') }}</span>
                        @endfor
                    </div>
                    <div class="rlc-chart-main">
                        <div class="rlc-plot">
                            @foreach ($attendanceTrend as $day)
                                <div class="rlc-bar-slot" title="{{ $day['date'] }}: {{ $day['count'] }} check-ins">
                                    <div class="rlc-bar" style="height: {{ $day['count'] / $chartMax * 100 }}%; --rlc-bar-delay: {{ $loop->index * 60 }}ms"><span>{{ $day['count'] }}</span></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="rlc-x-axis" aria-hidden="true">
                            @foreach ($attendanceTrend as $day)
                                <div><strong>{{ $day['label'] }}</strong><span>{{ $day['date'] }}</span></div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <p class="rlc-chart-caption">{{ number_format($attendanceTrend->sum('count')) }} check-ins this period</p>
            </section>

            <section class="rlc-panel rlc-today rlc-enter" style="--rlc-delay: 170ms">
                <div class="rlc-heading-title"><x-rlcp-icon name="calendar" /><div><h2>Today's Activities</h2><p>Up to 5 scheduled activities</p></div></div>
                <div class="rlc-list">
                    @forelse ($todaysActivities as $activity)
                        <a href="{{ route('activities.show', $activity) }}" class="rlc-list-card rlc-interactive">
                            <span class="rlc-dot"></span><div class="rlc-list-copy"><h3>{{ $activity->title }}</h3><p>{{ $activity->start_time ? \Illuminate\Support\Carbon::parse($activity->start_time)->format('g:i A') : 'Time not set' }}</p><span class="rlc-tag">{{ ucwords(str_replace('_', ' ', $activity->development_area)) }}</span></div><x-rlcp-icon name="chevron" />
                        </a>
                    @empty
                        <div class="rlc-empty"><x-rlcp-icon name="check" /><p>No activities scheduled today.</p><a class="rlc-button rlc-button-tinted" href="{{ route('activities.index') }}">View all activities <x-rlcp-icon name="arrow" /></a></div>
                    @endforelse
                </div>
                @if ($todaysActivities->isNotEmpty())
                    <a class="rlc-text-link" href="{{ route('activities.index') }}">View all activities <x-rlcp-icon name="arrow" /></a>
                @endif
            </section>

            <section class="rlc-panel rlc-recent rlc-enter" style="--rlc-delay: 220ms">
                <div class="rlc-panel-heading"><div class="rlc-heading-title"><x-rlcp-icon name="document" /><div><h2>Recent Assistance Releases</h2><p>Latest 5 releases, including archived profiles</p></div></div><a class="rlc-button rlc-button-small" href="{{ route('reports.index') }}">View all</a></div>
                <div class="rlc-list">
                    @forelse ($recentAssistance as $transaction)
                        {{-- rlc-archived-assistance-card --}}
                        @if ($transaction->beneficiary && ! $transaction->beneficiary->trashed())
                            <a href="{{ route('beneficiaries.show', $transaction->beneficiary) }}" class="rlc-list-card rlc-interactive">
                        @else
                            <div class="rlc-list-card">
                        @endif
                            <span class="rlc-avatar"><x-rlcp-icon name="users" /></span>
                            <div class="rlc-list-copy"><h3>{{ $transaction->item_name }}</h3><p>{{ $transaction->beneficiary?->full_name ?? 'Beneficiary unavailable' }}</p><p>{{ ucfirst($transaction->category) }} &middot; Qty: {{ $transaction->quantity }}</p><p class="rlc-timestamp">{{ $transaction->released_at->copy()->timezone('Asia/Manila')->format('M d, Y g:i A') }} PHT</p>
                                @if ($transaction->beneficiary?->trashed())<span class="rlc-tag">Archived</span>@endif
                            </div>
                            @if ($transaction->beneficiary && ! $transaction->beneficiary->trashed())<x-rlcp-icon name="chevron" /></a>@else</div>@endif
                    @empty
                        <div class="rlc-empty"><x-rlcp-icon name="document" /><p>No assistance releases to display.</p></div>
                    @endforelse
                </div>
            </section>

            <section class="rlc-panel rlc-upcoming rlc-enter" style="--rlc-delay: 260ms">
                <div class="rlc-panel-heading"><div class="rlc-heading-title"><x-rlcp-icon name="calendar" /><h2>Upcoming Activities</h2></div>
                    @can('create', \App\Models\ProgramActivity::class)<a class="rlc-button rlc-button-primary rlc-button-small" href="{{ route('activities.create') }}">+ Add Activity</a>@endcan
                </div>
                <div class="rlc-list">
                    @forelse ($upcomingActivities as $activity)
                        <a class="rlc-list-card rlc-upcoming-card rlc-interactive" href="{{ route('activities.show', $activity) }}">
                            <span class="rlc-calendar-tile"><span>{{ $activity->activity_date->format('M') }}</span><strong>{{ $activity->activity_date->format('d') }}</strong><span>{{ $activity->activity_date->format('Y') }}</span></span>
                            <div class="rlc-list-copy"><h3>{{ $activity->title }}</h3><div class="rlc-activity-meta"><span><x-rlcp-icon name="clock" />{{ $activity->start_time ? \Illuminate\Support\Carbon::parse($activity->start_time)->format('g:i A') : 'Time not set' }}</span><span><x-rlcp-icon name="pin" />{{ $activity->location ?: 'Location not set' }}</span></div><span class="rlc-tag">{{ ucwords(str_replace('_', ' ', $activity->development_area)) }}</span></div><x-rlcp-icon name="chevron" />
                        </a>
                    @empty
                        <div class="rlc-empty rlc-empty-compact"><p>No upcoming activities recorded.</p><a class="rlc-text-link" href="{{ route('activities.index') }}">View activities <x-rlcp-icon name="arrow" /></a></div>
                    @endforelse
                </div>
            </section>

            <section class="rlc-panel rlc-quick rlc-enter" style="--rlc-delay: 300ms">
                <div class="rlc-heading-title"><x-rlcp-icon name="bolt" /><h2>Quick Actions</h2></div>
                <div class="rlc-list">
                    @can('create', \App\Models\Beneficiary::class)<a class="rlc-button rlc-button-primary rlc-quick-action" href="{{ route('beneficiaries.create') }}"><x-rlcp-icon name="user-plus" /><span>Register Beneficiary</span><x-rlcp-icon name="chevron" /></a>@endcan
                    <a class="rlc-button rlc-quick-action" href="{{ route('beneficiaries.index') }}"><x-rlcp-icon name="users" /><span>View Beneficiaries</span><x-rlcp-icon name="chevron" /></a>
                    <a class="rlc-button rlc-quick-action" href="{{ route('nfc.index') }}"><x-rlcp-icon name="radio" /><span>NFC Lookup</span><x-rlcp-icon name="chevron" /></a>
                </div>
            </section>
        </div>
    </div>
</x-layouts::app>
'@
$iconCode = @'
@props(['name' => 'users'])
@php
    $paths = [
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M16 3a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        'calendar' => 'M8 2v4 M16 2v4 M3 10h18 M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2 M8 14h3 M8 18h7',
        'radio' => 'M5 5a10 10 0 0 0 0 14 M19 5a10 10 0 0 1 0 14 M8 8a6 6 0 0 0 0 8 M16 8a6 6 0 0 1 0 8 M14 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0',
        'document' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M8 12h8 M8 16h8',
        'arrow' => 'M5 12h14 M13 6l6 6-6 6',
        'chevron' => 'M9 5l7 7-7 7',
        'search' => 'M21 21l-5-5 M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
        'clock' => 'M12 8v4l3 2 M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
        'pin' => 'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0 M15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
        'bolt' => 'M13 2L3 14h8l-1 8 11-12h-8z',
        'user-plus' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M20 8v6 M17 11h6',
        'check' => 'M9 12l2 2 4-4 M8 2v4 M16 2v4 M3 10h18 M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2',
        'moon' => 'M21 13a9 9 0 1 1-10-10 7 7 0 0 0 10 10',
        'sun' => 'M12 2v2 M12 20v2 M2 12h2 M20 12h2 M4.93 4.93l1.42 1.42 M17.65 17.65l1.42 1.42 M4.93 19.07l1.42-1.42 M17.65 6.35l1.42-1.42 M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
    ];
@endphp
<svg {{ $attributes->merge(['class' => 'rlc-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="{{ $paths[$name] ?? $paths['users'] }}" />
</svg>
'@
$designCssCode = @'
/* RLCP dashboard v1. Scoped styles; keeps existing forms and pages intact. */
.rlc-shell { background:#f5f6fc; }
.dark .rlc-shell { background:#101118; }
.rlc-dashboard { --rlc-ink:#11132e; --rlc-muted:#697391; --rlc-border:#e9eaf5; --rlc-surface:rgba(255,255,255,.96); --rlc-soft:#f7f8fd; --rlc-violet:#7439f7; --rlc-tint:#f1ecff; color:var(--rlc-ink); max-width:1600px; margin:auto; padding:20px 28px 36px; position:relative; isolation:isolate; }
.dark .rlc-dashboard { --rlc-ink:#f6f5fc; --rlc-muted:#aaaabd; --rlc-border:#2c2d3c; --rlc-surface:rgba(25,26,35,.97); --rlc-soft:#1f202b; --rlc-violet:#b27bff; --rlc-tint:#2b2141; }
.rlc-dashboard * { box-sizing:border-box; }
.rlc-dashboard a { text-decoration:none; }
.rlc-dashboard button { cursor:pointer; }
.rlc-dashboard :is(a,button,input):focus-visible, .rlc-brand:focus-visible { outline:3px solid #9b73ff; outline-offset:4px; }
.rlc-icon { width:22px; height:22px; flex-shrink:0; display:block; }
.rlc-toolbar { display:flex; gap:12px; align-items:center; justify-content:flex-end; position:relative; z-index:2; }
.rlc-search { display:flex; align-items:center; gap:10px; width:340px; background:var(--rlc-surface); border:1px solid var(--rlc-border); border-radius:16px; min-height:46px; padding:6px 10px 6px 16px; color:var(--rlc-muted); box-shadow:0 4px 16px #34307704; }
.rlc-search input { width:100%; min-width:0; border:0; background:transparent; color:var(--rlc-ink); font-size:13px; padding:6px 0; outline:none; }
.rlc-search:focus-within { border-color:var(--rlc-violet); box-shadow:0 0 0 3px #8b5cf618; }
.rlc-search input::placeholder { color:var(--rlc-muted); }
.rlc-search button { border:0; border-radius:8px; padding:7px; background:var(--rlc-tint); color:var(--rlc-violet); }
.rlc-search button .rlc-icon { width:17px; height:17px; }
.rlc-date,.rlc-theme-button { display:flex; gap:9px; align-items:center; justify-content:center; min-height:46px; border:1px solid var(--rlc-border); background:var(--rlc-surface); border-radius:16px; padding:10px 15px; font-size:12px; font-weight:600; white-space:nowrap; }
.rlc-theme-button { width:46px; padding:10px; color:var(--rlc-violet); }
.rlc-welcome { position:relative; min-height:115px; padding:20px 6px 22px; }
.rlc-welcome-copy { position:relative; z-index:1; max-width:900px; }
.rlc-eyebrow { font-size:10px; letter-spacing:.13em; text-transform:uppercase; font-weight:600; color:var(--rlc-muted); margin:0 0 7px; }
.rlc-welcome h1 { font-size:clamp(25px,2.6vw,38px); line-height:1.2; letter-spacing:-.035em; font-weight:750; margin:0; overflow-wrap:anywhere; }
.rlc-welcome-copy>p:last-child { font-size:15px; color:var(--rlc-muted); margin:9px 0 0; }
.rlc-wave { display:inline-block; transform-origin:75% 75%; animation:rlc-wave 1.1s ease-in-out .35s; }
.rlc-landscape { position:absolute; z-index:-1; pointer-events:none; top:-82px; right:-28px; width:85%; height:210px; overflow:hidden; background:radial-gradient(ellipse at 95% 25%,#e8defb 0,transparent 65%),linear-gradient(180deg,#e9eefb90,transparent); }
.rlc-landscape svg { position:absolute; width:100%; height:100%; bottom:0; opacity:.34; }
.rlc-mountain-back { fill:#b7c3df; }.rlc-mountain-front { fill:#9eacca; }.rlc-church { fill:#8897b7; }
.dark .rlc-landscape { background:radial-gradient(ellipse at 95% 30%,#a6666560,transparent 62%),linear-gradient(180deg,#43305570,transparent); }
.dark .rlc-landscape svg { opacity:.32; }.dark .rlc-mountain-back { fill:#493e62; }.dark .rlc-mountain-front { fill:#24243a; }.dark .rlc-church { fill:#9c8aa7; }
.rlc-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:20px; }
.rlc-panel { min-width:0; background:var(--rlc-surface); border:1px solid var(--rlc-border); border-radius:20px; padding:21px; box-shadow:0 5px 24px #35377704; }
.dark .rlc-panel { box-shadow:0 4px 24px #0000000a; }
.rlc-stat { display:block; color:var(--rlc-ink); padding:22px; }
.rlc-stat-top { display:flex; align-items:center; gap:16px; }.rlc-stat-top>div { min-width:0; }
.rlc-icon-box { width:54px; height:54px; border-radius:15px; display:flex; align-items:center; justify-content:center; background:var(--rlc-tint); color:var(--rlc-violet); flex-shrink:0; }
.rlc-icon-box .rlc-icon { width:30px; height:30px; }
.rlc-stat h2 { font-size:12px; font-weight:600; margin:0; }.rlc-stat-number { font-size:35px; line-height:1.15; font-weight:750; letter-spacing:-.04em; margin:6px 0 0; }
.rlc-stat-bottom { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:17px; }.rlc-stat-bottom p { font-size:11px; line-height:1.5; color:var(--rlc-muted); margin:0; }.rlc-stat-bottom .rlc-icon { width:16px; height:16px; color:var(--rlc-muted); opacity:.6; }
.rlc-tone-green .rlc-icon-box { color:#0e9b56; background:#dbf7e9; }.rlc-tone-blue .rlc-icon-box { color:#287bfa; background:#e4eeff; }.rlc-tone-orange .rlc-icon-box { color:#f47725; background:#fff0e6; }
.dark .rlc-tone-green .rlc-icon-box { color:#62e9a0; background:#203c32; }.dark .rlc-tone-blue .rlc-icon-box { color:#68a8ff; background:#21334e; }.dark .rlc-tone-orange .rlc-icon-box { color:#ffa66b; background:#432d22; }
.rlc-panels { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:18px; align-items:start; }
.rlc-attendance { grid-column:span 5; }.rlc-today { grid-column:span 3; }.rlc-recent { grid-column:span 4; }.rlc-upcoming { grid-column:span 8; }.rlc-quick { grid-column:span 4; }
.rlc-panel-heading { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }.rlc-heading-title { display:flex; align-items:flex-start; gap:11px; min-width:0; }.rlc-heading-title>.rlc-icon { color:var(--rlc-violet); width:24px; height:24px; margin-top:1px; }.rlc-heading-title>div { min-width:0; }
.rlc-heading-title h2 { margin:0; font-size:16px; line-height:1.35; font-weight:700; letter-spacing:-.02em; }.rlc-heading-title p { margin:5px 0 0; font-size:11px; line-height:1.45; color:var(--rlc-muted); }
.rlc-button { display:inline-flex; align-items:center; justify-content:center; gap:9px; border:1px solid var(--rlc-border); background:var(--rlc-surface); color:var(--rlc-violet); border-radius:11px; padding:11px 14px; min-height:42px; font-size:12px; font-weight:600; line-height:1.35; text-align:center; transition:background .18s, transform .18s,box-shadow .18s; }
.rlc-button-small { min-height:34px; padding:8px 11px; font-size:11px; flex-shrink:0; }.rlc-button .rlc-icon { width:16px; height:16px; }.rlc-button-primary { color:#fff; background:linear-gradient(110deg,#6651fb,#9634f9); border-color:transparent; box-shadow:0 4px 12px #813dff20; }.rlc-button-tinted { background:var(--rlc-tint); border-color:transparent; }
.rlc-button:hover { background:var(--rlc-tint); transform:translateY(-1px); }.rlc-button-primary:hover { background:linear-gradient(110deg,#5844e7,#8728eb); box-shadow:0 7px 17px #813dff30; }
.rlc-chart { display:flex; gap:10px; margin-top:37px; }.rlc-y-axis { display:flex; flex-direction:column; justify-content:space-between; height:155px; font-size:10px; color:var(--rlc-muted); min-width:23px; align-items:flex-end; transform:translateY(-6px); }
.rlc-chart-main { flex:1; min-width:0; }.rlc-plot { height:155px; display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); border-left:1px solid var(--rlc-border); border-bottom:1px solid var(--rlc-border); background:repeating-linear-gradient(to top,transparent 0,transparent calc(25% - 1px),var(--rlc-border) calc(25% - 1px),var(--rlc-border) 25%); }
.rlc-bar-slot { display:flex; align-items:flex-end; justify-content:center; border-right:1px solid color-mix(in srgb,var(--rlc-border),transparent 45%); padding:0 9px; }.rlc-bar { position:relative; width:30px; max-width:100%; min-height:0; background:linear-gradient(180deg,#9143ff,#7453fa); border-radius:6px 6px 0 0; transform-origin:bottom; animation:rlc-bar-in .7s ease-out both; animation-delay:var(--rlc-bar-delay,0ms); }.rlc-bar span { position:absolute; bottom:100%; left:50%; transform:translateX(-50%); padding-bottom:8px; font-size:11px; font-weight:650; color:var(--rlc-ink); }
.rlc-x-axis { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); text-align:center; margin-top:10px; }.rlc-x-axis strong { display:block; font-size:10px; font-weight:600; }.rlc-x-axis span { display:block; font-size:9px; margin-top:4px; color:var(--rlc-muted); white-space:nowrap; }.rlc-chart-caption { margin:18px 0 0; font-size:11px; color:var(--rlc-muted); }
.rlc-list { display:flex; flex-direction:column; gap:10px; margin-top:19px; }.rlc-list-card { display:flex; align-items:center; gap:13px; background:var(--rlc-soft); border:1px solid var(--rlc-border); padding:15px; border-radius:13px; color:var(--rlc-ink); min-width:0; }.rlc-list-copy { flex:1; min-width:0; }.rlc-list-copy h3 { margin:0; font-size:13px; font-weight:650; overflow-wrap:anywhere; }.rlc-list-copy p { margin:5px 0 0; font-size:11px; color:var(--rlc-muted); line-height:1.4; overflow-wrap:anywhere; }.rlc-list-card>.rlc-icon { width:15px; height:15px; color:var(--rlc-muted); }.rlc-timestamp { font-size:10px!important; }
.rlc-avatar { width:42px; height:42px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; background:var(--rlc-tint); color:var(--rlc-violet); }.rlc-avatar .rlc-icon { width:22px; height:22px; }.rlc-dot { width:8px; height:8px; border-radius:50%; background:var(--rlc-violet); flex-shrink:0; }
.rlc-empty { display:flex; align-items:center; justify-content:center; flex-direction:column; gap:17px; min-height:217px; padding:23px 10px; border-radius:14px; background:var(--rlc-soft); text-align:center; }.rlc-empty>.rlc-icon { width:48px; height:48px; color:#c9c2ed; }.dark .rlc-empty>.rlc-icon { color:#73688d; }.rlc-empty p { font-size:12px; margin:0; color:var(--rlc-muted); }.rlc-empty-compact { min-height:110px; }
.rlc-text-link { display:inline-flex; align-items:center; gap:8px; color:var(--rlc-violet); font-size:12px; font-weight:600; margin-top:15px; }.rlc-text-link .rlc-icon { width:15px; height:15px; }.rlc-empty .rlc-text-link { margin-top:0; }
.rlc-calendar-tile { width:66px; padding:10px 5px; background:var(--rlc-tint); color:var(--rlc-violet); border-radius:12px; display:flex; flex-direction:column; align-items:center; gap:2px; flex-shrink:0; }.rlc-calendar-tile span { font-size:10px; }.rlc-calendar-tile>span:first-child { text-transform:uppercase; font-weight:650; }.rlc-calendar-tile strong { font-size:25px; line-height:1.15; color:var(--rlc-ink); }
.rlc-activity-meta { display:flex; flex-wrap:wrap; gap:10px 20px; margin-top:8px; font-size:11px; color:var(--rlc-muted); }.rlc-activity-meta span { display:flex; align-items:center; gap:6px; min-width:0; overflow-wrap:anywhere; }.rlc-activity-meta .rlc-icon { width:15px; height:15px; }.rlc-tag { display:inline-block; background:var(--rlc-tint); color:var(--rlc-muted); padding:3px 7px; margin-top:8px; font-size:10px; border-radius:6px; }
.rlc-quick-action { display:flex; justify-content:flex-start; width:100%; padding:14px 16px; min-height:50px; }.rlc-quick-action span { flex:1; text-align:left; }.rlc-quick-action .rlc-icon { width:20px; height:20px; }.rlc-quick-action>.rlc-icon:last-child { width:16px; height:16px; }
.rlc-interactive { transition:border-color .18s,box-shadow .18s,background .18s,translate .18s; }.rlc-interactive:hover { border-color:#ad8bf780; box-shadow:0 8px 22px #6c42bc0c; translate:0 -2px; }.rlc-interactive:active { translate:0 0; }
.rlc-enter { animation:rlc-enter .45s ease-out both; animation-delay:var(--rlc-delay,0ms); }
@keyframes rlc-enter { from { opacity:0; transform:translateY(9px); } to { opacity:1; transform:translateY(0); } }
@keyframes rlc-bar-in { from { transform:scaleY(.02); } to { transform:scaleY(1); } }
@keyframes rlc-wave { 0%,100% { transform:rotate(0); } 25%,65% { transform:rotate(15deg); } 45%,85% { transform:rotate(-7deg); } }
.rlc-sidebar { background:#ffffff!important; border-color:#e9eaf5!important; }
.dark .rlc-sidebar { background:linear-gradient(150deg,#191a25,#14151d)!important; border-color:#2a2b39!important; }
.rlc-brand { display:flex; align-items:center; gap:11px; padding:5px 0 22px; min-width:0; color:#11132e; text-decoration:none; }.dark .rlc-brand { color:#f7f6ff; }
.rlc-brand-mark { display:flex; width:46px; height:46px; align-items:center; justify-content:center; flex-shrink:0; color:#fff; border-radius:50%; background:linear-gradient(130deg,#6755ff,#972eff); box-shadow:0 4px 14px #7f46ff20; }.rlc-brand-mark .rlc-icon { width:27px; height:27px; }.rlc-brand strong { display:block; font-size:23px; line-height:1.2; letter-spacing:-.03em; }.rlc-brand small { display:block; font-size:9px; line-height:1.5; color:#727b98; margin-top:4px; }.dark .rlc-brand small { color:#a5a6bc; }
.rlc-sidebar [data-flux-sidebar-item] { border-radius:11px; min-height:44px; margin-bottom:5px; transition:background .18s,color .18s; }
.rlc-sidebar [data-flux-sidebar-item][data-current] { background:#eee8ff!important; color:#7439f7!important; }.dark .rlc-sidebar [data-flux-sidebar-item][data-current] { background:#342256!important; color:#d0b4ff!important; }
.rlc-sidebar-footer { border-top:1px solid #e9eaf5; padding-top:16px; margin-top:20px; }.dark .rlc-sidebar-footer { border-color:#2a2b39; }
@media (min-width:1500px) { .rlc-panel { padding:24px; }.rlc-stat { padding:24px; }.rlc-heading-title h2 { font-size:18px; }.rlc-chart { margin-top:41px; }.rlc-plot,.rlc-y-axis { height:177px; } }
@media (max-width:1279px) { .rlc-stat-top { gap:11px; }.rlc-icon-box { width:44px; height:44px; }.rlc-stat { padding:18px; }.rlc-attendance { grid-column:span 7; }.rlc-today { grid-column:span 5; }.rlc-recent { grid-column:span 7; }.rlc-quick { grid-column:span 5; grid-row:2; grid-column-start:8; }.rlc-upcoming { grid-column:span 12; }.rlc-dashboard { padding:20px; } }
@media (max-width:1023px) { .rlc-stats { grid-template-columns:repeat(2,minmax(0,1fr)); }.rlc-dashboard { padding:20px; }.rlc-landscape { right:-20px; }.rlc-toolbar { flex-wrap:wrap; }.rlc-search { margin-right:auto; }.rlc-welcome { padding-top:25px; } }
@media (max-width:639px) { .rlc-dashboard { padding:14px 12px 26px; }.rlc-toolbar { gap:8px; }.rlc-search { width:100%; order:3; margin:0; }.rlc-date { margin-right:auto; font-size:11px; }.rlc-theme-button { order:2; }.rlc-welcome { padding:23px 3px; }.rlc-welcome-copy>p:last-child { font-size:13px; max-width:330px; line-height:1.6; }.rlc-eyebrow { font-size:9px; }.rlc-landscape { right:-12px; width:100%; top:-8px; opacity:.65; }.rlc-stats { gap:10px; margin-bottom:14px; }.rlc-stat { padding:15px 12px; border-radius:15px; }.rlc-stat-top { align-items:flex-start; flex-direction:column; gap:10px; }.rlc-icon-box { width:38px; height:38px; border-radius:10px; }.rlc-icon-box .rlc-icon { width:23px; height:23px; }.rlc-stat-number { font-size:30px; }.rlc-stat h2 { font-size:11px; }.rlc-stat-bottom { margin-top:10px; }.rlc-stat-bottom p { font-size:10px; }.rlc-stat-bottom>.rlc-icon { display:none; }.rlc-panels { display:flex; flex-direction:column; gap:14px; }.rlc-panels>.rlc-panel { width:100%; padding:18px 15px; border-radius:17px; }.rlc-heading-title h2 { font-size:15px; }.rlc-panel-heading { gap:8px; }.rlc-heading-title { gap:8px; }.rlc-heading-title>.rlc-icon { width:21px; height:21px; }.rlc-button-small { padding:7px 9px; font-size:10px; }.rlc-bar-slot { padding:0 5px; }.rlc-x-axis span { font-size:8px; }.rlc-list-card { padding:13px 11px; gap:10px; }.rlc-calendar-tile { width:52px; }.rlc-activity-meta { gap:7px; flex-direction:column; }.rlc-quick { order:5; }.rlc-empty { min-height:190px; }.rlc-empty-compact { min-height:110px; } }
@media (prefers-reduced-motion:reduce) { .rlc-enter,.rlc-wave,.rlc-bar { animation:none!important; }.rlc-interactive,.rlc-button,.rlc-sidebar [data-flux-sidebar-item] { transition:none!important; }.rlc-interactive:hover,.rlc-button:hover { translate:none; transform:none; } }

'@
$brandCode = @'
<a class="rlc-brand" href="{{ route('dashboard') }}" wire:navigate>
                    <span class="rlc-brand-mark"><x-rlcp-icon name="users" /></span>
                    <span><strong>RLCP</strong><small>Risen Lord Church Phils., Inc.</small></span>
                </a>
'@
$oldExternalNav = @'
            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>
'@
$newFooter = @'
            <flux:sidebar.nav class="rlc-sidebar-footer">
                <flux:sidebar.item icon="cog" :href="route('profile.edit')" :current="request()->routeIs('profile.*')" wire:navigate>
                    {{ __('Settings') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>
'@

# Prepare all replacements before writing. Preserve mobile menu and logout.
if (-not $sidebar.Contains('class="rlc-brand"')) {
    $logoLine = '<x-app-logo :sidebar="true" href="{{ route(''dashboard'') }}" wire:navigate />'
    if ([regex]::Matches($sidebar,[regex]::Escape($logoLine)).Count -ne 1) {
        throw 'Expected sidebar logo missing or duplicated. No changes saved.'
    }
    $sidebar = $sidebar.Replace($logoLine,$brandCode)
}
if (-not $sidebar.Contains('rlc-sidebar-footer')) {
    if (-not $sidebar.Contains($oldExternalNav.Replace("`r`n","`n"))) {
        throw 'Expected starter sidebar links not found. No changes saved.'
    }
    $sidebar = $sidebar.Replace($oldExternalNav.Replace("`r`n","`n"),$newFooter.Replace("`r`n","`n"))
}
$sidebar = $sidebar.Replace('class="dark"','')
foreach ($styleTarget in @(
    @{ Pattern='<body(?=\s|>)[^>]*>'; Class='rlc-shell' },
    @{ Pattern='<flux:sidebar(?=\s|>)[^>]*>'; Class='rlc-sidebar' }
)) {
    $tagMatches = [regex]::Matches($sidebar,$styleTarget.Pattern)
    if ($tagMatches.Count -ne 1) {
        throw "Expected one layout tag for $($styleTarget.Class). No changes saved."
    }
    $tagMatch = $tagMatches[0]
    $tag = $tagMatch.Value
    $classMatch = [regex]::Match($tag,'\bclass\s*=\s*"(?<classes>[^"]*)"')
    if (-not $classMatch.Success) {
        throw "Expected class attribute on $($styleTarget.Class). No changes saved."
    }
    $classes = $classMatch.Groups['classes'].Value.Replace('border-zinc-200bg-zinc-50','border-zinc-200 bg-zinc-50')
    if (($classes -split '\s+') -notcontains $styleTarget.Class) {
        $classes = $styleTarget.Class + ' ' + $classes
    }
    $newTag = $tag.Substring(0,$classMatch.Index) + 'class="' + $classes + '"' + $tag.Substring($classMatch.Index + $classMatch.Length)
    $sidebar = $sidebar.Substring(0,$tagMatch.Index) + $newTag + $sidebar.Substring($tagMatch.Index + $tagMatch.Length)
}
if (-not $head.Contains('@fluxAppearance') -and -not $sidebar.Contains('@fluxAppearance')) {
    $sidebar = $sidebar.Replace("@include('partials.head')", "@include('partials.head')" + "`n        @fluxAppearance")
}
$cssImport = "@import './rlcp-dashboard.css';"
if (-not $appCss.Contains($cssImport)) {
    $anchor = "@import '../../vendor/livewire/flux/dist/flux.css';"
    if (-not $appCss.Contains($anchor)) { throw 'Flux CSS import not found. No changes saved.' }
    $appCss = $appCss.Replace($anchor,$anchor + [Environment]::NewLine + $cssImport)
}
$writes = @(
    @{ Path=$iconPath; Content=$iconCode },
    @{ Path=$designCssPath; Content=$designCssCode },
    @{ Path=$appCssPath; Content=$appCss },
    @{ Path=$dashboardPath; Content=$dashboardCode },
    @{ Path=$sidebarPath; Content=$sidebar }
)
$backupDir = Join-Path $ProjectPath ('storage\app\dashboard-backups\' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
$manifest = @()
for ($i=0; $i -lt $writes.Count; $i++) {
    $entry = $writes[$i]
    $exists = Test-Path -LiteralPath $entry.Path
    $backup = Join-Path $backupDir ($i.ToString() + '.bak')
    if ($exists) { Copy-Item -LiteralPath $entry.Path -Destination $backup }
    $manifest += @{ Path=$entry.Path; Existed=$exists; Backup=$backup }
}
$manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $backupDir 'manifest.json') -Encoding UTF8
Push-Location $ProjectPath
try {
    foreach ($entry in $writes) {
        $parent = Split-Path $entry.Path -Parent
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
        [System.IO.File]::WriteAllText($entry.Path,$entry.Content,$utf8)
    }
    & php artisan view:clear
    if ($LASTEXITCODE -ne 0) { throw 'View clear failed.' }
    & php artisan view:cache
    if ($LASTEXITCODE -ne 0) { throw 'Blade compilation failed.' }
    & npm run build
    if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }
    $renderCheck = '$user = \App\Models\User::findOrFail(1); \Illuminate\Support\Facades\Auth::setUser($user); $html = app(\App\Http\Controllers\DashboardController::class)->index()->render(); if (! str_contains($html, "rlc-dashboard") || ! str_contains($html, "rlc-brand")) { throw new \RuntimeException("Dashboard markup missing."); } dump("RLCP_DASHBOARD_RENDER_OK");'
    # Native Windows PowerShell can strip embedded double quotes; use PHP single quotes.
    $renderCheck = $renderCheck.Replace('"rlc-dashboard"', "'rlc-dashboard'").Replace('"rlc-brand"', "'rlc-brand'").Replace('"Dashboard markup missing."', "'Dashboard markup missing.'").Replace('"RLCP_DASHBOARD_RENDER_OK"', "'RLCP_DASHBOARD_RENDER_OK'")
    $renderOutput = & php artisan tinker --execute="$renderCheck" 2>&1
    $renderExit = $LASTEXITCODE
    $renderText = $renderOutput | Out-String
    Write-Host $renderText
    if ($renderExit -ne 0 -or -not $renderText.Contains('RLCP_DASHBOARD_RENDER_OK')) {
        throw 'Dashboard runtime render failed. Review the output above.'
    }
    Write-Host 'RLCP dashboard redesign installed successfully.' -ForegroundColor Green
    Write-Host "Backups: $backupDir"
    Write-Host 'Refresh Dashboard. Check light/dark toggle, search, links, and mobile menu.'
} catch {
    $failure = $_
    foreach ($entry in $manifest) {
        if ($entry.Existed) { Copy-Item -LiteralPath $entry.Backup -Destination $entry.Path -Force }
        elseif (Test-Path -LiteralPath $entry.Path) { Remove-Item -LiteralPath $entry.Path }
    }
    & php artisan view:clear
    & npm run build
    throw "Dashboard update failed; source files restored. $failure"
} finally {
    Pop-Location
}
