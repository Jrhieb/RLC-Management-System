# Reports and export integrity audit

Audit completed against repository source, migrations, and synthetic SQLite `:memory:` fixtures. Date: 2026-10-18 (Asia/Manila). The live database, including its schema, was **not accessed**.

## Isolation and baseline

The implementation map and relevant existing tests were inspected before editing. The unchanged baseline command ran these five files:

```powershell
$taskRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
& (Join-Path $taskRuntime 'php.exe') -c (Join-Path $taskRuntime 'registry.ini') -d memory_limit=512M vendor/bin/phpunit --do-not-cache-result tests/Feature/AssignedBeneficiaryAccessTest.php tests/Feature/AttendanceWorkspaceTest.php tests/Feature/AttendanceTimestampIntegrityTest.php tests/Feature/AssistanceReleaseIntegrityTest.php tests/Unit/TestIsolationTest.php
```

Baseline: **166 passed, 3,579 assertions**. Runtime: verified PHP 8.4.25 with the existing `registry.ini`. No live Artisan/database command was used.

The existing `tests/TestCase.php:26` and `tests/Support/TestIsolation.php:10` were reused unchanged:

- Before application bootstrap or connection use, reject non-testing environment, non-array mail/session/cache, non-sync queue, non-SQLite driver, non-`:memory:` database, read/write connection overrides, disabled foreign keys, and bootstrap/environment path overrides.
- Optional database URL is parsed using Laravel's configuration parser; absent/empty URLs are valid. A URL resolving outside SQLite `:memory:`, or containing query/fragment overrides, is rejected. No literal URL is required.
- Each application selects a fresh owned temporary bootstrap directory before the kernel bootstraps. Its config/routes/events/services/packages cache paths must be absent; an existing selected cache fails the guard rather than being loaded. The LAN application's bootstrap caches are neither loaded nor cleared. `TestIsolationTest::test_existing_selected_bootstrap_caches_fail_without_being_loaded` covers all five cache names, including `config.php`.
- Effective configuration is checked after `LoadConfiguration`, before providers, and again before `RefreshDatabase`. Mail and notifications are faked before providers finish booting. All writable app/view/log/font/PDF storage is checked to be inside the per-test temporary directory and cleaned after each test.
- Both new test classes assert `Mail::assertNothingOutgoing()` and `Notification::assertNothingSent()`. Requests use Laravel's in-process test kernel. New PDF limit tests mock the renderer; existing real PDF tests use isolated storage and disable remote resources/PHP execution. No external requests or emails were sent.

## Path-by-path implementation map

All paths below are GETs in the `auth`, `verified`, `EnsureStaffAccess` route group (`routes/web.php:54`), with web middleware `EnsureAccountApproval` (`bootstrap/app.php:23`). Report paths additionally authorize both `BeneficiaryPolicy::viewAny` and `ProgramActivityPolicy::viewAny`. Activity paths authorize `ProgramActivityPolicy::view`/`viewAny`. Staff eligibility and beneficiary visibility are shared model rules, not client-supplied owner filters.

| Route / output | Controller, query construction and role scope | Accepted filters / validation / timestamp | Paging and ordering | Response, empty and invalid behavior |
| --- | --- | --- | --- | --- |
| `/reports` summary | `ReportController::index`, lines 17–68. Independent attendance/assistance builders using shared `visibleTo`; cloned for counts and `distinct()->count('beneficiary_id')`. | Nullable `date_from`, `date_to`, `date_format:Y-m-d`. Attendance: `activity_attendances.checked_in_at`. Assistance: `assistance_transactions.released_at`. | Counts over all filtered/scoped rows, before pagination. | HTML 200; empty totals are zero. Invalid dates/reversed range: form 302 with errors, JSON 422. |
| `/reports` row listings | Same builders cloned in `ReportController`, lines 71–83; eager-load beneficiary/activity/recorder. | Same normalized date bounds and scopes as summary. No report search, owner, category or beneficiary filter is accepted. | 10 per paginator; separate `attendance_page`, `assistance_page`; event timestamp DESC, `id` DESC; `withQueryString()`. | Same HTML 200. Out-of-range pages have empty rows and retain totals. Invalid page values fall back to page 1. |
| `/reports/export/attendance` | `ReportExportController::__invoke`, lines 18–68. Date construction duplicated from summary; shared attendance `visibleTo`. | Same dates; `checked_in_at >= startUTC AND < nextDayUTC`. | Before fix: `lazy(200)` offset batches. After RE-01: `ExportRows::descending`, 200 per batch, `(checked_in_at, id)` descending cursor. | Streaming CSV 200; header-only when empty; no row cap. Date errors 302/422 before streaming. Unknown type 404. |
| `/reports/export/assistance` | Same controller, lines 69–86; shared assistance `visibleTo`. | Same dates; `released_at >= startUTC AND < nextDayUTC`. | Same 200-row cursor, `(released_at, id)` DESC. | Same CSV/error contract; eight existing columns. |
| `/reports/pdf/{attendance\|assistance}` | `ReportPdfExportController::__invoke`, lines 18–98. Date/query logic duplicated; same model scopes and eager loads. | Same dates/event fields. | Timestamp DESC, `id` DESC; `limit(1001)->get()`, reject count > 1000. No pagination/truncation. | PDF attachment 200, including empty. Over limit: form 302 / JSON 422 on `date_from`, renderer not called. Unknown type 404. A4 landscape; remote/PHP disabled. |
| `/attendance`, `/activities/{activity}` rows and count cards | `ProgramActivityController::attendance`, `show`, `attendanceWorkspace`, lines 89–171; relation-scoped attendance queries plus shared `visibleTo` / `matchingBeneficiary`. Activity selector remains organization-wide. | `/attendance`: nullable `activity_id`, integer/min:1/exists; both screens: nullable string `search`, max:100, trimmed. No date range filter. Displays `checked_in_at` in PHT; activity date is separate date-only metadata. | 10 rows, `page`; `checked_in_at` DESC, `id` DESC; query string retained. Count cards intentionally omit search but retain selected activity and beneficiary scope. | HTML/JSON 200, JSON private/no-store. No activity gives null counts/list selection. Unknown selector 302/422; direct nonexistent activity 404. Invalid search 302/422. |
| `/activities/{activity}/attendance/export` | `ActivityAttendanceExportController::__invoke`, lines 13–82; same selected activity relation, `visibleTo`, `matchingBeneficiary`. | Nullable string `search`, max:100, trimmed. Same search as row list, **not** the search-independent count cards. No report date filter. | Before: `lazy(200)`. After RE-01: same shared 200-row cursor, `checked_in_at` DESC, `id` DESC. | Streaming CSV 200, header-only when empty. Invalid search 302/422; missing activity 404. Eight existing columns. |

Unauthorized JSON behavior is 401 for guests and 403 for pending, rejected, unverified or applicant accounts. Browser guests retain the login redirect; pending/rejected Caseworkers retain the account-status redirect under `EnsureAccountApproval`. Unverified users retain verification handling. No authorization response was changed.

### Shared and duplicated logic

`ActivityAttendance::scopeVisibleTo` (`app/Models/ActivityAttendance.php:46`), `AssistanceTransaction::scopeVisibleTo` (`app/Models/AssistanceTransaction.php:40`), and `Beneficiary::scopeVisibleTo` (`app/Models/Beneficiary.php:57`) determine scope consistently. Directors retain all historical event rows, including archived/unassigned beneficiaries. Eligible Caseworkers retain only currently assigned, non-archived beneficiaries; **inactive but non-archived historical rows remain readable**. Historical `beneficiary()` relationships use `withTrashed()`, with the explicit Caseworker archive condition still enforced. Neither event model uses soft deletes.

Date validation/defaults/conversion, event range predicates, and sort construction are duplicated in summary/CSV/PDF. No divergence was reproduced. The two CSV controllers also duplicate `safeCell` and CSV serialization; their established behavior matches. These were not refactored. Only the defective traversal is now shared by `app/Support/ExportRows.php:12`.

### Migrations, casts and indexes

Schema statements below describe the checked-in migrations and the schema they build in isolated tests, **not the live schema**:

| Table / migration | Relevant columns, casts and constraints |
| --- | --- |
| `activity_attendances` / `2026_09_29_063658_create_activity_attendances_table.php` | Primary `id`; restrictive FKs `program_activity_id`, `beneficiary_id`; non-null `checked_in_at` timestamp (model datetime cast); `source`; nullable `recorded_by` FK null-on-delete; audit timestamps. Unique `(program_activity_id, beneficiary_id)` named `activity_attendance_unique_beneficiary`. Activity date/record `created_at` are not the attendance event time. |
| `assistance_transactions` / `2026_09_29_083019_create_assistance_transactions_table.php` | Primary `id`; restrictive `beneficiary_id` FK; category/item; unsigned quantity (integer cast); non-null `released_at` datetime (datetime cast); nullable notes/recorder FK; audit timestamps. `2026_10_02_052643_add_submission_token_to_assistance_transactions_table.php` adds nullable globally unique `submission_token`. |
| `beneficiaries` / create + `2026_09_27_131325_add_profile_fields_to_beneficiaries_table.php` | Primary integer `id` differs from unique string `beneficiary_id`; full name, date-only `registered_at`, status. `2026_09_29_011746_add_soft_deletes_to_beneficiaries_table.php` adds `deleted_at`. `2026_10_07_000001_add_caseworker_id_to_beneficiaries_table.php` adds nullable FK `caseworker_id`, null-on-delete; integer cast in model. |
| `program_activities` / `2026_09_29_060908_create_program_activities_table.php` | Primary `id`; title; date-only `activity_date`, optional `start_time`; development area; nullable creator FK. Organization-wide activity metadata stays accessible under existing activity permissions. |

Primary/unique keys support identity, attendance duplicate prevention and submission-token uniqueness. No migration declares an event-time or composite `(event timestamp, id)` range/order index for either history table. SQLite does not acquire such an index just from these FKs. This is a **performance observation only**, with no measured live workload and no correctness-based reason for a migration. No migration was prepared or applied; pre-flight/rollback is **Not applicable**.

## Test inventory and coverage matrix

The aliases below cite exact test methods (dataset cases count separately in PHPUnit):

| Alias | File and test method |
| --- | --- |
| A1 | `tests/Feature/AssignedBeneficiaryAccessTest.php:293` — `test_reports_csv_and_real_pdf_downloads_only_include_visible_records` (Director, two Caseworkers, zero-assignment Caseworker) |
| A2 | Same file, line 335 — `test_reassignment_and_unassignment_revoke_all_prior_access_on_subsequent_requests` |
| A3 | Same file, line 382 — `test_existing_account_and_guest_restrictions_cover_internal_reads_writes_and_downloads` |
| A4 | Same file, line 274 — `test_attendance_rows_search_and_source_counts_are_scoped_while_activities_stay_organization_wide` |
| W1 | `tests/Feature/AttendanceWorkspaceTest.php:77` — `test_records_and_csv_share_search_scope_pagination_history_and_pht` |
| W2 | Same file, line 59 — `test_counts_are_real_and_independent_of_search_and_other_activities` |
| T1 | `tests/Feature/AttendanceTimestampIntegrityTest.php:69` — `test_reports_use_the_complete_manila_day_across_the_year_boundary` |
| T2 | Same file, line 38 — `test_server_audit_values_are_utc_and_pht_display_crosses_the_year_boundary` |
| S1 | `tests/Feature/AssistanceReleaseIntegrityTest.php:369` — `test_staff_release_dates_cross_utc_pht_boundary_and_audit_fields_cannot_be_spoofed` |
| C1 | `tests/Feature/ReportExportChunkingTest.php:47` — `test_csv_traverses_three_batches_with_ties_and_nonmonotonic_event_ids` |
| C2 | Same file, line 80 — `test_insert_between_csv_batches_does_not_duplicate_or_skip_existing_rows` |
| C3 | Same file, line 104 — `test_pdf_limit_is_applied_after_role_and_date_filters_and_csv_is_not_truncated` |
| R1 | `tests/Feature/ReportExportIntegrityTest.php:46` — `test_event_dates_and_distinct_totals_agree_across_reports_csv_and_pdf` |
| R2 | Same file, line 95 — `test_activity_search_has_the_same_literal_and_wildcard_behavior_in_rows_and_csv` |
| R3 | Same file, line 128 — `test_csv_bytes_headers_escaping_formula_cells_and_leading_zero_ids_keep_the_existing_contract` |
| R4 | Same file, line 193 — `test_empty_results_and_missing_bounds_use_the_same_pht_defaults_in_every_report_output` |
| R5 | Same file, line 229 — `test_invalid_report_ranges_return_existing_validation_responses_before_export` |
| R6 | Same file, line 256 — `test_malformed_activity_search_is_rejected_consistently_before_rows_or_csv` |
| R7 | Same file, line 272 — `test_unknown_invalid_and_out_of_range_pages_do_not_change_totals_or_export_scope` |
| R8 | Same file, line 308 — `test_unknown_export_types_and_activity_ids_return_existing_not_found_or_validation_responses` |

Matrix cells show **baseline → final**. C = covered, P = partial, N = not covered, NA = Not applicable. References identify meaningful evidence, not a claim to exhaust every possible input. “Rows” includes the report paginators and activity workspace where the check applies.

| Check | Summary | Rows | Report CSV | Activity CSV | PDF |
| --- | --- | --- | --- | --- | --- |
| Correct event field rather than audit creation time | P(T1,S1) → C(R1) | P(T1,S1) → C(R1) | P(T1,S1) → C(R1,C1) | C(T2,W1) | P(T1,S1) → C(R1) |
| PHT inclusive edges, single day, month/year boundary, UTC 16:30 next day | P(T1,S1) → C(R1) | P(T1,T2,S1) → C(R1) | P(T1,S1) → C(R1) | C(T2,W1) display; date filtering NA | P(T1,S1) → C(R1) |
| Filtered totals, distinct beneficiaries, inactive/archive handling | P(A1,W2) → C(R1,C1) | P(A1,A4,W2) → C(R1,C1) | P(A1) → C(R1,C1) membership/count | C(A1,A4,W2,C1) membership/count | P(A1) → C(R1,C3) membership/count |
| Director / two owners / unassigned / archived / zero assignments | C(A1) | C(A1,A4) | C(A1) | C(A1,A4) | C(A1) |
| Reassignment/unassignment revokes subsequent access | C(A2) | C(A2) | C(A2) | C(A2) | C(A2) |
| Pending/rejected/unverified/guest/applicant restrictions | C(A3) | C(A3) | C(A3) | C(A3) | C(A3) |
| Accepted filters and links retain scope; forged filters cannot widen it | P(A1) → C(R1,R4,R7) | P(A4,W1) → C(R1,R2,R7) | P(A1) → C(R1,R4,R7) | P(A4,W1) → C(R2,R7) | P(A1) → C(R1,R4,C3) |
| Quotes, wildcard %, _, Unicode, whitespace and bound SQL search text | NA (no report search) | P(W1) → C(R2) activity rows | NA (no report search) | P(W1) → C(R2) | NA (no report search) |
| Large batches, ties, deterministic ordering, inter-batch insertion | NA | P(W1) → C(C1) pagination/order | N → C(C1,C2) | N → C(C1,C2) | NA; fixed bounded materialization |
| CSV escaping/formulas/zero IDs/BOM/LF/columns/filename/headers | NA | NA | P(A1) → C(R3) | P(W1) → C(R3) | NA |
| PDF exact limit / limit+1 / filtered scoped limit / untruncated CSV | NA | NA | N → C(C3) | NA (no PDF counterpart) | N → C(C3) |
| Empty output, no hidden totals/data | C(A1) → C(R4) | C(A1) → C(R4) | C(A1) → C(R4) | C(A1) → C(R4) | C(A1) → C(R4) |
| Missing date bounds, defaults, malformed/reversed ranges | N → C(R4,R5) | N → C(R4,R5) | N → C(R4,R5) | Date bounds NA; search N → C(R6) | N → C(R4,R5) |
| Unknown/invalid/out-of-range pagination and unknown route values | N → C(R7) totals | P(W1) → C(R7,R8) | N → C(R7,R8) ignores paging | N → C(R7,R8) ignores paging | Pagination NA; types N → C(R8) |
| Quantity sums / per-day groupings | NA | NA | NA (quantity column only) | NA | NA |

Existing authorization coverage was reused; it was not copied into new test classes. New tests supply independent fixture IDs/expected counts and CSV cells, never a second invocation of the query under test.

## Confirmed finding

### RE-01 — Medium — CSV offset batches can repeat existing records — Fixed

Before the fix, report CSV and activity CSV used `$query->lazy(200)` at the former `ReportExportController.php:102` / `ActivityAttendanceExportController.php:54`. Installed Laravel implements `lazy` with an increasing offset (`vendor/laravel/framework/src/Illuminate/Database/Concerns/BuildsQueries.php:253`, especially lines 268 and 276). The event time plus ID sort was already deterministic, but inserts ahead of the next offset shifted the page.

**Failing regression first:** C2, for attendance, assistance and activity CSV, inserted one new first-sorting row after the first batch query fetched its results. Each old implementation returned **451 rows with only 450 unique existing IDs**, repeating an existing row. All three cases failed the uniqueness assertion. Static large/tied fixtures alone had passed, so ties without data changes were not falsely reported as a separate defect.

**Small fix:** `app/Support/ExportRows.php:12` clones the existing fully scoped query per 200-row batch. The next batch must be strictly below the previous `(raw event timestamp, id)` tuple: older time, or same time with smaller ID. The two controllers only replace their iteration calls (`ReportExportController.php:103`, `ActivityAttendanceExportController.php:55`). Existing date/search/role conditions, eager loading, ordering, columns and serialization remain intact. An ID-only cursor was deliberately avoided because event order and ID order can differ.

**Focused result:** the initial eight new chunk/PDF cases passed after the fix (141 assertions); the final suite also checks report pages 2 and 45. C2 now returns exactly the original 450 IDs once each in all three exports. This tests a deterministic single-connection interleaving, not production concurrency or a transaction-wide snapshot.

No other correctness defect was confirmed. No policy, validation or CSV sanitization rule was changed.

## Checklist verdicts and observed contracts

| Area | Verdict | Evidence / current behavior |
| --- | --- | --- |
| Isolation | **OK** | Existing preboot/effective config guards and 29 unit isolation cases were retained. Per-test temporary storage, fake mail/notifications, no live connection. See isolation section. |
| Event timestamps | **OK** | R1 deliberately places included records' `created_at` in 2030 and excluded records' `created_at` inside the selected day. Only `checked_in_at` / `released_at` determine report membership. Activity date is independent metadata. |
| PHT date edges | **OK** | R1 supplies literal UTC fixtures just before `15:59:59`, at previous UTC day `16:00:00`, at `16:30:00` (= PHT 00:30 next day), at inclusive end `15:59:59`, and at excluded next-day `16:00:00`. Single-day Jan 1 and Feb 1 cases cover year/month boundaries for both report types, CSV and PDF input/display. T1/T2/S1 preserve real-render coverage. |
| Missing/invalid ranges | **OK** | R4 freezes now at UTC Jan 15 16:30 = PHT Jan 16 00:30. Missing start defaults to PHT current month day 1; missing end defaults to PHT today; empty strings become null. A resulting reversed range, malformed/array/impossible date returns 302 errors or JSON 422 (R5), before any export. A future start alone can therefore produce a `date_to` error. These existing rules are preserved. |
| Totals/distinct | **OK** | R1 expects Caseworker event IDs `[103,102,101]`, 3 records / 2 beneficiaries; Director `[103,107,106,105,102,101]`, 6 / 5. Same membership in attendance and assistance summary/rows/CSV/PDF. Repeated beneficiary records count once in distinct totals. Source cards tested independently (W2, C1). |
| Quantity sums / by-day aggregation | **Not applicable** | Neither report computes a quantity sum or day grouping. Assistance quantity is an existing per-row CSV/PDF column, tested as a string in CSV. No report type/aggregate was added. |
| Access control and historical scope | **OK** | A1–A4 cover Director, two Caseworkers, empty caseload, other-owner/unassigned/archived history, restricted accounts, and reassignment then unassignment on subsequent requests. R1 additionally includes an inactive assigned beneficiary; Director includes archives, Caseworker excludes them. Client owner/beneficiary parameters never become scope overrides (R2/R7/C3). |
| Filter consistency and links | **OK, with existing distinction** | R1/R4 inspect generated report CSV/PDF links for both normalized dates. R2 checks trimmed activity search in the CSV link and exact row/CSV IDs for quotes, %, _, Unicode, whitespace and SQL-looking bound text. Report endpoints have no beneficiary search filter. Activity CSV has no date-range filter. Activity cards ignore search by existing design (W2); compare CSV count with filtered row total, not those cards. |
| CSV completeness/order | **Defect RE-01, fixed** | Batch size **200**, unchanged. C1/C2 use **450 matching records**, therefore 3 batches. Two identical-time groups contain 225 records each, crossing boundaries. Newer odd IDs and older even IDs require composite traversal. Expected order: odds 449 down to 1, then evens 450 down to 2. Three hidden records must stay absent. Header appears once, all 450 original IDs once; pages 2/45 independently checked. C3 confirms CSV also returns all 1,001 in-range rows. |
| CSV escaping/encoding/header contract | **OK** | R3 tests all three CSV paths: UTF-8 BOM, comma delimiter, double enclosure, empty escape parameter, doubled quotes, embedded commas/newlines/Unicode, LF record ending, exact existing column order, MIME type, attachment filename, private/no-store, nosniff. Source labels and activity date formats retain their existing path-specific formats. |
| Formula prefixes | **OK for existing mechanism; broader contract ambiguous** | Both `safeCell` implementations prefix an apostrophe when `=`, `+`, `-` or `@` follows optional whitespace/BOM. R3 includes legitimate leading `+`/`-` names, tab/CR followed by `=`, a BOM-prefixed formula, and already apostrophe-prefixed text. Plain tab/CR followed by non-formula text is **preserved**, not prefixed. Whether every control-prefixed cell should be neutralized is not specified by the implementation/tests; no new sanitization scheme was guessed. |
| Leading-zero IDs | **OK in CSV bytes; spreadsheet interpretation unverifiable** | R3 expects strings `00011` … `00001` unchanged after CSV round trip; C1 checks `000449`. There is no Excel-specific cell-type annotation. Preservation when opening/importing in a spreadsheet depends on the import mode and was not claimed or changed. |
| PDF bound/responses | **OK** | C3 tests both types at exactly 1,000 owned/date-filtered records (renderer receives all exact IDs, 200 PDF response), 1,001 (JSON 422 / form 302, renderer not called), and Director with additional historical rows (over limit). Hidden/out-of-date rows do not consume a Caseworker's limit. No silent truncation. Remote/PHP disabled, landscape/header assertions. No new cap. |
| Empty outputs | **OK** | R4: zero counts/empty paginators, all CSVs header-only, both PDF renderers receive empty collections and return 200. A1 also exercises actual empty PDF rendering for a zero-assignment Caseworker. |
| Malformed filters / pagination | **OK** | R5/R6 cover malformed dates, arrays, reversed ranges and >100-character search. R7 covers unknown page names, negative/non-numeric/array pages (page 1 fallback), valid out-of-range pages (empty rows, unchanged totals) and CSV ignoring paging. R8 checks 404 unknown export type/direct activity and 422 unknown activity selector. No 500 reproduced. |
| Index/migration need | **Not applicable to correctness fix** | No migration needed/prepared/applied. Event-time index absence is performance-only and was not used to justify schema changes. |
| Production DB/concurrency and PDF visual appearance | **Unverifiable in this audit** | Live DB/schema never accessed; file-backed SQLite locking/concurrent processes not reproduced. Existing real PDF render tests passed, but no visual inspection or layout/style edit was performed. |

### CSV formats preserved

- Report attendance (7): Beneficiary Name, Beneficiary ID, Activity, Activity Date, Check-in Time (PHT), Source, Recorded By.
- Report assistance (8): Beneficiary Name, Beneficiary ID, Category, Assistance, Quantity, Released At (PHT), Notes, Recorded By.
- Activity attendance (8): Activity, Activity Date, Beneficiary Name, Beneficiary ID, Status, Check-in Time (PHT), Source, Recorded By.
- Report event timestamps: `m/d/Y H:i:s`; activity CSV timestamps: `Y-m-d H:i:s`. Report activity dates: `m/d/Y`; activity CSV activity dates: `Y-m-d`. All event display conversion is explicitly Asia/Manila; activity dates are stored date-only, not UTC instants.
- Report filenames: `{type}-report-{from}-to-{to}.csv` / `.pdf`. Activity CSV: `activity-{id}-attendance-{PHT Ymd-His}.csv`. CSV Content-Type is `text/csv; charset=UTF-8`; PDF is `application/pdf`. Existing private/no-store and nosniff headers remain.

## Verification and files changed

Current-task application changes only:

1. `app/Support/ExportRows.php` — shared descending composite-cursor traversal, batch size 200.
2. `app/Http/Controllers/ReportExportController.php` — use it for attendance/assistance CSV iteration.
3. `app/Http/Controllers/ActivityAttendanceExportController.php` — use it for selected-activity CSV iteration.

Added `tests/Feature/ReportExportChunkingTest.php`, `tests/Feature/ReportExportIntegrityTest.php`, and this document. No existing tests or test isolation guards were edited in this task. Prior audit files/changes were preserved.

- Relevant unchanged baseline: **166 tests / 3,579 assertions, passed**.
- Failing regression before fix: **3 insertion-between-batch cases**, one per CSV path; static 450-row cases passed.
- Focused verification after fix: chunk/PDF **8 tests / 141 assertions**, plus contract/boundaries **31 tests / 1,022 assertions**, passed. Subsequent pagination assertions are included in the final suite.
- Final full isolated suite, run once after the fixes: **790 tests / 9,891 assertions, all passed** (179.8 seconds). This includes the 39 new dataset cases and all existing authorization, registration, attendance and assistance checks.
- Changed-file formatter: `vendor/bin/pint --test` on the three application PHP files and two added test files: **passed**.
- After the suite, remaining temporary directories for `rlcp-tests-*`, `rlcp-cache-guard-*`, and `rlcp-isolation-*`: **0 each**.

Full-suite command (no real database/config cache command):

```powershell
$taskRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
& (Join-Path $taskRuntime 'php.exe') -c (Join-Path $taskRuntime 'registry.ini') -d memory_limit=512M vendor/bin/phpunit --do-not-cache-result
```

## Remaining limits and change boundaries

- Cursor traversal removes offset shifting; it does not promise a transaction-wide snapshot while records/assignments change during an already streaming request. The deterministic interleaving test verifies no duplication of the original matching rows when a new first-sorting row arrives. Backdated insertions, in-flight assignment changes, external deletes and file-backed multi-process locking are outside that guarantee. Subsequent-request revocation is covered by A2.
- Live schema/indexes/data and real LAN browser responses were deliberately not inspected because even read-only live DB access was prohibited. Test requests are automated kernel-level verification. No live browser sign-in/export was performed.
- PDF rendering/input/headers are tested, including real existing renderer tests; appearance and spreadsheet import behavior were not visually verified. Plain control prefixes and automatic numeric conversion on CSV import remain the explicit contract ambiguities described above.
- No migration was prepared or applied; pre-flight query and rollback are Not applicable.
- Real accounts, records, assignments and stored beneficiary files were untouched. UI/Blade/PDF templates/assets, SMTP, APP_URL, dependencies, permissions/roles and business rules were unchanged. No export formats, report types, filters or columns were added. No real mail was sent.
