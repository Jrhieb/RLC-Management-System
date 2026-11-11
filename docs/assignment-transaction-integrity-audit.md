# Beneficiary assignment transaction-integrity audit

Date: 2026-10-18 (Asia/Manila). Scope: existing row/bulk assignment, reassignment, unassignment, optional assignment during beneficiary creation, and their stored count-only notifications.

**No application defect was confirmed. Production application code is unchanged.** Additional tests exercise missing failure/retry, connection-identity, transaction-boundary approval, stale-owner and forged-actor cases. Expected synthetic failures are test inputs, not findings against the implementation.

No live database access, including schema reads, was performed. Schema observations refer to checked-in migrations and the isolated schema only.

## Isolation and unchanged baseline

Source, relevant existing tests, policies, models, migrations and notification code were read before editing. The existing `tests/TestCase.php` / `tests/Support/TestIsolation.php` guards were reused unchanged:

- Before kernel bootstrap or migration/database access, require testing environment, SQLite driver and `:memory:` database, array mail/session/cache and isolated sync queue. Reject unsafe database URLs, read/write overrides, disabled foreign keys and environment/bootstrap-path overrides.
- A database URL is optional; Laravel's separate driver/database configuration is accepted. Any supplied URL must still resolve to SQLite `:memory:` without query/fragment overrides.
- Each application uses an owned temporary bootstrap directory. Existing selected config/routes/events/services/packages caches fail the guard rather than being loaded. LAN bootstrap caches are neither loaded nor cleared. Effective configuration is rechecked after loading configuration, before providers, and before migrations.
- Mail and framework notifications are faked before providers finish booting. Writable app/log/view/font/PDF storage stays inside per-test temporary storage and is removed during teardown. No external delivery/network calls were made.
- **`AccountNotification` is a custom Eloquent model**, not a Laravel notification channel. Its `account_notifications` inserts run normally in the isolated database despite `Notification::fake()`. These writes were not mocked or replaced.
- The existing `BeneficiaryAssignmentNotificationTest` intentionally migrates the guarded memory database per test and purges its PDO at teardown, without an outer `RefreshDatabase` transaction. This exercises actual controller commits/rollbacks. The other baseline feature files use the existing guarded migration fixtures.

Verified existing runtime: PHP 8.4.25 with `registry.ini`. Baseline command, before edits:

```powershell
$taskRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
& (Join-Path $taskRuntime 'php.exe') -c (Join-Path $taskRuntime 'registry.ini') -d memory_limit=512M vendor/bin/phpunit --do-not-cache-result tests/Feature/BeneficiaryCaseworkerAssignmentTest.php tests/Feature/BeneficiaryAssignmentNotificationTest.php tests/Feature/BeneficiaryCreateCaseworkerTest.php tests/Feature/BeneficiaryCreateNfcTest.php tests/Unit/TestIsolationTest.php
```

**Baseline: 199 tests / 1,476 assertions, passed** (40.5 seconds). The completed access audit was not rebuilt or rerun as a separate audit; its existing tests remain part of the required final full suite.

## Implementation map

Both write routes belong to the `auth`, `verified`, `EnsureStaffAccess` group (`routes/web.php:54`) and retain web `EnsureAccountApproval` middleware. `BeneficiaryPolicy::assignCaseworker` (`app/Policies/BeneficiaryPolicy.php:46`) permits Directors and excludes archived records. `create` also requires staff eligibility and Director role. Submitted role/actor fields do not replace the authenticated user used by these gates.

| Write path | Authorization and validation | Save boundary and connection | Assignment and notification writes | Existing response |
| --- | --- | --- | --- | --- |
| Row assign/reassign/unassign: `POST /beneficiaries/assign-caseworker` | `BeneficiaryCaseworkerAssignmentController::__invoke:18`; class gate before validation; every selected record gated before any save. `beneficiary_ids`: required array, 1–100; each required integer/distinct/min:1. Destination must be present, nullable integer/min:1. Literal `unassign` normalizes to null. | `DB::transaction` at line 32, default connection, default one attempt. Inside it, resolve `User::eligibleCaseworkers()` with `lockForUpdate`, then non-archived beneficiaries by requested primary IDs, sorted by ID with `lockForUpdate`. Count mismatch rejects the entire selection. | Compare persisted/current `caseworker_id` with destination; skip unchanged rows. Associate/dissociate and require successful save; accumulate old-owner removals and new-owner additions only for saved changes. Call `assignmentsChanged` before commit (line 79). | Success 302 to Registry with string `search`, `status`, `caseworker`, `page` query values retained, `registry_selection_complete=true`, existing success text. Validation uses `assignCaseworker` error bag (form 302 / JSON 422). Gate failures 403; guest login redirect / JSON 401. Unexpected storage exceptions propagate, rolling back the transaction. |
| Bulk assign/reassign/unassign | Same endpoint, rules, per-record gates and error bag as row action; not a separate implementation. Missing/archived/malformed/duplicate/mixed-invalid selections fail as a whole. | Same single transaction; no update occurs before all selected models have been loaded, count-checked and authorized. | Same loop, one aggregated notice per affected current Caseworker recipient; not one notice per selected row. Unselected rows are untouched. | Same redirect/filter/selection-complete behavior. Success text counts **selected records**, including unchanged selections; notification counts reflect **actual changes**. This distinction is existing behavior and was preserved. |
| Beneficiary creation: `POST /beneficiaries` | `BeneficiaryController::store:119`: create gate, assignment gate when field present; existing profile/photo/NFC rules; nullable integer/min:1 destination with eligible-account check. Omitted/null/empty optional destination stays Unassigned. Creation does not normalize literal `unassign`; its existing form uses an empty optional value. | Photo stored privately before transaction. `DB::transaction` at line 178, up to three concurrency attempts, default connection. Destination eligibility is queried again inside the transaction with `lockForUpdate`; optional NFC availability also rechecked. | Instantiate beneficiary from validated profile fields; set status active; associate eligible destination explicitly (assignment is not mass assignable); set optional NFC; require save success. Then store one addition notice inside the transaction. Catch failures, delete uploaded photo, preserve existing NFC-conflict validation behavior. | Success 302 to new profile with existing message. Invalid destination/profile/NFC 302 errors / JSON 422 before save, or after transactional recheck with rollback/photo cleanup. Unauthorized requests denied before upload/save. Unexpected failures propagate after cleanup. |
| Database FK null-on-delete | `beneficiaries.caseworker_id` is a nullable FK to users, `nullOnDelete`, declared in `2026_10_07_000001_add_caseworker_id_to_beneficiaries_table.php:12`. | Executed by database FK semantics if an account is deleted. This is not an additional assignment route or notification operation. | Beneficiary/card/history remain; recipient/account notification FKs cascade on user deletion. Existing behavior was not changed. | Existing fixture tests verify nullable assignment and record preservation. No live account was deleted. |

Ordinary beneficiary update does not accept `caseworker_id`; it is excluded from the model's fillable fields. Archive/restore retains assignment and NFC and is not an assignment-notification path. Activity permissions/data remain outside these writes and unchanged.

### Eligible destinations and transactional notifications

`User::scopeEligibleCaseworkers` (`app/Models/User.php:82`) requires role `caseworker`, non-null verified email, and `approval_status=approved`. That same scope is used for both destination lookups; creation checks twice to close the prevalidation/upload gap. Both paths query eligible destinations inside their save transaction. Null destination means unassigned, without silent assignment to the Director or any Caseworker.

`AccountNotificationService::assignmentsChanged` (`app/Services/AccountNotificationService.php:18`):

1. Return immediately for no changes; otherwise require an active default database transaction.
2. Resolve affected **current Caseworker-role** recipient IDs, sorted by ID. Previous Caseworkers who are pending/rejected/unverified still receive a stored removal notice; eligibility controls their ability to read it. Deleted recipients or recipients no longer having Caseworker role are not selected. This is the existing role-based recipient behavior, not a newly imposed restriction.
3. Skip zero/zero counts. `firstOrCreate` by recipient and server-generated operation event key; store recipient/account ownership, assignment kind and addition/removal counts. A cancelled insert with `exists=false` throws and fails the enclosing transaction.
4. No assignment mail, queue job or after-commit external notification is created. Existing account-decision email code is a separate method and remains unchanged.

The operation UUID is generated once outside each transaction closure (`BeneficiaryCaseworkerAssignmentController.php:31`, `BeneficiaryController.php:176`). The existing unique `(recipient_id,event_key)` constraint and stable operation key protect repeated calls for the same operation without changing counts/read state. Unchanged HTTP resubmissions generate no change map, save, or notice; later actual changes use a new operation.

`User`, `Beneficiary`, and `AccountNotification` declare no alternate model connection. The controllers' `DB::transaction` and notification service use the same default connection. New tests additionally compare the **actual PDO object** used by beneficiary and notice model events and require a nonzero transaction level on that connection; both paths pass.

### Notification data and schema

`AccountNotification` (`app/Models/AccountNotification.php:23`) casts owner/count fields to integers and `read_at` to immutable datetime. Assignment title/message are generated from kind/counts; the destination is relative `/my-caseload` (`destination:63`). The read/list/count endpoints use `forRecipient` and authentication, not request-supplied recipient IDs. No beneficiary ID/name/photo/address/history is stored or inserted into the notice.

Migrations inspected:

- `2026_10_07_000001_add_caseworker_id_to_beneficiaries_table.php`: nullable user FK, null-on-delete; assignment represented by `beneficiaries.caseworker_id`, integer model cast. Existing beneficiary soft deletes use `deleted_at`; inactive non-archived records may still be assigned under the existing rules.
- `2026_10_09_000002_create_account_notifications_table.php`: UUID primary key, recipient/account FKs cascading on user deletion, kind, event key, nullable read time, timestamps; unique `(recipient_id,event_key)` and unread-list index `(recipient_id,read_at,created_at)`.
- `2026_10_10_000001_add_assignment_counts_to_account_notifications_table.php`: unsigned-small-integer counts default zero. The 100-record selection limit fits those count fields; no column or constraint change is needed.
- Existing beneficiary/NFC unique indexes and soft-delete migration were retained. NFC ownership includes archived records under existing validation/uniqueness rules.

No missing lock/index was treated as a correctness finding. No migration was prepared/applied; a new pre-flight query/rollback is **Not applicable**.

## Coverage map

Aliases: **A** = `tests/Feature/BeneficiaryCaseworkerAssignmentTest.php`; **N** = `tests/Feature/BeneficiaryAssignmentNotificationTest.php`; **C** = `tests/Feature/BeneficiaryCreateCaseworkerTest.php`; **F** = `tests/Feature/BeneficiaryCreateNfcTest.php`. Names below identify exact methods in those files. Existing checks were reused rather than copied.

| Required behavior | Existing evidence | Added or extended evidence | Verdict |
| --- | --- | --- | --- |
| Director-only, verified staff and normal guest behavior | A `test_backend_rejects_guests_other_roles_and_unverified_directors`, `test_guest_web_requests_require_login_and_unverified_directors_require_verification`; C `test_denied_creation_and_nfc_checks_do_not_write_records_files_or_notifications` | N `test_forged_actor_fields_cannot_authorize_a_caseworker_or_choose_notice_counts`: forged Director identity still denied; authenticated Director still drives the write despite contrary actor metadata | **OK** |
| Role/verification/approval eligibility, absent users and unassignment representation | A `test_only_existing_verified_caseworkers_are_eligible`, `test_destination_is_rechecked_when_an_account_becomes_ineligible_after_the_registry_opens`, `test_director_can_explicitly_unassign_one_or_multiple_records`; N `test_ineligible_receiving_caseworkers_cannot_be_assigned_or_notified` | N `test_destination_approval_is_rechecked_at_the_transaction_boundary_on_both_paths`: pending/rejected at boundary, both paths, four cases | **OK** |
| Selection 1–100, missing destination, duplicates/malformed IDs and mixed invalid records | A `test_invalid_bulk_fields_are_rejected_without_partial_changes`, `test_omitting_the_destination_does_not_implicitly_unassign_records`, `test_duplicate_beneficiary_ids_are_rejected_even_with_different_scalar_representations`, `test_missing_or_archived_records_reject_the_whole_selection`, `test_every_selected_record_is_authorized_before_any_save`; N `test_the_existing_hundred_record_limit_is_preserved_and_a_full_bulk_creates_one_notice` | Existing tests retained; no stricter validation rule introduced | **OK** |
| Bulk update failure restores earlier writes | A `test_a_cancelled_second_save_rolls_back_the_first_assignment_and_returns_feedback`, `test_an_exception_on_the_second_save_rolls_back_all_assignments`; N `test_a_failed_beneficiary_save_rolls_back_all_assignments_and_leaves_no_notifications` | Last N test now clears the injected failure, retries successfully, checks addition count 2 and unchanged repeat with one notice | **OK** |
| Notification failure restores assignment and earlier notices | N `test_failure_on_the_second_notification_rolls_back_the_first_notice_and_assignment` (exception/cancel); `test_outer_transaction_rollback_discards_assignments_and_notifications_on_both_paths` | Existing two failure cases now retry successfully without duplicates. New N `test_sql_notice_failure_rolls_back_mixed_bulk_changes_and_retry_commits_once_on_the_same_connection` fails a real SQL insert after two earlier notices were inserted, then verifies no orphans and a correct retry | **OK** |
| Same transactional connection | N transaction-level assertions in row/bulk and creation tests; service refuses out-of-transaction writes | New bulk/creation SQL-failure tests assert identical PDO objects and active connection transactions for both assignment and notice events | **OK** |
| Addition/removal recipients, multiple owners, unassigned and unchanged rows | N `test_row_and_bulk_assignment_notify_only_the_receiving_caseworker`, `test_row_and_bulk_reassignment_notify_previous_and_receiving_caseworkers`, `test_mixed_bulk_changes_are_aggregated_per_recipient_and_exclude_unchanged_records`, `test_row_and_bulk_unassignment_notify_removals_only_and_repeated_requests_are_noops` | Mixed SQL rollback/retry fixture independently expects old owner A `(0,2)`, old owner B `(0,1)`, destination `(4,0)`; unchanged destination row and unselected row do not inflate counts | **OK** |
| Idempotency/read state | N `test_unchanged_assignments_neither_save_records_nor_create_notifications`, `test_later_actual_changes_create_new_operations_but_repeated_submissions_preserve_read_state`, `test_operation_retries_reuse_the_unique_event_and_preserve_the_original_counts_and_read_state` | New SQL retry tests and extended failure cases check no duplicate notices; repeated unchanged mixed bulk preserves the full notice snapshot | **OK** |
| Privacy and authenticated My Caseload links | N `test_creation_assignment_notifies_once_without_storing_beneficiary_details_and_duplicate_creation_does_not_notify`, `test_notification_links_open_the_authenticated_recipients_caseload_even_after_removal_and_archival`, `test_assignment_notification_list_count_read_and_read_all_are_owned_and_legacy_account_notices_still_work` | Retained; custom database writes stay real, while mail/framework delivery remains fake | **OK** |
| Creation optional assignment / no partial creation on invalid destination | C `test_director_registration_remains_optional_without_an_assignment_or_card`, `test_invalid_or_ineligible_assignments_are_rejected_before_creation`; N `test_unassigned_director_creation_and_denied_caseworker_creation_do_not_notify` | Boundary revocation tests also assert no new beneficiary/notice/photo and original record unchanged | **OK** |
| Creation failure, NFC and photo cleanup | C `test_director_saves_assignment_card_and_private_photo_in_one_transaction`, `test_eligibility_is_rechecked_inside_the_transaction_and_failed_private_photo_is_removed`, `test_nfc_database_conflict_rolls_back_assignment_and_registration_and_retains_selection`, `test_nfc_claim_between_validation_and_saving_rejects_the_entire_new_registration`, `test_failure_after_insert_rolls_back_beneficiary_assignment_card_and_private_photo`; F cancellation/uniqueness/rollback tests | N `test_creation_sql_notice_failure_cleans_photo_and_card_and_retry_creates_one_assigned_beneficiary`: inserted beneficiary/card and uploaded photo removed after SQL notice failure; same external ID/NFC retry saves once with one file/notice | **OK** |
| Original access after rollback, new access after committed transfer | Existing `AssignedBeneficiaryAccessTest::test_reassignment_and_unassignment_revoke_all_prior_access_on_subsequent_requests` retained; N authenticated My Caseload test retained | SQL mixed-bulk test checks original owner 200/new owner 403 after rollback, then old owner 403/new owner 200 after successful retry; creation retry checks new owner's profile access | **OK** |
| Fresh owner counts / filter retention | A normal assignment redirect retains query context; notifications derive owners from loaded models | N `test_intervening_owner_change_is_read_fresh_and_removal_counts_ignore_stale_client_fields`: current owner receives removal, stale owner none, destination addition; all four Registry filter keys retained | **OK** |
| Archive/restore and user deletion FK behavior | A `test_archive_and_restore_keep_assignment_and_card_and_archived_records_cannot_be_reassigned`, `test_deleting_a_caseworker_nulls_assignment_without_deleting_beneficiaries_or_cards`, `test_database_foreign_key_rejects_nonexistent_assignment` | No additional workflow or business rule was added | **OK** |
| File-backed multi-process concurrency / live browser/schema | Not exercised under this brief's isolation restrictions | See limits below; missing locks/indexes alone are not findings | **Unverifiable here** |

### Failure injection and independent expectations

The added database failures use a **temporary SQLite trigger only in the guarded memory database**, removed in `finally`. It raises an ordinary SQL write error for a chosen synthetic recipient. No production hook, notification fake, external recipient or migration is involved.

The bulk fixture contains two records owned by A, one by B, one unassigned, one already at the destination, and one unselected record retained by A. The destination has the highest recipient ID, so two real removal notices are inserted before the trigger rejects its addition notice. Eloquent `created` events confirm those earlier inserts occurred. Rollback restores the full original beneficiary snapshot and leaves zero notices. Retry stores exactly three notices with independently calculated counts: A removed 2; B removed 1; destination added 4. The unchanged/unselected rows retain their established behavior.

The creation fixture reaches the beneficiary `created` event with the optional NFC before SQL notification failure. Rollback leaves no beneficiary/notice and no photo. Retrying the same beneficiary identifier/NFC with a fresh synthetic upload succeeds, proving the failed attempt did not reserve them or leave an orphan file. Both new SQL tests assert zero queue jobs and no outgoing mail/framework notifications.

## Stale reads and concurrency limits

- Both controllers resolve destinations at the transaction boundary using the current eligible scope. The creation path also validates before upload; its second lookup prevents a stale initial pass from authorizing a now pending/rejected/unverified/role-changed/deleted destination.
- Both the eligibility and stale-owner tests use `beforeStartingTransaction` to make one deterministic **single-connection interleaving**. Approval or ownership changes before the transaction starts, then the HTTP request must read the changed state. They do not simulate simultaneous processes or prove lock behavior.
- Beneficiaries are loaded afresh from the submitted primary IDs, not reconstructed from submitted previous-owner fields. The stale-owner test changes owner A to B before transaction start, then expects only B's removal and the destination's addition. It preserves `search`, `status`, `caseworker` and `page` redirect filters.
- Destination and selected-row queries use `lockForUpdate`, with beneficiary locks ordered by ID. Installed `vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/SQLiteGrammar.php:31` compiles SQLite row locks to an empty string. Checked-in SQLite configuration uses `DEFERRED` transactions (`config/database.php`); actual deployed pragmas/connection configuration were not inspected. These facts are a verification limit, **not proof of an assignment/count defect**.
- True concurrent writers, file-backed SQLite busy/locking behavior, process crashes and other database engines were not reproduced. The bulk controller uses one transaction attempt; creation permits three framework concurrency attempts. The stable operation ID/transaction rollback design was inspected, but no automatic-deadlock-retry or multi-process guarantee is claimed from memory tests.
- No new business-rule ambiguity needed resolution. Existing semantics were preserved, including current-role recipient selection, explicit Unassign, optional creation assignment, selected-count success messages, historical records and activity visibility.

## Changes and verification

Application files changed: **none**. Models, controllers, services, policies, routes and migrations are unchanged.

Files changed for this task:

1. `tests/Feature/BeneficiaryAssignmentNotificationTest.php`: eight additional dataset cases (two SQL-failure/retry cases, four approval-boundary cases, one stale-owner case, one forged-actor case); extend three existing failure cases with valid retries/no-duplicate assertions. Reuses its existing fixtures, helper methods and real-commit test setup.
2. `docs/assignment-transaction-integrity-audit.md`: this implementation map, coverage and limits.

| Check | Result |
| --- | --- |
| Relevant unchanged baseline, five files | **199 tests / 1,476 assertions, passed** |
| Expanded assignment-notification suite | **36 tests / 701 assertions, passed** (11.6 seconds) |
| Full isolated PHP suite, run once after focused checks | **798 tests / 10,143 assertions, all passed** (190.4 seconds) |
| `vendor/bin/pint --test tests/Feature/BeneficiaryAssignmentNotificationTest.php` | **Passed** |
| Remaining temporary directories after suite | **0** for each of `rlcp-tests-*`, `rlcp-cache-guard-*`, and `rlcp-isolation-*` |

Final full-suite command:

```powershell
$taskRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
& (Join-Path $taskRuntime 'php.exe') -c (Join-Path $taskRuntime 'registry.ini') -d memory_limit=512M vendor/bin/phpunit --do-not-cache-result
```

## Remaining gaps and unchanged boundaries

Live database/schema and real browser flows were not inspected. No live sign-in, assignment, registration, notification read or download was performed. Single-connection SQLite memory checks do not prove multi-process behavior on deployed file-backed SQLite. Prior broad access tests were preserved, not redesigned; new HTTP access checks are limited to assignment rollback/commit integration.

No migration was prepared or applied. New pre-flight/rollback instructions are Not applicable. Temporary test-only SQL triggers were removed; test storage is cleaned by the existing harness.

Real accounts, assignments, beneficiaries, stored files and jobs were untouched. No real email or notification was sent. UI/templates/assets, SMTP, APP_URL, other configuration, dependencies, permissions, eligible-recipient rules, selection limits, business rules and response formats were unchanged.
