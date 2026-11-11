# Backend attendance integrity audit — 2026-10-17

This audit used source inspection and synthetic SQLite `:memory:` tests only.
No live database connection, LAN form submission, email, or NFC device was used.

## Implementation map (before changes)

| Path | Authorization and reference resolution | Write and duplicate behavior |
| --- | --- | --- |
| `POST /activities/{activity}/attendance` → `ActivityAttendanceController::store` | Staff middleware; activity `recordAttendance` policy; external beneficiary ID resolved through `visibleTo(authenticated user)` and `status=active`; beneficiary `view` policy; transaction and beneficiary row lock. | Query-builder `insertOrIgnore`, followed by retrieval of the activity/beneficiary pair. Fixed `source=manual`, authenticated actor, one `now('UTC')` for all timestamps. New JSON response 201; duplicate 200; form requests redirect with the existing success messages. |
| `POST /activities/{activity}/attendance/nfc` → `NfcAttendanceController::store` | Same middleware/activity policy; normalized card lookup through current beneficiary visibility; explicit inactive/archive rejection; beneficiary policy; transaction and beneficiary row lock. | Eloquent `firstOrCreate` on the same pair. Fixed `source=nfc_test`; recorder is the scanning **authenticated staff member**, not the beneficiary or an anonymous device. Check-in uses `now('UTC')`; model timestamps use the UTC application timezone. Same JSON/form duplicate semantics as above. |
| `POST /nfc/lookup` → `NfcLookupController::lookup` | Current visibility, active/non-archived beneficiary, then beneficiary policy. | Redirects to the authorized profile; does not write attendance. Included because registered-card format compatibility and privacy affect attendance. |
| Other application paths | Searches of current routes, controllers, models, services, jobs and seeders found no additional attendance bulk/import/sync/admin writer, update, or delete endpoint. `ProgramActivity::attendances()` is an ordinary relationship. | `ActivityAttendance` has no custom save/update hooks. Its fillable fields are used by the NFC writer; manual writes use the query builder. User deletion can set historical `recorded_by` to NULL through the existing foreign key. |

Organization-wide activity lists and activity permissions are intentional and unchanged.
The beneficiary, rather than the activity creator, determines Caseworker attendance eligibility.

### Schema declared by the existing migration

`database/migrations/2026_09_29_063658_create_activity_attendances_table.php`:

| Column | Declaration | Nullable/default and constraints |
| --- | --- | --- |
| `id` | big integer primary key | Auto-generated, required. |
| `program_activity_id` | foreign ID | Required; references `program_activities.id`; restrict deletion. |
| `beneficiary_id` | foreign ID | Required; references `beneficiaries.id`; restrict deletion. |
| `checked_in_at` | timestamp | Required; supplied by the server. |
| `source` | string, length 20 | Required; default `manual`; no new enum/state introduced. |
| `recorded_by` | foreign ID | Nullable; references `users.id`; NULL on user deletion. |
| `created_at`, `updated_at` | Laravel timestamps | Nullable schema columns; both current writers populate them. |

The declared database unique index is **`activity_attendance_unique_beneficiary`
on (`program_activity_id`, `beneficiary_id`)**. It is not merely an application
check. Primary-key/unique indexes and foreign keys were inspected in source;
the existing migrations were applied only to memory during tests. The deployed
schema was deliberately not inspected.

### Assignment, archival and time

- Current assignment is `beneficiaries.caseworker_id` (nullable foreign ID,
  integer model cast). `Beneficiary::visibleTo()` checks the current authenticated
  user's ID, staff eligibility and `deleted_at IS NULL`. Directors retain their
  existing privileged access. No historical assignment grants a new check-in.
- Archival uses `deleted_at`/SoftDeletes; inactivity is `status=inactive`.
  Both write paths reject either state. NFC ownership is a nullable `nfc_uid`
  on the beneficiary; unlinking clears that field. There is no separate tag
  activation state to invent.
- Storage convention is naive UTC; application timezone is `UTC`.
  `ActivityAttendance::casts()` casts check-in time to datetime; default model
  serialization emits UTC ISO-8601. API cards, attendance rows, profile history,
  reports, CSV and PDF explicitly convert to `Asia/Manila`.
- Report index/CSV/PDF derive a Manila midnight start and exclusive next-day
  midnight end, then convert both to UTC. `activity_date` is a separate calendar
  date, not an attendance timestamp or a new recording window.
- Request `source`, `recorded_by`, role/owner fields, timestamps and body activity
  IDs never enter the write values. The route activity and resolved beneficiary
  determine the pair. Existing `nfc` history/counts remain supported; the current
  UID endpoint deliberately records `nfc_test`.

## Existing tests inspected and baseline coverage

Fixtures are inline synthetic beneficiaries/activities/attendance records and
`User::factory()` users. The default test user is a verified approved Caseworker;
restricted-account and Director fixtures override those fields explicitly.
There is no production attendance fixture import.

| Existing test file | Relevant coverage |
| --- | --- |
| `AttendanceWorkspaceTest.php` | Activity selection/management, source counts, search/pagination, CSV/history/PHT, UID normalization, repeated NFC, both cross-path orders, invalid/unknown UIDs, inactive/archive rejection, authentication/verification/role/policy/CSRF, protected photo response and non-JavaScript redirects. |
| `AssignedBeneficiaryAccessTest.php` | Own/other/unassigned/archived fixtures, restricted accounts and guests, both attendance submissions, NFC lookup/link/unlink, current-assignment revocation, counts/search, organization-wide activities, CSV and real PDF visibility. |
| `AccountApprovalAccessTest.php` | Pending/rejected/unverified staff restrictions and middleware/route protection. |
| `BeneficiaryNfcRegistrationTest.php`, `BeneficiaryCreateNfcTest.php` | Accepted string card formats including decimal leading zeros, normalization, optional card registration, archived ownership, replacement/conflict handling and isolated race simulations. |
| `BeneficiaryArchiveRestoreTest.php`, `DashboardTest.php` | Historical records survive archival; dashboard and attendance trend/read behavior. |
| `tests/js/attendance-workspace.test.mjs` | Existing client queue, duplicate handling and permission-related controls; frontend implementation unchanged. |

Abbreviations in the baseline matrix:

- **W** = `AttendanceWorkspaceTest`; **A** = `AssignedBeneficiaryAccessTest`.
- Method names below are exact; all begin with `test_`.

| Checklist item | Manual baseline | NFC baseline |
| --- | --- | --- |
| Authorization/current assignment | **Covered** — A:`nfc_registration_unlink_lookup_and_both_attendance_submissions_require_current_assignment`, `reassignment_and_unassignment_revoke_all_prior_access_on_subsequent_requests`, `existing_account_and_guest_restrictions_cover_internal_reads_writes_and_downloads`; W:`auth_verified_and_role_permissions_apply_to_workspace_and_writes`. | **Covered** — same tests; W:`record_attendance_permission_is_checked_for_the_selected_activity`. |
| Active/non-archived/privacy | **Covered** — W:`inactive_and_archived_beneficiaries_cannot_use_either_check_in`; A current-assignment test. | **Covered** — same tests; W:`unknown_uid_is_rejected_without_profile_data`. |
| Valid references/tag compatibility | **Partially covered** — valid/hidden/inactive references tested; malformed manual input and malformed route IDs missing. | **Partially covered** — W:`invalid_uids_do_not_create_attendance`, `unknown_uid_is_rejected_without_profile_data`; registered decimal cards were incorrectly classified as invalid; malformed activity IDs and cleared-card check-in missing. |
| Database uniqueness/FK enforcement | **Not covered directly** — sequential duplicate responses only. | **Not covered directly** — sequential duplicate responses only. |
| Repeat/cross-path/race handling | **Partially covered** — W:`manual_and_uid_requests_share_duplicate_protection`; full manual/manual preservation and losing-insert simulation missing. | **Partially covered** — W:`duplicate_uid_requests_return_confirmation_without_replacing_the_original_record`, `manual_and_uid_requests_share_duplicate_protection`; losing-insert simulation missing. True concurrency unverifiable here. |
| UTC storage/PHT display | **Partially covered** — W:`records_and_csv_share_search_scope_pagination_history_and_pht` tests 00:30 UTC → 08:30 PHT; raw generated UTC and midnight/year boundary missing. | **Partially covered** — same read test; generated raw UTC and boundary coverage missing. |
| Source/recorder/client spoofing | **Partially covered** — valid source/actor writes exercised, forged audit payload not explicit. | **Partially covered** — W:`test_uids_normalize_and_retain_the_test_source` rejects source spoofing; forged actor/timestamps/body activity ID missing. |

Baseline before any repository edits:

- Exact requested URL probe: one existing test errored before migration because
  old guards rejected every nonempty SQLite URL. No database connection made.
- Unchanged full PHP suite with its legacy `DB_URL=''`, `DB_DATABASE=:memory:`,
  array/fake mail and an external temporary storage root: **608 passed,
  7,765 assertions**. The first full attempt hit the runtime's 128 MB PDF memory
  limit; the completed baseline used the same PHP 8.4.25/registry.ini with a
  process-only `memory_limit=512M` override.

## Confirmed findings and changes

### AT-01 — Medium — NFC lookup/check-in reject registered decimal cards — fixed

Evidence: `NfcCardUid::rules()` and registration/create tests accept 10-digit
identifiers, including `0027947780` and `0000000000`; the two consuming controllers
independently allowed only 8/14/20 hexadecimal characters. Four new regression
cases registered the cards successfully, then failed with 422 at lookup/check-in.

Fix: extract format-only rules in the existing `NfcCardUid` helper and reuse its
normalization/format/error message in both consumers. Registration keeps its
separate ownership-uniqueness rule. No permission, ownership, source or duplicate
response changed. The stale invalid-UID test now uses an actually invalid
nine-digit string.

Regression: `AttendanceWorkspaceTest::test_registered_decimal_cards_work_for_lookup_and_attendance`
(leading-zero/all-zero cards, both endpoints).

### AT-02 — Medium — Both paths accept malformed activity IDs by numeric coercion — fixed

Evidence: with only synthetic activity ID 1, POST route IDs `1e0` and `1.0`
resolved to it and returned 201 on both attendance paths. Four failing regression
cases demonstrated attendance creation against that coerced reference.

Fix: `ProgramActivity::resolveRouteBinding()` rejects non-digit primary-key route
values before querying. Existing digit-only IDs, policies and organization-wide
activity visibility are preserved. Putting this on the model also protects the
existing cached routes without changing or rebuilding their cache.

Regression: `AttendanceIntegrityTest::test_invalid_activity_route_references_return_404_without_writes`
includes unknown, malformed, zero, null-like, missing, scientific-notation and
fractional route values for both paths.

### Test-safety requirement — exact URL and temporary storage guards

`Tests\Support\TestIsolation` validates the exact raw URL
`sqlite:///:memory:`, the configured driver/database, and Laravel's parsed
connection configuration. It rejects query-string overrides and persistent
databases without connecting. Shared `TestCase` checks before application boot
and again before any migration hook, retains array delivery plus fake mail and
notifications, and keeps sessions/cache/queue isolated.

Every feature test gets its own temporary storage directory for disks, compiled
views, session/cache paths, logs, PDF fonts and PDF scratch files; teardown removes
it. Live config/routes caches are bypassed through temporary cache paths rather
than cleared. Storage guard tests reject parent traversal, outside/missing paths
and similarly named sibling directories. PHP fake upload files use temporary
file handles rather than beneficiary storage. Nine existing feature-specific
guards only changed their URL condition to match this required exact URL.

## Final checklist verdicts and focused additions

| Item | Expected / observed on both paths | Verdict |
| --- | --- | --- |
| Authentication/authorization/current assignment | Guests: JSON 401, forms login redirect. Ineligible staff: 403. Hidden/unassigned/former-owner beneficiaries: generic 422 without profile data. Directors retain eligible-record access. Client role/owner/actor fields do not grant access. | **OK**, existing authorization tests plus guest form and audit-spoof checks. |
| Active/non-archived | No attendance inserted for inactive/archived targets; scoped NFC lookups reveal no hidden profile. | **OK**, existing tests retained. |
| References/cards | Invalid input: 422; nonexistent/malformed activity route: 404; real pair resolved server-side. Linked decimal cards now accepted. Unknown or unlinked card cannot resolve/check in; clearing a card does not disable manual attendance. | **AT-01/AT-02 fixed**; other checks **OK**. |
| Database uniqueness/references | Actual unique index discovered and duplicate insert raises `UniqueConstraintViolationException`; missing activity/beneficiary FK insert fails. | **OK in memory**, deployed schema not inspected. |
| Repeats/conflict handling | Manual/manual preserves the full original row; existing NFC repeat and both cross-path tests preserve duplicate semantics; same beneficiary can attend distinct activities. Simulated winning insert returns duplicate 200 and keeps original source/actor/time. | **OK for tested sequential/simulated paths**; true concurrency **unverifiable**. |
| Timestamps | Frozen 2026-12-31 16:30 UTC stored unchanged in all three audit timestamps; model serialization remains UTC; displayed Jan 1 00:30 PHT. Manila report range includes exact start and final second, excludes one second before start and exact next-day start. | **OK** for JSON, workspace/profile, activity CSV, report HTML/CSV and HTML passed to the real PDF renderer. |
| Source/recorder | `manual` or `nfc_test` and the authenticated scanning/recording staff user persist despite forged source/actor/owner/role/timestamps/body activity IDs. | **OK**, no new source meanings introduced. |

New focused cases:

- `AttendanceIntegrityTest.php`: manual repeat preservation, two activities,
  invalid manual references and activity routes, actual unique/FK failures,
  simulated losing insert on each path, unlinked-card behavior and guest forms.
- `AttendanceTimestampIntegrityTest.php`: both paths, UTC/PHT year rollover,
  complete Manila day boundaries, spoof-resistant audit fields and real PDF output.
- `AttendanceWorkspaceTest.php`: four registered-decimal-card regressions.
- `TestIsolationTest.php`: nine guard checks; no database connections.

Final verification:

- **657 PHP tests passed, 8,084 assertions** (49 additional cases), using verified
  PHP 8.4.25/registry.ini with `memory_limit=512M` and the exact memory URL.
- Relevant post-fix authorization/attendance/guard group: **104 passed,
  3,123 assertions**. The UTC/PHT group passed all four cases.
- Existing attendance/NFC JavaScript checks: **32 passed**; no frontend edits.
- Pint passed for every changed PHP file.
- Two actual runner probes rejected a non-memory URL and the normal application
  storage directory **before application boot**, both with expected exit code 2
  and zero assertions/migrations. These are expected safety rejections, separate
  from the passing regression suite.
- Per-test temporary directories were removed; the two temporary baseline
  directories were also cleaned up after verification.

## Files changed

Application changes (only AT-01 and AT-02):

- `app/Support/NfcCardUid.php` — shared format-only validation (AT-01).
- `app/Http/Controllers/NfcAttendanceController.php` — consume existing helper (AT-01).
- `app/Http/Controllers/NfcLookupController.php` — consume existing helper (AT-01).
- `app/Models/ProgramActivity.php` — validate primary-key route syntax (AT-02).

Tests and audit evidence:

- `phpunit.xml`, `tests/TestCase.php`, `tests/Support/TestIsolation.php`,
  `tests/Unit/TestIsolationTest.php` — required exact URL and storage safety.
- `tests/Feature/AttendanceIntegrityTest.php`,
  `tests/Feature/AttendanceTimestampIntegrityTest.php` — meaningful matrix gaps.
- `tests/Feature/AttendanceWorkspaceTest.php` — AT-01 regressions, stale invalid
  data correction, exact URL guard condition.
- Only the exact URL condition changed in these existing guard files:
  `BeneficiaryArchiveRestoreTest.php`, `BeneficiaryCaseloadsTest.php`,
  `BeneficiaryCaseworkerAssignmentTest.php`, `BeneficiaryCreateNfcTest.php`,
  `BeneficiaryNfcRegistrationTest.php`, `BeneficiaryCreateCaseworkerTest.php`,
  `BeneficiaryRegistryTest.php`, `UserApprovalFoundationTest.php`,
  `BeneficiarySearchTest.php` (all under `tests/Feature`).
- `docs/attendance-integrity-audit.md` — map, baseline matrix and evidence.

## Limits and deliberately unchanged behavior

- **No real concurrency proof:** one-connection SQLite cannot reproduce separate
  writers, row locking/isolation, deadlocks or production-engine behavior. The
  tests prove the unique constraint fires and simulate the losing-insert paths.
  NFC uses installed Laravel `firstOrCreate`/`createOrFirst`, which catches unique
  conflicts and retrieves the winning pair; manual uses `insertOrIgnore`.
- Manual `insertOrIgnore` can suppress more than duplicate errors on some database
  engines. Inspection found no reachable invalid insert from the current
  validated/server-generated values. No speculative refactor was made.
- The live schema and hardware/browser flow were not exercised. Separate
  database-engine concurrency testing would require a disposable non-live
  database, outside this brief's SQLite-only constraint.
- No migration was needed, prepared or applied. The existing unique constraint
  already covers the pair, so there is no new pre-flight query or rollback.
- UI/templates/assets, SMTP, `APP_URL`, dependencies, accounts, permissions and
  real business records were untouched. No emails/jobs were created or sent.
  No new status, role, time window or business rule was added. Historical records,
  current-assignment rules and organization-wide activity visibility remain intact.

Repeat the PHP suite with the verified runtime (no config-cache clear or build):

```powershell
$taskRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
& (Join-Path $taskRuntime 'php.exe') -c (Join-Path $taskRuntime 'registry.ini') -d memory_limit=512M vendor/bin/phpunit --do-not-cache-result
```

Do not point this test command at a real database. The new guard rejects an
inherited DB URL other than the exact memory URL instead of silently replacing it.
