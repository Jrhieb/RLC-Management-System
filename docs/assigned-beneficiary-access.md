Assigned beneficiary access is enforced as of October 13, 2026. Verified Directors retain organization-wide beneficiary access, administrative actions, and historical reporting. Verified, approved Caseworkers receive only currently assigned, non-archived beneficiaries. Both active and inactive assigned profiles remain readable; existing active-only assistance/check-in rules still apply. Account approval, verification, guest restrictions, account notifications, and organization-wide activity permissions retain their existing behavior.

The existing `BeneficiaryPolicy::view` now checks current ownership; `update` delegates to the same check. Existing profile, edit/update, assistance, protected-photo, and NFC registration/unlink authorization therefore share the restriction. `Beneficiary::visibleTo($user)` fails closed for guests and ineligible staff and explicitly excludes archived records for Caseworkers, including queries through historical `withTrashed` relations. The existing grouped search, alphabetical, and attendance search scopes are reused. Related attendance and assistance visibility scopes preserve Director history and filter Caseworker rows through the current beneficiary assignment.

Visibility is applied in SQL before search results, pagination, counts, feed limits, CSV streaming, and the PDF size limit. Assignment comes from the authenticated account and current beneficiary records. Registry owner parameters cannot widen a Caseworker's scope; the owner selector is Director-only. Caseworker caseload cards contain their own account/count and omit the Unassigned card. The existing folder SVGs, colors, layout, and styling are preserved. Search and dashboard projections include `caseworker_id` internally so per-record policies still authorize their protected links and photos; search JSON retains its existing minimal fields.

| Surface | Enforcement |
| --- | --- |
| Registry, My Caseload, filters, fragments, pagination, suggestion JSON | Beneficiary visibility scope before grouped search, ordering, pagination, or suggestion limits. Registry account/count queries are restricted for Caseworkers. |
| Dashboard preview and polling | Authenticated assignment relationship plus visibility scope; live counts and protected profile/photo links. Director dashboard queries remain organization-wide. |
| Notification activity feed | Attendance/assistance scopes before each feed limit; ordinary program activity events keep existing activity permissions. Separate account notifications and count-only assignment notices are unchanged. |
| Profiles, edits, updates, assistance history/create/store, photos | Existing server-side `view`/`update` gates use the tightened beneficiary policy. Archive photos keep the existing Director gate. |
| NFC registration and unlink | Existing beneficiary update gate, including the fresh locked record on registration. Card uniqueness checks reveal no beneficiary names, IDs, photos, or history. |
| NFC lookup and manual/NFC attendance submission | Current visibility scope before lookup; attendance gates and current beneficiary authorization before inserts or duplicate responses. Inaccessible records get the generic unavailable response. |
| Attendance workspace, JSON, search, source totals, activity CSV | Attendance visibility scope on rows and all count queries; organization activity selectors/management remain available under existing activity permissions. |
| Reports, totals, CSV, PDF | Attendance/assistance visibility scope before counts, pagination, streaming, and PDF limits. Directors retain archived history. |

Reassignment and unassignment take effect on subsequent requests. Old owners lose profile/edit/photo/assistance/NFC access and membership in lists, feeds, counts, and exports. No ownership list is cached. A new owner can read existing history; the records, original recorder, and timestamps remain intact. Assignment/reassignment, archive/restore, bulk administration, and account review remain Director-only.

Beneficiary creation is now Director-only through the existing `BeneficiaryPolicy::create`. Approved Caseworkers visiting the create URL are redirected to their Registry with "Only Directors can add beneficiaries." Unauthorized POST requests return 403 before validation, upload, or persistence. Optional Director assignment and assigned-record Caseworker access are preserved. See [the focused creation change and verification steps](director-beneficiary-creation.md).

Changed application files:

| File | Change |
| --- | --- |
| `app/Models/Beneficiary.php` | Shared staff/ownership/archive visibility scope and accurate scope/relationship type metadata. |
| `app/Models/ActivityAttendance.php` | Current-assignment visibility for historical attendance and scope/relationship type metadata. |
| `app/Models/AssistanceTransaction.php` | Current-assignment visibility for assistance and scope/relationship type metadata. |
| `app/Models/ProgramActivity.php` | Relationship type metadata used by attendance scopes. Runtime behavior unchanged. |
| `app/Models/User.php` | Eligible-caseworker scope and assignment relationship type metadata. Runtime behavior unchanged. |
| `app/Policies/BeneficiaryPolicy.php` | Assigned-only Caseworker view/update authorization. Creation and Director administrative permissions preserved. |
| `app/Services/CaseworkerCaseload.php` | Shared visibility for live personal counts/preview; include ownership in the protected-link projection. |
| `app/Http/Controllers/BeneficiaryController.php` | Scope Registry rows/accounts/counts and ignore forged Caseworker owner filters. |
| `app/Http/Controllers/BeneficiarySearchController.php` | Scope suggestions before limits and reuse grouped search; include ownership for policy checks. |
| `app/Http/Controllers/MyCaseloadController.php` | Reuse shared visibility alongside the authenticated assignment relationship. |
| `app/Http/Controllers/DashboardNotificationController.php` | Scope beneficiary attendance/assistance events before feed limits. |
| `app/Http/Controllers/NfcLookupController.php` | Scope card lookup before returning a profile URL. |
| `app/Http/Controllers/ActivityAttendanceController.php` | Scope and authorize manual beneficiary lookup before check-in or duplicate confirmation. |
| `app/Http/Controllers/NfcAttendanceController.php` | Scope and authorize locked card lookup before check-in or duplicate confirmation. |
| `app/Http/Controllers/ProgramActivityController.php` | Scope attendance rows and every source/total count. |
| `app/Http/Controllers/ActivityAttendanceExportController.php` | Scope activity CSV rows before search/streaming. |
| `app/Http/Controllers/ReportController.php` | Scope report rows, pagination, and all participation/release totals. |
| `app/Http/Controllers/ReportExportController.php` | Scope both CSV report types before streaming. |
| `app/Http/Controllers/ReportPdfExportController.php` | Scope both PDF report types before record limits and rendering. |
| `resources/views/beneficiaries/index.blade.php` | Restrict the owner filter to Director assignment permission. |
| `resources/views/beneficiaries/partials/caseworker-caseloads.blade.php` | Restrict Unassigned folder rendering to Director assignment permission. Folder artwork retained. |

Changed validation/documentation files:

| File | Change |
| --- | --- |
| `tests/Feature/AssignedBeneficiaryAccessTest.php` | New end-to-end access matrix, real CSV/PDF downloads, synthetic photo/NFC/history fixtures, reassignment, counts, empty states, restricted accounts, creation behavior, and optional browser fixture rendering. |
| `tests/Feature/AttendanceWorkspaceTest.php` | Owned Caseworker fixtures; keep archived-history coverage under Director access and duplicate-history preservation after assignment. |
| `tests/Feature/BeneficiaryNfcRegistrationTest.php` | NFC behavior fixtures explicitly assigned to the authenticated synthetic Caseworker. |
| `tests/Feature/BeneficiaryRegistryTest.php` | Owned synthetic fixtures preserve existing Registry UI/sort/pagination/fragment checks. |
| `tests/Feature/BeneficiarySearchTest.php` | Retain organization-wide owner/status filter coverage for Directors; assigned-only suggestions covered by the new suite. |
| `tests/Feature/BeneficiaryCaseworkerAssignmentTest.php` | Caseworkers see their own profiles and retain no assignment controls. |
| `tests/Feature/BeneficiaryCreateCaseworkerTest.php` | Director registration with optional assignment; deny Caseworker creation and retain assigned-profile access. |
| `tests/Feature/BeneficiaryCaseloadsTest.php` | Own folder/count only; forged owner filter cannot open another caseload. |
| `tests/Feature/CaseworkerCaseloadTest.php` | Replace the former broader-access expectation with denied foreign Registry/profile/edit/photo/search access. |
| `tests/browser/assigned-access.test.mjs` | Installed-browser checks of isolated synthetic HTML, preserved folders, absent hidden records, scoped counts, empty states, themes, and viewport containment. |
| `docs/assigned-beneficiary-access.md` | Implementation, checks, creation decision, changed files, and verification links. |
| `docs/caseworker-dashboard.md` | Link the earlier dashboard handover to the current assigned-only access rules. |

Checks completed on October 13, 2026:

| Check | Result |
| --- | --- |
| New access suite | 25 tests passed, 2,412 assertions. Covers a Director, two approved Caseworkers, a zero-assignment Caseworker, pending/rejected/unverified accounts, an applicant role, and guests. |
| Full PHPUnit regression | 580 tests passed, 7,150 assertions. Includes account approval/review, account notifications, count-only assignment notices, creation, archive/restore, NFC, attendance, and existing UI behavior. |
| Existing JavaScript suites | 110 tests passed. |
| Browser checks | 28 passed: Registry, personal/Director folders, empty Registry, personal Dashboard, attendance, and reports, each at 375/1,440 pixels in light/dark themes. |
| Visual review | Reviewed Director folders, the personal folder, and the mobile Caseworker Registry screenshots. Folder artwork/layout retained. |
| Pint | All 28 changed PHP files pass formatting checks. |
| PHPStan | Completes with a 512 MB limit and reports 56 remaining typing findings in existing controller/service/model code. The new visibility scopes and scoped relationship calls have no findings. See `storage/framework/testing/assigned-access-phpstan.json`. This repository-wide check is not green. |

The shared `tests/TestCase.php` guard runs before database refresh/migration hooks and requires testing mode, SQLite `:memory:` without a database URL, isolated array mail/session/cache, and sync queues. Mail and Laravel notifications are faked. All fixtures and mutation checks use synthetic memory records. Existing cached configuration/routes are bypassed with process-local cache paths; no cache/configuration files are cleared. The installed PHP 8.4 runtime and existing Node/Edge/dependencies were reused. No application database records, migration files, dependencies, real emails, public application workflows, or CSS/JavaScript production assets were changed.

The browser renderer required execution outside the managed command sandbox. Its temporary profile stays under the workspace test-artifact directory; the test checks its resolved path before removal. It strips scripts and blocks HTTP/HTTPS resources. These checks review test-rendered HTML; authenticated requests and writes were exercised through Laravel feature tests. Physical NFC reader hardware and real staff browser sessions were not exercised.

Reproduce database checks in this workspace without changing application configuration:

```powershell
$accessRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
$env:APP_CONFIG_CACHE = Join-Path $env:TEMP 'rlcp-access-no-config.php'
$env:APP_ROUTES_CACHE = Join-Path $env:TEMP 'rlcp-access-no-routes.php'
& (Join-Path $accessRuntime 'php.exe') -c (Join-Path $accessRuntime 'registry.ini') vendor/bin/phpunit --no-progress
# The shared test bootstrap refuses an unsafe database/mail/cache configuration.
```

To regenerate synthetic browser fixtures, set `$env:ASSIGNED_ACCESS_UI_FIXTURES = '1'` and run `tests/Feature/AssignedBeneficiaryAccessTest.php` with the same guarded command. Set `$env:ASSIGNED_ACCESS_UI_BROWSER = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'`, then run `node --test tests/browser/assigned-access.test.mjs`. Browser artifacts are under `storage/framework/testing/assigned-access-ui/`.

Read-only browser verification links use the existing application URL. Sign in as the relevant verified staff role to review them:

- [Registry](http://localhost:8000/beneficiaries)
- [Caseload folders](http://localhost:8000/beneficiaries?tab=caseloads)
- [My Caseload](http://localhost:8000/my-caseload)
- [Dashboard](http://localhost:8000/dashboard)
- [Attendance](http://localhost:8000/attendance)
- [Reports](http://localhost:8000/reports?date_from=2026-10-01&date_to=2026-10-13)
- [NFC lookup](http://localhost:8000/nfc)

Synthetic screenshot examples:

- [Caseworker folder, desktop dark](../storage/framework/testing/assigned-access-ui/caseworker-caseloads-dark-1440.png)
- [Director folders, desktop light](../storage/framework/testing/assigned-access-ui/director-caseloads-light-1440.png)
- [Caseworker Registry, mobile light](../storage/framework/testing/assigned-access-ui/caseworker-registry-light-375.png)
