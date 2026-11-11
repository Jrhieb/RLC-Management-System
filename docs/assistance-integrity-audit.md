# Assistance-release integrity audit — 2026-10-17

Scope: existing RLCP Laravel assistance releases only. Source, migrations and an
isolated SQLite `:memory:` schema were inspected. **The live database was never
accessed, including read-only.** No LAN forms or real emails were submitted.

## Implementation map, recorded before editing

All internal routes are inside the existing `auth`, `verified`, and
`EnsureStaffAccess` group; web middleware also enforces account approval.

| Path | Authorization | Validation | Write / transaction | Existing response |
| --- | --- | --- | --- | --- |
| `GET /beneficiaries/{beneficiary}/assistance/create` → `AssistanceTransactionController::create` | `BeneficiaryPolicy::update`, then active-status check. Soft-deleted rows do not bind. | Route-bound beneficiary. | None. | 200 form; ineligible staff/owner/status 403; missing/archive 404; guests redirect to login. |
| `POST /beneficiaries/{beneficiary}/assistance` → `store` | Same gate and active check **before** token lookup. | Inline required UUID, allowed category, item string ≤255, integer quantity 1–100000, PHT minute-format release date, nullable notes string ≤5000. | Originally `firstOrCreate` by globally normalized token. One release row; no controller transaction, child row, stock/inventory, file, mail or notification write. | 302 to profile with `Assistance release recorded successfully.` (including requests accepting JSON). |
| Same POST, same token and validated details | Same current authorization and eligibility checks. | Same validation; compares beneficiary, category, item, quantity, release instant, notes and authenticated recorder with the existing row. | Reuses row; no update. | 302 to profile with `This release was already saved. No duplicate record was created.` |
| Same POST, changed details | Same checks, including current actor and route beneficiary. | A mismatch raises `ValidationException` for `submission_token`. | Existing row unchanged. | JSON 422; ordinary form redirects back with existing validation errors. Generic message asks for a new form; no original release details returned. |
| Edit, void, bulk, import, sync or administrative release writer | **Not applicable:** none found in current routes/controllers/models/services/jobs. | N/A | N/A | N/A |

Searches found only this controller creating releases. `AssistanceTransaction`
defines fillable attributes, casts and read relationships/scopes, with no custom
save hook or release observer. Profile history, dashboard notifications, reports,
CSV and PDF read these rows; they do not create additional release/audit rows.

The final implementation keeps the responses and comparison semantics above.
It replaces generic `firstOrCreate` conflict recovery with a token-specific lookup,
insert and unique-conflict recovery, and rejects an unsaved model (AS-01/AS-02).
**No new top-level transaction was added:** the only business write is one atomic
database insert.

## Model, schema, tokens and dates

Sources:

- `app/Models/AssistanceTransaction.php`.
- `database/migrations/2026_09_29_083019_create_assistance_transactions_table.php`.
- `database/migrations/2026_10_02_052643_add_submission_token_to_assistance_transactions_table.php`.

| Column | Declaration / constraints |
| --- | --- |
| `id` | Auto-generated big integer primary key. |
| `beneficiary_id` | Required foreign ID → `beneficiaries.id`, restrict deletion. |
| `category` | Required string length 30; existing request allowlist: education, healthcare, gifts, other. |
| `item_name` | Required string length 255. |
| `quantity` | Unsigned integer, default 1; model integer cast. Controller retains range 1–100000. |
| `released_at` | Required datetime; model datetime cast. Staff-entered minute value is interpreted in Manila and converted to UTC. |
| `notes` | Nullable text; existing request length limit 5000. |
| `recorded_by` | Nullable foreign ID → `users.id`, NULL on user deletion; current writes always use the authenticated staff ID. |
| `created_at`, `updated_at` | Laravel timestamps; server-generated in the existing UTC application timezone. |
| `submission_token` | Nullable UUID column, unique **globally**, index `assistance_transactions_submission_token_unique`. Multiple legacy NULL tokens remain allowed. |

SQLite uses its normal integer/text affinities: the migration's unsigned/UUID
declarations do not create extra unsigned-range or UUID-format CHECK constraints.
Those existing input requirements are enforced by request validation; the unique
index and foreign keys were exercised directly against the memory schema.

Forms generate `Str::uuid()` in the existing assistance form/profile dialog and
retain old input for validation recovery. Store requires `uuid` format and
lowercases it before lookup/insert. Request trimming is the existing web behavior.
Quantity is converted to integer; blank optional notes normalize to NULL. There
is no payload-based deduplication: a fresh token permits another identical release.

Current assignment is nullable `beneficiaries.caseworker_id` (integer cast).
`BeneficiaryPolicy::update` reuses current staff eligibility and checks the current
owner or Director role. `status=inactive` blocks create and store; archival is
`deleted_at`/SoftDeletes. Replays run those same checks first: inactive →403,
archived →404, former owner after reassignment/unassignment →403. This existing
replay policy was preserved, including Directors' active/non-archived requirement.

The storage convention is naive UTC. `released_at` uses validated
`Y-m-d\TH:i` in `Asia/Manila`, converted to UTC. Backdated and future entries remain
valid; there is no new time window. Model array serialization remains UTC ISO-8601.
Profile/report HTML, CSV and PDF convert explicitly to PHT. Report date filters
use a Manila midnight start and exclusive next-day midnight end, both converted
to UTC. Activity visibility and the folder-card interface are unaffected.

## Existing tests and pre-edit baseline

Existing relevant tests were read before changes:

| File | Coverage / fixtures |
| --- | --- |
| `AssignedBeneficiaryAccessTest.php` | Inline own/other/unassigned/inactive/archived beneficiaries, approved staff, Director and restricted users. Policies/current visibility, assistance create/store, reassignment/unassignment, preserved historical rows, reports and real CSV/PDF downloads. |
| `AccountApprovalAccessTest.php` | Pending/rejected/unverified and guest staff restrictions, middleware and route protection. |
| `BeneficiaryArchiveRestoreTest.php` | Assistance history survives archive/restore. |
| `BeneficiaryCaseworkerAssignmentTest.php` | Assignment changes preserve assistance rows. |
| `CaseworkerCaseloadTest.php`, `DashboardTest.php` | Scoped/history read behavior, totals, dashboard access. |

Users use the existing synthetic `User::factory()`; assistance/activity/beneficiary
fixtures are inline memory rows. No real data or production fixture import.
There was no dedicated assistance idempotency/validation/failure test suite.

**Baseline: 177 existing relevant tests passed, 4,216 assertions**, before any
repository edit. Runtime: verified PHP 8.4.25 with `registry.ini` and a process-only
512 MB memory limit. The existing pre-boot memory guard and fake delivery were
used, with per-test temporary storage. Fresh temporary service/package/event
cache paths prevented loading LAN bootstrap manifests; existing TestCase already
used fresh temporary config/route cache paths. LAN caches were not cleared/read.

### Baseline coverage matrix

Aliases: **A** = `AssignedBeneficiaryAccessTest`; **R** =
`BeneficiaryArchiveRestoreTest`; **C** = `BeneficiaryCaseworkerAssignmentTest`.
Exact method names below have the normal `test_` prefix.

| Check | Create/store baseline | Replay baseline | Other write paths |
| --- | --- | --- | --- |
| Authorization/current assignment | **Covered**: A:`profile_edit_photo_and_assistance_access`, `reassignment_and_unassignment_revoke_all_prior_access_on_subsequent_requests`, `existing_account_and_guest_restrictions_cover_internal_reads_writes_and_downloads`. | **Not covered** directly. | Not applicable. |
| Active/non-archived | **Covered**: A:`profile_edit_photo_and_assistance_access` includes inactive/archive targets. | **Not covered** directly. | Not applicable. |
| Token uniqueness and replay | **Partial**: valid UUID fixtures; no direct index/FK assertion. | **Not covered**. | Not applicable. |
| Changed payloads | Not applicable to a new token. | **Not covered**. | Not applicable. |
| Separate legitimate releases | **Partial**: multiple distinct historical fixtures, no identical-payload/new-token check. | Not applicable. | Not applicable. |
| Failed writes/rollback | **Not covered** for releases. R/C verify history retention, not failed inserts. | **Not covered**. | Not applicable. |
| Audit-field spoofing | **Not covered** directly for releases. | **Not covered** directly. | Not applicable. |
| Validation/UTC/PHT boundaries | **Partial**: ordinary valid fixture dates and read/export filters. | **Not covered**. | Not applicable. |

## Confirmed findings

### AS-01 — Medium — Cancelled insert reported as saved replay — fixed

Reproduction: `AssistanceReleaseIntegrityTest::test_cancelled_save_is_not_reported_as_a_successful_replay_and_can_be_retried`
uses the existing Eloquent `saving` event seam to cancel saving. Original code
returned a 302 duplicate-success notice with **zero release rows**. Eloquent
`create()` can return an unsaved model when a save event cancels; its attributes
still matched the replay comparison.

Fix: after lookup/insert recovery, require `$transaction->exists`; otherwise
return a clean 500 save failure. No false success, no token persisted; a valid
retry succeeds. Normal new/replay responses remain unchanged.

### AS-02 — Medium — Generic unique failure mistaken for token replay — fixed

Reproduction: `test_race_simulation_recovers_only_a_submission_token_unique_conflict`
seeds a matching winner after an empty lookup, then induces a **real SQLite
primary-key unique violation**. The test captures exception columns `['id']`.
Installed Laravel `firstOrCreate`/`createOrFirst` catches any
`UniqueConstraintViolationException` and re-queries its original attributes.
Original assistance code consequently returned duplicate success (302) for this
unrelated failure. The focused failing test had one valid token-race pass and one
unrelated-primary-key failure before the fix.

Fix: only recover when the exception's columns are exactly `['submission_token']`
or its index is `assistance_transactions_submission_token_unique`; re-read the
winning token row and apply the existing full-payload comparison. Other unique
exceptions are rethrown; general `QueryException`, FK failures, connection errors
and SQLite-busy failures are never treated as a replay. No broad QueryException
catch or text-based error guessing was introduced.

### AS-03 — Medium — Malformed beneficiary route IDs resolve by numeric coercion — fixed

Reproduction: `test_invalid_beneficiary_route_references_return_404_and_create_nothing`
demonstrated that `1e0` and `1.0` resolved to synthetic beneficiary ID 1. Create
returned 200 and store inserted a row/returned 302 instead of 404 (four failing
cases).

Fix: `Beneficiary::resolveRouteBinding()` rejects non-digit primary-key values
before querying. Existing digit-only IDs and explicit non-primary route fields
retain their behavior. This works with cached routes too; no LAN route cache
was rebuilt and no permission changed.

### AS-04 — Low — JSON boolean accepted as an integer quantity — fixed

The final wrong-type review added the `boolean quantity` dataset to
`test_invalid_release_payload_returns_validation_errors_without_writes`.
It failed with 302 instead of 422: Laravel's non-strict `integer` validator uses
`filter_var` and accepts boolean `true`, which the controller converted to quantity
1. The normal saved-row check confirmed persistence before success was returned.

Fix: require `numeric` as well as `integer`. Booleans are rejected while existing
integer form strings and integers remain valid; minimum/maximum and categories
are unchanged. The regression and all four valid category/boundary cases were
rerun. `integer:strict` was deliberately not used because it would reject normal
numeric form strings.

## Final checklist verdicts and evidence

Names in this section are methods on `AssistanceReleaseIntegrityTest` unless
another test class is named explicitly.

| Check | Verdict / evidence |
| --- | --- |
| Guest, unverified, pending/rejected, unsupported-role denial; Directors/current owners | **OK**: existing A authorization tests and AccountApprovalAccessTest; full suite retained. No broader beneficiary scope or new permission introduced. |
| Inactive/archive/reassignment/unassignment replay | **OK**: `test_replay_rechecks_current_beneficiary_eligibility`; 403/404 before token access; original release unchanged. Existing create denials remain. |
| Token privacy/other-owner release | **OK**: `test_another_owners_token_does_not_disclose_or_change_their_release`; token conflict exposes no hidden beneficiary or release details. Actor/beneficiary changes are conflicts even where the caller otherwise has valid current access. |
| Same-token replay and complete preservation | **OK**: `test_replay_normalizes_token_case_and_preserves_every_original_column`; fresh database snapshot proves original ID, fields, recorder and timestamps unchanged after time advances. |
| Changed quantity/category/item/beneficiary/notes/date/actor | **OK**: `test_changed_replay_is_generic_and_does_not_modify_the_original` (seven cases); full fresh row snapshots unchanged; `test_form_conflict_keeps_existing_validation_redirect_behavior` preserves non-JSON response. |
| Separate legitimate release | **OK**: `test_new_token_allows_an_identical_legitimate_separate_release`; distinct IDs/tokens, identical other fields. |
| Token constraint/FKs/legacy rows | **OK in isolated schema**: `test_global_token_index_and_foreign_keys_are_enforced_and_legacy_null_tokens_survive`; `PRAGMA foreign_keys=1`, actual globally conflicting insert fails, both beneficiary/recorder FKs fail on missing rows, two NULL tokens coexist and new forms still work. |
| Specific conflict recovery | **AS-02 fixed**: real token unique conflict recovers existing row; changed winning payload produces 422; primary-key conflict produces failure and leaves the winner unchanged. |
| Save cancellation/FK/busy error and retry | **AS-01 fixed; other paths OK**: cancelled save, actual FK failure and injected `QueryException` carrying SQLite error 5 produce failures, never success. No row/token remains from a failed attempt; same-token retry succeeds. Failing-response checks use standard debug-off 500 responses; LAN debug/error presentation was not exercised. |
| Connection failure | **OK by code inspection**: recovery catches only token-specific UniqueConstraintViolationException; a generic connection QueryException is not caught as replay. No real connection failure or network operation was induced. |
| Atomicity and related side effects | **OK / not applicable**: one atomic release insert; no child/inventory/file/mail/notification writes to roll back. All focused tests assert zero fake outgoing mail/notifications. No transaction added solely for the single row. |
| Token/quantity/category/item/notes/date validation | **AS-04 fixed; otherwise OK**: `test_invalid_release_payload_returns_validation_errors_without_writes` covers required/missing/null, malformed/wrong-type/overlong tokens; quantity zero/negative/fractional/oversized/boolean/type/range failures; category allowlist/type; item/notes lengths/types; calendar/format/date-type errors. All return 422 and write nothing. |
| Valid categories and existing bounds | **OK**: `test_existing_categories_quantity_bounds_and_optional_notes_remain_valid`; all four existing categories, quantities 1 and 100000, numeric integer input, 255-character item and 5000-character notes, NULL/blank notes. No quantity/category rule changed. |
| Route references and body beneficiary/audit spoofing | **AS-03 fixed / otherwise OK**: nonexistent/malformed/missing/scientific/fractional route IDs →404; body IDs/owner/role/user/audit timestamps/unsupported attributes never override route beneficiary, authenticated recorder or server audit columns. |
| Staff-entered date, UTC/PHT boundaries, backdated/future dates | **OK**: `test_staff_release_dates_cross_utc_pht_boundary_and_audit_fields_cannot_be_spoofed` stores PHT Jan 1 00:30 as previous UTC Dec 31 16:30, separately verifies server audit clock, UTC serialization, PHT profile/CSV/PDF and report day inclusion/exclusion; both backdated and future releases remain accepted. |
| Live schema / true contention | **Unverifiable under constraints**: never accessed the live database; no file-backed or multi-process concurrency experiment. |

## Isolation guard changes

The existing shared `TestCase`/`TestIsolation` were reused, not replaced with an
alternative harness. The stricter literal-URL restriction from the earlier
attendance audit was relaxed as requested: separate SQLite driver/database settings
with empty/missing URL are valid. A supplied URL must still resolve to memory;
query/fragment overrides, read/write overrides, disabled foreign keys and unsafe
raw environment values fail before application boot. The effective loaded
database and fake-delivery configuration are checked after LoadConfiguration,
**before providers boot**, and again before migration hooks.

LAN config/routes/services/packages caches exist. Tests explicitly select a fresh
temporary **bootstrap directory** for every application boot. The five selected
config/routes/events/services/packages cache paths must not exist before boot;
any existing selected cache or inherited bootstrap path override raises an error.
The LAN caches are never loaded, cleared, or overwritten. Reusing the real
bootstrap/app.php keeps current routes/providers/middleware; only writable cache
location changes within the test process. Fake mail/notifications are installed
before provider boot and retained before fixtures.

`TestIsolationTest` verifies memory configuration with absent/empty/explicit URL,
unsafe connection settings, environment overrides, storage escapes, and five
existing temporary bootstrap-cache fixtures containing code that must never load.
Actual runner probes also rejected a persistent SQLite URL and an inherited
existing `bootstrap/cache/config.php` path **before application boot** (expected
exit code 2, zero assertions/migrations). No database file was opened by probes.

Each feature test retains its own temporary writable storage and cleanup;
disks/views/logs/cache/sessions/PDF fonts/scratch files are isolated. Ten existing
feature-specific guards no longer duplicate the obsolete literal-URL requirement;
the shared guard handles URL resolution and overrides centrally.

## Race simulations and limits

The race test deliberately inserts a winning row on the same connection after
an empty SELECT returns and before the losing INSERT. It verifies actual SQLite
token/primary-key unique errors, token-specific recovery, changed winning data
conflicts, and preservation of the winning row. It is a **race simulation**, not
proof of simultaneous writers. The busy error is injected through an existing
model event seam; no database file or second writer is created.

Single-connection `:memory:` SQLite cannot faithfully demonstrate multi-process
file-backed SQLite locking, SQLITE_BUSY timing, isolation or application-request
interleavings. Those remain unverified. No packages, threads, production hooks or
new retry policy were added for testing.

## Files and checks

Application changes:

- `app/Http/Controllers/AssistanceTransactionController.php`: AS-01 saved-state
  check, AS-02 token-specific unique-conflict recovery, and AS-04 numeric quantity
  type validation. All successful and
  ordinary conflict responses/payload comparisons preserved.
- `app/Models/Beneficiary.php`: AS-03 primary-key route syntax validation.

Tests/docs:

- `tests/Feature/AssistanceReleaseIntegrityTest.php`: focused previously missing
  replay, failure, constraint, validation, audit/date and reference cases.
- `tests/TestCase.php`, `tests/Support/TestIsolation.php`,
  `tests/Unit/TestIsolationTest.php`, `phpunit.xml`: required guard refinements.
- Obsolete literal-URL clause removed only from existing guards in
  `AttendanceWorkspaceTest.php`, `BeneficiaryArchiveRestoreTest.php`,
  `BeneficiaryCaseloadsTest.php`, `BeneficiaryCaseworkerAssignmentTest.php`,
  `BeneficiaryCreateNfcTest.php`, `BeneficiaryNfcRegistrationTest.php`,
  `BeneficiaryCreateCaseworkerTest.php`, `BeneficiaryRegistryTest.php`,
  `UserApprovalFoundationTest.php`, `BeneficiarySearchTest.php` (under
  `tests/Feature`). Their actual coverage and fixtures are unchanged.
- `docs/assistance-integrity-audit.md`: all audit evidence in this document.

Verification recorded so far:

- Existing relevant baseline: **177 passed / 4,216 assertions**.
- AS-01 targeted post-fix group: **4 passed / 40 assertions**.
- AS-02 replay/constraint post-fix group: **11 passed / 122 assertions**.
- AS-03 route post-fix group: **12 passed / 60 assertions**.
- Combined release/guard group before AS-04: **102 passed / 638 assertions**.
- A full isolated PHP run before the final boolean-type regression passed
  **750 tests / 8,709 assertions**. The newly reproduced AS-04 justified a focused
  check and a final full rerun after its one-rule fix.
- AS-04 post-fix wrong-type/valid-boundary group: **5 passed / 39 assertions**.
- Pint passed for all **16 changed PHP files**, including the final AS-04 change.
- **Final full isolated PHP suite: 751 passed / 8,716 assertions**, with separate
  SQLite `:memory:` connection/database settings and empty URL. This includes
  all authorization, registration, attendance, release and guard regressions.
- Cleanup check: **zero per-test storage directories and zero cache-guard fixture
  directories remain**. The rejected persistent-database probe created no file;
  the temporary baseline bootstrap directory was also removed.

No schema defect requiring a migration was found. **No migration prepared or
applied**; a new pre-flight query/rollback is therefore not applicable. Schema
claims refer only to migrations and the isolated memory schema, never live state.

UI/Blade/assets, SMTP, APP_URL, dependencies, accounts, assignments and real files
or business records were unchanged. No real mail, notification or job was sent.
Existing roles, categories, quantity limits, states and staff release-date rules
remain unchanged. No inventory or extra workflow was introduced.

Repeat checks from the workspace using the verified runtime:

```powershell
$taskRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
& (Join-Path $taskRuntime 'php.exe') -c (Join-Path $taskRuntime 'registry.ini') -d memory_limit=512M vendor/bin/phpunit --do-not-cache-result
```

This command uses phpunit.xml's memory database and safe delivery defaults. Unsafe
inherited values fail closed. Do not use a live database or clear LAN caches to
run it.
