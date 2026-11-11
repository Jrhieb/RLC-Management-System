<div class="rlcr-caseloads">
    <p class="rlcr-caseloads-description">Open a caseload to view its beneficiaries. Counts include active and inactive beneficiaries and exclude archived records.</p>
    @if ($registryCaseworkers->isEmpty())
        <div class="rlcr-caseloads-empty" role="status">
            <h3>No caseworker accounts yet</h3>
            <p>Beneficiaries without a caseworker are listed in Unassigned.</p>
        </div>
    @endif
    <ul class="rlcr-caseload-grid" aria-label="Caseworker caseloads">
        @foreach ($registryCaseworkers as $account)
            <li>
                <a href="{{ route('beneficiaries.index', ['caseworker' => $account->id]) }}" class="rlcr-caseload-card" data-caseload="{{ $account->id }}"
                   @if ($caseworkerFilter === (string) $account->id) aria-current="page" @endif>
                    <svg class="rlcr-folder-art" viewBox="0 0 300 200" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        <defs>
                            <linearGradient id="rlcr-folder-back-{{ $account->id }}" x1="0" y1="0" x2="0" y2="1">
                                <stop class="rlcr-folder-back-start" offset="0" />
                                <stop class="rlcr-folder-back-end" offset="1" />
                            </linearGradient>
                            <linearGradient id="rlcr-folder-front-{{ $account->id }}" x1="0" y1="0" x2="1" y2="1">
                                <stop class="rlcr-folder-front-start" offset="0" />
                                <stop class="rlcr-folder-front-end" offset="1" />
                            </linearGradient>
                        </defs>
                        <path class="rlcr-folder-back" fill="url(#rlcr-folder-back-{{ $account->id }})" d="M12 32V18Q12 2 28 2H104Q114 2 120 9L132 22Q136 26 142 26H270Q286 26 286 42V180Q286 196 270 196H28Q12 196 12 180Z" />
                        <path class="rlcr-folder-focus" d="M12 32V18Q12 2 28 2H104Q114 2 120 9L132 22Q136 26 142 26H270Q286 26 286 42V180Q286 196 270 196H28Q12 196 12 180Z" />
                        <path class="rlcr-folder-paper-back" d="M42 35L258 29Q271 28 272 40L274 72H34V45Q34 36 42 35Z" />
                        <path class="rlcr-folder-paper-front" d="M35 43L267 36Q279 35 279 48V78H27V53Q27 44 35 43Z" />
                        <path class="rlcr-folder-front" fill="url(#rlcr-folder-front-{{ $account->id }})" d="M17 48L282 35Q300 34 298 53L291 184Q290 200 274 200H21Q6 200 5 185L0 66Q-1 49 17 48Z" />
                        <path class="rlcr-folder-focus" d="M17 48L282 35Q300 34 298 53L291 184Q290 200 274 200H21Q6 200 5 185L0 66Q-1 49 17 48Z" />
                    </svg>
                    <div class="rlcr-caseload-person">
                        <span class="rlcr-avatar" aria-hidden="true">{{ $account->initials() }}</span>
                        <h3 title="{{ $account->name }}">{{ $account->name }}</h3>
                    </div>
                    <p class="rlcr-caseload-count"><strong>{{ number_format($account->assigned_beneficiaries_count) }}</strong> {{ $account->assigned_beneficiaries_count === 1 ? 'beneficiary' : 'beneficiaries' }}</p>
                    <span class="rlcr-caseload-open">{{ $account->assigned_beneficiaries_count ? 'Open caseload' : 'No beneficiaries assigned' }} <x-rlcp-icon name="arrow" /></span>
                </a>
            </li>
        @endforeach
        @can('assignCaseworker', \App\Models\Beneficiary::class)
        <li>
            <a href="{{ route('beneficiaries.index', ['caseworker' => 'unassigned']) }}" class="rlcr-caseload-card" data-caseload="unassigned"
               @if ($caseworkerFilter === 'unassigned') aria-current="page" @endif>
                <svg class="rlcr-folder-art" viewBox="0 0 300 200" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <defs>
                        <linearGradient id="rlcr-folder-back-unassigned" x1="0" y1="0" x2="0" y2="1">
                            <stop class="rlcr-folder-back-start" offset="0" />
                            <stop class="rlcr-folder-back-end" offset="1" />
                        </linearGradient>
                        <linearGradient id="rlcr-folder-front-unassigned" x1="0" y1="0" x2="1" y2="1">
                            <stop class="rlcr-folder-front-start" offset="0" />
                            <stop class="rlcr-folder-front-end" offset="1" />
                        </linearGradient>
                    </defs>
                    <path class="rlcr-folder-back" fill="url(#rlcr-folder-back-unassigned)" d="M12 32V18Q12 2 28 2H104Q114 2 120 9L132 22Q136 26 142 26H270Q286 26 286 42V180Q286 196 270 196H28Q12 196 12 180Z" />
                    <path class="rlcr-folder-focus" d="M12 32V18Q12 2 28 2H104Q114 2 120 9L132 22Q136 26 142 26H270Q286 26 286 42V180Q286 196 270 196H28Q12 196 12 180Z" />
                    <path class="rlcr-folder-paper-back" d="M42 35L258 29Q271 28 272 40L274 72H34V45Q34 36 42 35Z" />
                    <path class="rlcr-folder-paper-front" d="M35 43L267 36Q279 35 279 48V78H27V53Q27 44 35 43Z" />
                    <path class="rlcr-folder-front" fill="url(#rlcr-folder-front-unassigned)" d="M17 48L282 35Q300 34 298 53L291 184Q290 200 274 200H21Q6 200 5 185L0 66Q-1 49 17 48Z" />
                    <path class="rlcr-folder-focus" d="M17 48L282 35Q300 34 298 53L291 184Q290 200 274 200H21Q6 200 5 185L0 66Q-1 49 17 48Z" />
                </svg>
                <div class="rlcr-caseload-person">
                    <span class="rlcr-avatar" aria-hidden="true"><x-rlcp-icon name="users" /></span>
                    <h3>Unassigned</h3>
                </div>
                <p class="rlcr-caseload-count"><strong>{{ number_format($unassignedCount) }}</strong> {{ $unassignedCount === 1 ? 'beneficiary' : 'beneficiaries' }}</p>
                <span class="rlcr-caseload-open">{{ $unassignedCount ? 'Open caseload' : 'No unassigned beneficiaries' }} <x-rlcp-icon name="arrow" /></span>
            </a>
        </li>
        @endcan
    </ul>
</div>
