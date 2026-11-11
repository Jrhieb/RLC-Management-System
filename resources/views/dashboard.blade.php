<x-layouts::app :title="'Dashboard'">
    <div class="rlc-dashboard rlcp-dashboard-brand">
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
            <x-rlcp-beneficiary-search />
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
                <div class="rlc-panel-heading"><div class="rlc-heading-title"><x-rlcp-icon name="document" /><div><h2>Recent Assistance Releases</h2><p>Latest 2 releases, including archived profiles</p></div></div><a class="rlc-button rlc-button-small" href="{{ route('reports.index') }}">View all</a></div>
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
                <x-dashboard-upcoming-activities :activities="$upcomingActivities" />
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
