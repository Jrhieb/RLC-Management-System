@php
    // No activity publication rule exists. This public view must not query operational records.
    $requestedFilter = request()->query('filter', 'upcoming');
    $filter = in_array($requestedFilter, ['upcoming', 'past', 'all'], true) ? $requestedFilter : 'upcoming';
    $emptyState = match ($filter) {
        'past' => ['No past public activities.', 'Approved highlights from past activities will appear here.'],
        'all' => ['No public activities yet.', 'Activity details will appear here once approved for public sharing.'],
        default => ['No upcoming public activities.', 'Upcoming dates and locations will appear here once approved for public sharing.'],
    };
@endphp
<!DOCTYPE html>
<html lang="en" class="rlcp-public-document">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Public activity updates from RLCP. Activity information will be shared here once approved for publication.">
    <meta name="robots" content="noindex, nofollow">
    <title>Activities | RLCP</title>
    <link rel="icon" href="{{ asset('images/branding/rlc-logo.png') }}" type="image/png">
    @fonts
    @vite(['resources/css/public-home.css', 'resources/js/public-home.js'])
</head>
<body class="rlcp-public public-activities-page" id="page-top" data-public-home>
    <a class="public-skip" href="#main-content">Skip to content</a>
    <div class="activities-masthead">
        <x-public.header :pages-only="true" />
        <section class="activities-hero public-container" aria-labelledby="activities-title">
            <h1 id="activities-title">Community in Action</h1>
            <nav class="activities-breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><span aria-current="page">Activities</span>
            </nav>
            <p class="activities-photo-note"><x-public.icon name="image" /> Community photograph pending approval</p>
        </section>
    </div>

    <main class="activities-main public-container" id="main-content" tabindex="-1">
        <section class="activities-feature-empty" aria-labelledby="activities-feature-title">
            <span class="activities-feature-icon"><x-public.icon name="community" /></span>
            <div>
                <p class="public-eyebrow">Life in our community</p>
                <h2 id="activities-feature-title">No public activities to share yet.</h2>
                <p>When activities are approved for public sharing, you’ll find them here.</p>
            </div>
        </section>

        <nav class="activities-filters" aria-label="Activity filters">
            @foreach (['upcoming' => 'Upcoming', 'past' => 'Past Activities', 'all' => 'All Activities'] as $value => $label)
                <a href="{{ route('public.activities.index', ['filter' => $value]) }}#activity-list" @if ($filter === $value) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        <section class="activities-filtered-empty" id="activity-list" tabindex="-1" aria-labelledby="activity-list-title">
            <span class="activities-calendar-icon"><x-public.icon name="calendar" /></span>
            <h2 id="activity-list-title">{{ $emptyState[0] }}</h2>
            <p>{{ $emptyState[1] }}</p>
            <a class="activities-programs-link" href="{{ route('programs') }}">Explore our programs <x-public.icon name="arrow" /></a>
        </section>

        <p class="activities-publication-note"><x-public.icon name="faith" /><span>Only information approved for public sharing will appear on this page.</span></p>
    </main>
    <x-public.footer :pages-only="true" />
    <x-registration.dialog />
    <x-staff-login-dialog />
</body>
</html>
