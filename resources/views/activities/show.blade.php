<x-layouts::app :title="'Attendance'">
    @php
        $canRecord = $activity && Gate::allows('recordAttendance', $activity);
        $workspaceConfig = [
            'activity' => $activity ? [
                'id' => $activity->id, 'title' => $activity->title,
                'date' => $activity->activity_date->format('M d, Y'),
                'location' => $activity->location ?: 'Location not specified',
            ] : null,
            'canRecord' => (bool) $canRecord,
            'uidUrl' => $activity ? route('activities.attendance.nfc', $activity) : null,
            'manualUrl' => $activity ? route('activities.attendance.store', $activity) : null,
            'recordsUrl' => route('attendance.index'),
            'exportUrl' => $activity ? route('activities.attendance.export', $activity) : null,
            'search' => $search,
        ];
    @endphp

    <div class="rlc-dashboard rlc-attendance-workspace" data-attendance-workspace data-config="{{ json_encode($workspaceConfig) }}">
        <header class="rlca-page-header">
            <div>
                <nav class="rlca-breadcrumb" aria-label="Breadcrumb">
                    <a href="{{ route('dashboard') }}">RLC System</a>
                    <span aria-hidden="true">›</span>
                    <span aria-current="page">Attendance</span>
                </nav>
                <h1>Attendance</h1>
                <p class="rlca-muted">Track beneficiary check-ins for your selected activity.</p>
            </div>
            <div class="rlca-header-actions">
                <a href="{{ route('activities.index') }}" class="rlca-management-link">Manage Activities</a>
                <div class="rlca-page-buttons">
                    @if ($activity)
                        <a href="{{ route('activities.attendance.export', ['activity' => $activity, 'search' => $search]) }}" class="rlc-button" data-attendance-export>
                            <svg class="rlca-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M5 15v5h14v-5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            Export CSV
                        </a>
                    @endif
                    @if ($canRecord)
                        <a href="#attendance-manual-heading" class="rlc-button rlc-button-primary">
                            <svg class="rlca-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
                            Record Attendance
                        </a>
                    @endif
                </div>
            </div>
        </header>

        @if (session('success'))
            <p class="rlca-notice" role="status">{{ session('success') }}</p>
        @endif
        <p class="rlca-notice" data-workspace-notice role="status" hidden></p>

        <div class="rlca-counts" aria-label="Selected activity check-in totals">
            @foreach (['total' => 'Total check-ins', 'manual' => 'Manual', 'test_uid' => 'Test UID', 'nfc' => 'NFC'] as $key => $label)
                <section class="rlc-panel rlca-count-card" data-count-card="{{ $key }}">
                    <div class="rlca-stat-main">
                        <span class="rlca-stat-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                @switch($key)
                                    @case('total')
                                        <rect x="4" y="3" width="16" height="18" rx="3" /><path d="m8 12 3 3 5-6" />
                                        @break
                                    @case('manual')
                                        <path d="m16 3 5 5-12 12H4v-5Zm-3 3 5 5" />
                                        @break
                                    @case('test_uid')
                                        <rect x="3" y="5" width="18" height="14" rx="3" /><path d="M7 10h4m-4 4h2m6-4h2m-2 4h2" />
                                        @break
                                    @default
                                        <path d="M5 5v14m5-14v14m0-14 8 14V5M3 8v8m18-8v8" />
                                @endswitch
                            </svg>
                        </span>
                        <div class="rlca-stat-value">
                            <p class="rlca-muted">{{ $label }}</p>
                            <strong data-attendance-count="{{ $key }}">{{ $counts[$key] ?? '—' }}</strong>
                        </div>
                    </div>
                    <p class="rlca-stat-caption">
                        {{ $activity ? match ($key) { 'total' => 'For selected activity', 'manual' => 'Beneficiary ID entries', 'test_uid' => 'Test UID submissions', default => 'Recorded NFC check-ins' } : 'Select an activity' }}
                    </p>
                </section>
            @endforeach
        </div>

        <section class="rlc-panel rlca-activity" aria-labelledby="attendance-activity-heading">
            <h2 id="attendance-activity-heading" class="sr-only">Activity and beneficiary filters</h2>
            <div class="rlca-filter-bar">
                <form method="GET" action="{{ route('attendance.index') }}" class="rlca-form" data-activity-form>
                    <div>
                        <label for="attendance-activity">Program / Activity · Date · Location</label>
                        <select id="attendance-activity" name="activity_id" required data-activity-selector>
                            <option value="">Choose an activity</option>
                            @foreach ($activities as $option)
                                <option value="{{ $option->id }}" @selected($activity && $option->id === $activity->id)>
                                    {{ $option->title }} · {{ $option->activity_date->format('M d, Y') }} · {{ $option->location ?: 'Location not specified' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="rlc-button rlc-button-primary" data-activity-confirm>Use Activity</button>
                </form>
                @if ($activity)
                    <form method="GET" action="{{ route('attendance.index') }}" class="rlca-form" data-attendance-search>
                        <input type="hidden" name="activity_id" value="{{ $activity->id }}" />
                        <div>
                            <label for="attendance-search">Search Beneficiary</label>
                            <div class="rlca-search-field">
                                <svg class="rlca-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4 4" stroke-linecap="round" /></svg>
                                <input id="attendance-search" type="search" name="search" value="{{ $search }}" maxlength="100" placeholder="Search by name or ID..." />
                            </div>
                        </div>
                        <button type="submit" class="rlc-button">Search</button>
                        <a href="{{ route('attendance.index', ['activity_id' => $activity->id]) }}" class="rlc-button" data-clear-search>Clear</a>
                    </form>
                @else
                    <div class="rlca-filter-placeholder">
                        <label for="attendance-search">Search Beneficiary</label>
                        <input id="attendance-search" type="search" placeholder="Select an activity first" disabled />
                    </div>
                @endif
            </div>
            @error('activity_id') <p class="rlca-error">{{ $message }}</p> @enderror
            @error('search') <p class="rlca-error">{{ $message }}</p> @enderror

            @if ($activity)
                <div class="rlca-selected">
                    <div class="rlca-selected-summary">
                        <div>
                            <span class="rlca-tag">Receiving check-ins</span>
                            <h3>{{ $activity->title }}</h3>
                            <p class="rlca-muted">{{ $activity->activity_date->format('M d, Y') }} · {{ $activity->location ?: 'Location not specified' }}</p>
                        </div>
                        <div class="rlca-selected-actions">
                            <details class="rlca-activity-details">
                                <summary>Activity Details</summary>
                                <p class="rlca-muted">
                                    {{ ucwords(str_replace('_', ' ', $activity->development_area)) }}
                                    @if ($activity->start_time) · Starts {{ \Carbon\Carbon::parse($activity->start_time)->format('g:i A') }} PHT @endif
                                    @if ($activity->creator) · Created by {{ $activity->creator->name }} @endif
                                </p>
                                @if ($activity->description)
                                    <p class="rlca-description">{{ $activity->description }}</p>
                                @endif
                            </details>
                            @can('update', $activity)
                                <a href="{{ route('activities.edit', $activity) }}" class="rlca-management-link">Edit Activity</a>
                            @endcan
                        </div>
                    </div>
                </div>
            @else
                <p class="rlca-empty">Choose an activity to see its records and enable check-ins.</p>
            @endif
        </section>

        <div class="rlca-checkin-grid">
            <section class="rlc-panel" aria-labelledby="attendance-uid-heading">
                <div class="rlca-section-heading">
                    <h2 id="attendance-uid-heading">UID check-in</h2>
                    <span class="rlca-tag">Test UID mode</span>
                </div>
                <p class="rlca-muted">Use a test card UID while the reader model and connection method are being confirmed.</p>
                <div class="rlca-scan-controls">
                    <button type="button" class="rlc-button rlc-button-primary" data-start-scanning @disabled(! $canRecord)>Start Scanning</button>
                    <button type="button" class="rlc-button rlc-button-secondary" data-stop-scanning disabled>Stop Scanning</button>
                    <label class="rlca-sound-toggle"><input type="checkbox" data-scan-mute /> Mute scan sounds</label>
                </div>
                <p class="rlca-scanner-status" data-scanner-status role="status" aria-live="off">
                    {{ $canRecord ? 'Scanning stopped. Start scanning to submit a Test UID.' : ($activity ? 'You do not have permission to record attendance.' : 'Select and confirm an activity to enable scanning.') }}
                </p>
                <span class="sr-only" data-scan-announcement role="status" aria-live="polite" aria-atomic="true"></span>
                <span class="sr-only" data-scan-error-announcement role="alert" aria-live="assertive" aria-atomic="true"></span>
                @if ($canRecord)
                    <form method="POST" action="{{ route('activities.attendance.nfc', $activity) }}" data-uid-form>
                        @csrf
                        <fieldset class="rlca-form" data-uid-fields disabled>
                            <div>
                                <label for="attendance-test-uid">Test UID</label>
                                <input id="attendance-test-uid" name="nfc_uid" type="password" value="{{ old('nfc_uid') }}" maxlength="64" required autocomplete="new-password" spellcheck="false" placeholder="Card identifier is hidden" />
                            </div>
                            <button type="submit" class="rlc-button rlc-button-primary" data-uid-submit>Submit Test UID</button>
                        </fieldset>
                    </form>
                    @error('nfc_uid') <p class="rlca-error">{{ $message }}</p> @enderror
                    <div class="rlca-retry" data-retry-panel hidden>
                        <p>The current submission and following submissions are retained.</p>
                        <button type="button" class="rlc-button rlc-button-secondary" data-retry-scan>Retry Current Submission</button>
                    </div>
                    <p class="rlca-help">Stopping pauses new UID inputs. Already queued submissions will finish in order.</p>
                @endif
            </section>
            <section class="rlc-panel" aria-labelledby="attendance-manual-heading">
                <h2 id="attendance-manual-heading" tabindex="-1">Manual check-in</h2>
                <p class="rlca-muted">Record attendance using the beneficiary ID.</p>
                @if ($canRecord)
                    <form method="POST" action="{{ route('activities.attendance.store', $activity) }}" class="rlca-form" data-manual-form>
                        @csrf
                        <div>
                            <label for="attendance-beneficiary-id">Beneficiary ID</label>
                            <input id="attendance-beneficiary-id" name="beneficiary_id" value="{{ old('beneficiary_id') }}" maxlength="50" required autocomplete="off" placeholder="Beneficiary ID" />
                        </div>
                        <button type="submit" class="rlc-button rlc-button-primary" data-manual-submit>Check In</button>
                    </form>
                    @error('beneficiary_id') <p class="rlca-error">{{ $message }}</p> @enderror
                    <p class="rlca-help">Find an ID in the <a href="{{ route('beneficiaries.index') }}">Beneficiary Directory</a>.</p>
                @else
                    <p class="rlca-empty">{{ $activity ? 'Attendance is read only for your account.' : 'Select an activity to enable manual check-in.' }}</p>
                @endif
            </section>
        </div>

        <section class="rlc-panel rlca-records" aria-labelledby="attendance-records-heading">
            <div class="rlca-section-heading">
                <div class="rlca-records-title">
                    <span class="rlca-section-icon" aria-hidden="true">
                        <svg class="rlca-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3" /><path d="M7 3v4m10-4v4M3 11h18" /></svg>
                    </span>
                    <div>
                        <h2 id="attendance-records-heading">Attendance records</h2>
                        <p class="rlca-muted">Recorded check-ins for the selected activity. Times in PHT.</p>
                    </div>
                </div>
                @if ($activity)
                    <span class="rlca-activity-date">{{ $activity->activity_date->format('M d, Y') }}</span>
                @endif
            </div>
            <div data-attendance-records>
                @include('activities.partials.attendance-records')
            </div>
            <button type="button" class="rlc-button rlc-button-secondary" data-refresh-records hidden>Refresh Records</button>
            @if ($activity)
                <p class="rlca-help">CSV export includes all matching records across all pages. Times are in PHT.</p>
            @endif
        </section>

        <aside class="rlca-profile-card" data-profile-card hidden aria-label="Beneficiary check-in result">
            <header class="rlca-card-header" data-card-header tabindex="0" aria-label="Drag to move the card, or use the arrow keys">
                <div>
                    <small>Beneficiary check-in · Drag to move</small>
                    <strong data-card-title>Attendance</strong>
                </div>
                <button type="button" data-card-toggle aria-expanded="true">Collapse</button>
                <button type="button" data-card-close aria-label="Close check-in card">Close</button>
            </header>
            <div class="rlca-card-result">
                <span class="rlca-feedback-icon" data-card-icon aria-hidden="true"></span>
                <p data-card-result></p>
            </div>
            <div class="rlca-card-body" data-card-body>
                <div class="rlca-profile-person">
                    <div class="rlca-profile-photo">
                        <span data-profile-initials>?</span>
                        <img data-profile-photo alt="Beneficiary profile photo" hidden />
                    </div>
                    <div>
                        <h3 data-profile-name>Awaiting beneficiary</h3>
                    </div>
                </div>
                <dl>
                    <dt>Selected activity</dt>
                    <dd data-profile-activity>{{ $activity?->title ?? 'No activity selected' }}</dd>
                    <dd class="rlca-muted" data-profile-activity-meta>{{ $activity ? $activity->activity_date->format('M d, Y').' · '.($activity->location ?: 'Location not specified') : '—' }}</dd>
                    <dt data-profile-time-label>Check-in time (PHT)</dt>
                    <dd data-profile-time>—</dd>
                </dl>
            </div>
        </aside>
    </div>
</x-layouts::app>
