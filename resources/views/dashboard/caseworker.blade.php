<x-layouts::app :title="'Dashboard'">
    <div class="rlc-dashboard rlcp-dashboard-brand rlc-registry rlc-registry-index rlc-caseworker-dashboard" x-data="caseloadDashboard"
         data-caseload-dashboard data-endpoint="{{ route('dashboard.caseload', absolute: false) }}">
        <div class="rlc-toolbar">
            <x-rlcp-beneficiary-search />
            <div class="rlc-date"><x-rlcp-icon name="calendar" /><span>{{ now('Asia/Manila')->format('D, M d, Y') }}</span></div>
        </div>
        <header class="rlc-welcome">
            <div class="rlc-welcome-copy">
                <p class="rlc-eyebrow">My Caseload</p>
                <h1>Welcome back, {{ auth()->user()->name }}</h1>
                <p>Your assigned beneficiaries, excluding archived profiles.</p>
            </div>
        </header>
        <section aria-label="My assigned beneficiary counts" class="rlc-stats rlc-caseload-stats">
            @foreach ([['Assigned Beneficiaries', 'total', 'Active and inactive assigned profiles', '', 'violet'], ['Active', 'active', 'Active assigned beneficiaries', 'active', 'green'], ['Inactive', 'inactive', 'Inactive assigned beneficiaries', 'inactive', 'orange']] as [$label, $key, $description, $status, $tone])
                <a href="{{ route('my-caseload.index', $status ? ['status' => $status] : []) }}" class="rlc-panel rlc-stat rlc-tone-{{ $tone }}">
                    <div class="rlc-stat-top"><span class="rlc-icon-box"><x-rlcp-icon name="users" /></span><div><h2>{{ $label }}</h2><p class="rlc-stat-number" data-caseload-count="{{ $key }}">{{ number_format($caseloadCounts[$key]) }}</p></div></div>
                    <div class="rlc-stat-bottom"><p>{{ $description }}</p><x-rlcp-icon name="arrow" /></div>
                </a>
            @endforeach
        </section>
        <div class="rlc-caseload-panels">
            <section class="rlc-panel" aria-labelledby="my-caseload-preview-title">
                <div class="rlc-panel-heading">
                    <div class="rlc-heading-title"><x-rlcp-icon name="users" /><div><h2 id="my-caseload-preview-title">My Caseload</h2><p>First 5 beneficiaries &middot; Name A&ndash;Z</p></div></div>
                    <button type="button" class="rlc-button rlc-button-small" x-on:click="refresh()" :disabled="refreshing" :aria-busy="refreshing.toString()">Refresh caseload</button>
                </div>
                @include('dashboard.partials.caseload-preview')
                <a class="rlc-text-link" href="{{ route('my-caseload.index') }}" wire:navigate>View My Caseload <x-rlcp-icon name="arrow" /></a>
                <p class="rlc-caseload-error" role="status" aria-live="polite" x-cloak x-show="error" x-text="error"></p>
            </section>
            <section class="rlc-panel" aria-label="Organization-wide upcoming activities">
                <x-dashboard-upcoming-activities :activities="$upcomingActivities" />
            </section>
        </div>
        <p class="rlc-caseload-refresh-note">Caseload counts and preview refresh while this page is visible. <a href="{{ route('beneficiaries.index') }}" wire:navigate>Open the full Registry</a></p>
    </div>
</x-layouts::app>
