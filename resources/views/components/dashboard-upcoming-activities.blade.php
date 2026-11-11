@props(['activities'])
<div class="rlc-panel-heading">
    <div class="rlc-heading-title"><x-rlcp-icon name="calendar" /><div><h2>Upcoming Activities</h2><p>Organization-wide activities</p></div></div>
    @can('create', \App\Models\ProgramActivity::class)<a class="rlc-button rlc-button-primary rlc-button-small" href="{{ route('activities.create') }}">+ Add Activity</a>@endcan
</div>
<div class="rlc-list">
    @forelse ($activities as $activity)
        @can('view', $activity)
            <a class="rlc-list-card rlc-upcoming-card rlc-interactive" href="{{ route('activities.show', $activity) }}">
                <span class="rlc-calendar-tile"><span>{{ $activity->activity_date->format('M') }}</span><strong>{{ $activity->activity_date->format('d') }}</strong><span>{{ $activity->activity_date->format('Y') }}</span></span>
                <div class="rlc-list-copy"><h3>{{ $activity->title }}</h3><div class="rlc-activity-meta"><span><x-rlcp-icon name="clock" />{{ $activity->start_time ? \Illuminate\Support\Carbon::parse($activity->start_time)->format('g:i A') : 'Time not set' }}</span><span><x-rlcp-icon name="pin" />{{ $activity->location ?: 'Location not set' }}</span></div><span class="rlc-tag">{{ ucwords(str_replace('_', ' ', $activity->development_area)) }}</span></div><x-rlcp-icon name="chevron" />
            </a>
        @endcan
    @empty
        <div class="rlc-empty rlc-empty-compact"><p>No upcoming activities recorded.</p><a class="rlc-text-link" href="{{ route('activities.index') }}">View activities <x-rlcp-icon name="arrow" /></a></div>
    @endforelse
</div>
