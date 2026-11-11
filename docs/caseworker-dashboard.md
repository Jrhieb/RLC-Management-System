The approved Caseworker dashboard and My Caseload view are complete. This task reuses the assignment relationship, Registry view/components, staff policies, and [account notification foundation](account-notifications.md). It adds no migration, assignment notification, email configuration, dependency, public-site change, Attendance feature, or NFC feature.

The October 13 [assigned beneficiary access change](assigned-beneficiary-access.md) now restricts Registry, search, profiles, photos, history, NFC, attendance, and exports to an eligible Caseworker's current non-archived assignments. Its access rules supersede the broader-access descriptions in this earlier dashboard handover. Director access, account notifications, and organization-wide activity permissions are preserved.

The later [beneficiary assignment notification extension](beneficiary-assignment-notifications.md) now creates count-only, in-system notices linking to each recipient's own My Caseload. It leaves this dashboard, its polling, and Registry permission rules unchanged. Assignment emails are not implemented.

The October 10 verification found the requested application implementation already present. Follow-up changes strengthen refresh regression coverage: assignment/reassignment/unassignment and archive/restore now check dashboard and polling preview membership as well as counts and list membership; status changes update the counts, preview, and personal status filters; repeated initialization aborts old requests and retains exactly one polling timer and visibility listener. The browser harness now reports startup errors and allows more time for an installed browser to start. No application permission or behavior change was needed during this verification.

Verified Directors retain the organization-wide dashboard, including active beneficiary totals, activity/attendance/assistance totals, attendance trend, today's activities, recent assistance (including archived history), and upcoming activities. Their approval field still does not limit their existing verified access. Upcoming activity markup is shared by both dashboard views and explicitly labeled **Organization-wide activities**.

Verified approved Caseworkers receive a personal dashboard at the existing `/dashboard` route. It shows their assigned non-archived beneficiary total, active/inactive breakdown, the first five assigned beneficiaries sorted by full name then record ID, and links to the complete My Caseload list and its status filters. It keeps the existing authorized upcoming activities separately from the personal summary. Its existing Dashboard search still searches the broader Registry; Registry access and individual profile/edit/photo permissions remain unchanged.

`GET /my-caseload` uses the authenticated user's `assignedBeneficiaries()` relationship. Only search, status, and pagination affect the displayed list. Client-supplied `caseworker`, `caseworker_id`, user/owner, archived, and tab parameters never choose its owner or enable another caseload. The search OR conditions remain grouped under the relationship's owner condition. Default soft-delete scopes exclude archived records from every count, preview, list, and search result.

My Caseload reuses `beneficiaries.index`, the Registry search/status form, list/grid switch, row actions, protected-photo rendering, initials fallback, status badges, live result fragments, alphabetical ordering, and ten-record pagination. Pagination/clear/first-page links remain on `/my-caseload` and carry only the personal display filters. The Caseworker selector is omitted. The broader All Beneficiaries link remains available as a separate navigation action. Beneficiary IDs stay strings, including leading zeros. An unfiltered empty personal dashboard/list shows **No assigned beneficiaries yet.** Filter misses and out-of-range pages keep their existing search/first-page recovery controls.

The new `viewMyCaseload` permission requires a verified approved Caseworker and the existing Registry permission. Both new routes use the existing authenticated, verified, approved-staff route group and global approval middleware. Pending/rejected accounts remain on account status; their JSON requests are forbidden, and fragment redirects cannot replace the Registry results. Unverified approved Caseworkers follow email verification. Directors/admins cannot use the personal routes. No assigned-only restriction was added to ordinary Registry, profiles, editing, photos, or global search.

Dashboard refresh uses `GET /dashboard/caseload`, throttled to 30 requests/minute. It returns one aggregate count query and at most five preview records, with private/no-store JSON responses and protected photo URLs. The initial summary is rendered on the server. The browser polls every 30 seconds only while visible, refreshes immediately when returning to a visible page, and offers Refresh caseload. Hidden/destroyed/replaced instances stop their timer, remove the visibility listener, abort old requests, and ignore stale responses. Manual and periodic requests cannot overlap.

Only count text and the preview can change during a refresh. The search input, caret, toolbar, activities, and page remain mounted. A focused preview link is retained; its rows catch up on the next unfocused refresh. A version derived from the preview records keeps an unchanged preview mounted, avoiding repeated protected-photo downloads and lost image-fallback state. Failures retain the prior results with accessible retry feedback. Assignment, reassignment, unassignment, status changes, archive, and restore are read from current records on each request, without a cached ownership list.

The Caseworker layout uses the existing Registry/dashboard colors, light/dark variables, protected avatars, keyboard focus indicators, search/status labels, accessible sections, and responsive cards. The preview/counters collapse at mobile widths, and My Caseload keeps the Registry's list/grid layout and scrollable table. The notification bell remains a single shared header component with its separate account-notification and Activity feed sections. No notification workflow was changed.

Changed files:

| File | Change |
| --- | --- |
| `app/Http/Controllers/DashboardController.php` | Role-specific dashboard, owned polling response, shared upcoming activity query; preserve Director statistics/history. |
| `app/Http/Controllers/MyCaseloadController.php` | Authenticated-owner list, normalized search/status, personal pagination, reused Registry fragments. |
| `app/Services/CaseworkerCaseload.php` | Live assigned counts and alphabetical five-record preview with soft-delete exclusion. |
| `app/Models/Beneficiary.php` | Shared grouped search and alphabetical ordering scopes. |
| `app/Http/Controllers/BeneficiaryController.php` | Reuse those scopes while retaining broader Registry filters and permissions. |
| `app/Policies/BeneficiaryPolicy.php` | Add the personal-view permission; other permissions remain unchanged. |
| `routes/web.php` | Two unique staff-protected personal list/polling routes. |
| `resources/views/dashboard/caseworker.blade.php` | Personal counters/preview and separately labeled organization-wide upcoming activities. |
| `resources/views/dashboard/partials/caseload-preview.blade.php` | Owned preview, exact empty state, and stable preview version. |
| `resources/views/dashboard.blade.php` | Reuse shared upcoming activity markup on the Director dashboard. |
| `resources/views/beneficiaries/index.blade.php` | Personal mode in the existing Registry view, owner-free controls/links, shared person/status components. |
| `resources/views/components/registry-beneficiary-person.blade.php` | Extract existing protected photo, fallback initials, and name/profile markup for reuse. |
| `resources/views/components/registry-beneficiary-status.blade.php` | Extract the existing status badge for reuse. |
| `resources/views/components/dashboard-upcoming-activities.blade.php` | Shared authorized upcoming activity rendering and organization-wide label. |
| `resources/views/layouts/app/sidebar.blade.php` | My Caseload navigation only for eligible Caseworkers. |
| `resources/js/caseload-dashboard.js` | Visible-page polling, unchanged-preview preservation, safe DOM updates, focus/request/timer cleanup. |
| `resources/js/beneficiary-registry.js` | Reuse live search/navigation with an omitted Caseworker selector; share filter restoration. |
| `resources/js/app.js` | Import the personal dashboard module. |
| `resources/css/caseworker-caseload.css` | Responsive personal counters/preview with existing theme variables. |
| `resources/css/app.css` | Import the personal dashboard styling. |
| `tests/Feature/CaseworkerCaseloadTest.php` | Personal/Director data, empty/filtered states, both assignment paths with preview membership checks, live status transitions, forged filters, archived exclusion, permissions, protected photos, and memory-only UI fixtures. |
| `tests/js/caseload-dashboard.test.mjs` | Polling, visibility, unchanged previews, focus/caret, concurrent requests, stale responses, errors, navigation destruction, and repeated initialization cleanup. |
| `tests/js/registry-live-search.test.mjs` | Personal search/pagination with no owner selector and zero-prefixed IDs. |
| `tests/browser/caseload-layout.test.mjs` | Optional existing-browser layout checks using isolated fixtures and built CSS, bounded startup wait, and startup diagnostics. |
| `docs/caseworker-dashboard.md` | Implementation, checks, and deployment handover. |
| `docs/account-notifications.md` | Link the notification foundation to this later dashboard task. |

The asset build regenerated `public/build/manifest.json`, `public/build/fonts-manifest.json`, and assets under `public/build/assets/`, including `app-HGMHLt2J.css` and `app-CplSqXr3.js`. Package manifests/lockfiles, assignment controllers, authentication/approval middleware, migration files, notification/mail workflows, Attendance implementations, and NFC implementations were not changed.

Dashboard/My Caseload validation on October 10, 2026, before the separate assignment-notification extension, used the existing PHP 8.4.25 runtime and installed dependencies. Current assignment-notification regression results are recorded in its [handover](beneficiary-assignment-notifications.md).

| Check | Result |
| --- | --- |
| Caseload feature coverage | 23 tests passed, included in the focused run. |
| Focused caseload/Registry/assignment/approval/authentication/legacy/notification checks | 367 passed; 3,495 assertions. |
| Previous full feature regression suite (October 9) | 525 passed; 4,390 assertions. A full suite was not rerun during the October 10 focused verification. |
| Focused JavaScript suites | 49 passed, including 14 dashboard polling tests and personal Registry navigation/selection regressions. |
| Browser layout checks | 12 passed: Dashboard and My Caseload at 375, 768, and 1,440 pixels, each in light and dark themes. Checks cover viewport containment, readable theme colors, responsive columns, personal counts, search presence, and Registry grid mode. |
| Visual inspection | Reviewed the mobile dark dashboard and mobile light My Caseload screenshots from fake test records. |
| Pint | Final formatting check passed on all 8 changed PHP files. |
| Oxfmt | Final formatting check passed on all 8 changed JavaScript/CSS/browser-test files. |
| Asset build | `npm run build` passed. The existing optional `fontaine` font-optimization warning remains; no package was installed. |

The existing shared test bootstrap requires testing environment, SQLite `:memory:` without a database URL, array mail/session/cache, and an isolated queue before database refresh hooks. Mail and Laravel notifications are faked. Tests exercise the real assignment/creation/archive/restore HTTP paths only against memory records. After the later notification extension, assignment tests expect stored notices while continuing to prohibit assignment mail; archive/restore creates no additional notice. Read-only dashboard/list/polling requests are checked for unchanged beneficiary records and no notification/mail side effects.

Browser checks use the already installed headless Edge and screenshots under `storage/framework/testing/caseload-ui`. Fixtures are exported only by an explicit test flag from the guarded memory database. Browser fixtures have scripts/external links removed and built CSS injected; no real application, authentication session, database, or mail endpoint is contacted. An isolated browser profile is removed after validation. The October 10 browser matrix passed with normal desktop execution access after sandboxed Edge startup/page commands timed out. Layout checks cover rendered styles; JavaScript tests separately exercise the production polling/navigation code. Full authenticated browser/device testing remains deployment validation.

Re-run focused checks with PHP 8.4.1 or newer and the existing mbstring, PDO SQLite, fileinfo, OpenSSL, and GD extensions enabled:

```powershell
php vendor/bin/phpunit tests/Feature/CaseworkerCaseloadTest.php tests/Feature/DashboardTest.php tests/Feature/BeneficiaryCaseloadsTest.php tests/Feature/BeneficiaryRegistryTest.php tests/Feature/BeneficiarySearchTest.php tests/Feature/BeneficiaryCaseworkerAssignmentTest.php tests/Feature/BeneficiaryCreateCaseworkerTest.php tests/Feature/AccountApprovalAccessTest.php tests/Feature/DirectorAccountReviewTest.php tests/Feature/Auth tests/Feature/UserApprovalFoundationTest.php tests/Feature/AccountNotificationTest.php --no-progress
node --test tests/js/caseload-dashboard.test.mjs tests/js/registry-live-search.test.mjs tests/js/registry-selection.test.mjs
npm run build
```

In this workspace PHP is not on the shell's PATH. The installed test runtime and its existing extension-only configuration can be selected without editing any configuration:

```powershell
$caseloadRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
& (Join-Path $caseloadRuntime 'php.exe') -c (Join-Path $caseloadRuntime 'registry.ini') vendor/bin/phpunit tests/Feature/CaseworkerCaseloadTest.php --no-progress
& (Join-Path $caseloadRuntime 'php.exe') -c (Join-Path $caseloadRuntime 'registry.ini') vendor/bin/pint --test app/Http/Controllers/DashboardController.php app/Http/Controllers/MyCaseloadController.php app/Services/CaseworkerCaseload.php app/Models/Beneficiary.php app/Http/Controllers/BeneficiaryController.php app/Policies/BeneficiaryPolicy.php routes/web.php tests/Feature/CaseworkerCaseloadTest.php
```

For the optional browser matrix, build assets and export the isolated fixtures first, then select an already installed Chromium browser. No browser package installation is required:

```powershell
$env:CASELOAD_UI_FIXTURES = '1'
php vendor/bin/phpunit tests/Feature/CaseworkerCaseloadTest.php --filter test_responsive_shared_ui --no-progress
Remove-Item Env:CASELOAD_UI_FIXTURES
$env:CASELOAD_UI_BROWSER = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
node --test tests/browser/caseload-layout.test.mjs
Remove-Item Env:CASELOAD_UI_BROWSER
```

No new migration command is needed for this task. Deployment requires the existing beneficiary assignment column/foreign key from `2026_10_07_000001_add_caseworker_id_to_beneficiaries_table.php` and the previously completed role, email-verification, approval, registration, and account-notification schemas. Apply any pending prerequisites through the normal deployment procedure described in the earlier handovers; do not reapply the legacy approval backfill or modify existing assignments to deploy this view.

Publish the built assets together with the source changes, then refresh deployment caches and restart any long-lived PHP processes:

```powershell
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

These deployment commands were not executed. No real records, migrations, emails, mail configuration, or dependencies were changed. No implementation remains within the requested dashboard/My Caseload scope. Prior notification mail-provider/queue setup remains in [account-notifications.md](account-notifications.md); this dashboard adds no delivery or queue prerequisite. Assignment notifications are implemented separately in their [handover](beneficiary-assignment-notifications.md). Assignment email, public website, Attendance, and NFC changes remain outside this dashboard task.
