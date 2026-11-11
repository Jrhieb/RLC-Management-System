In-system beneficiary assignment notifications are complete. This extension reuses the stored notification table/service, recipient ownership checks, unread/read endpoints, shared bell, and visible-page polling described in [account-notifications.md](account-notifications.md). All destinations use the authenticated recipient's [My Caseload](caseworker-dashboard.md). It adds no assignment email, SMTP configuration, dashboard redesign, Attendance/NFC change, or dependency.

The existing row/bulk assignment endpoint and new-beneficiary creation transaction both call `AccountNotificationService::assignmentsChanged`. Receiving Caseworkers get added counts; previous Caseworkers get removed counts. A bulk operation produces one notification for each affected recipient, even when selected records come from several previous Caseworkers. Counts include only actual owner changes. Already assigned records and already unassigned records are not saved again; repeated requests making no new changes produce no new notification and preserve existing read timestamps.

Each request generates a server-side operation UUID before entering its assignment transaction or transaction retry closure. The service stores `beneficiary_assignment` rows using the existing unique `(recipient_id, event_key)` constraint with `assignment:<operation UUID>` as the event key. Recipient and account IDs both identify the receiving notification owner. Repeated service calls for the same operation reuse the row without overwriting counts or read state. Later actual changes use a new operation UUID and create a new notice.

Notification inserts run on the same database connection and inside the same transaction as the assignment or beneficiary creation. The service refuses nonempty writes outside a transaction. Assignment save failures, notification insert exceptions, cancelled notification saves, and outer transaction rollbacks leave no notification or partial assignment. Creation failure retains the existing uploaded-photo cleanup. The assignment method queues no mail, job, or after-commit callback; earlier registration and approval-decision notification/mail behavior remains unchanged.

The new migration adds only `added_count` and `removed_count`, unsigned small integers defaulting to zero, to `account_notifications`. It leaves the existing keys, foreign keys, ownership fields, and unread index intact. Notification content consists of these two counts plus existing notification metadata. No beneficiary record ID, beneficiary ID, name, photo/path, address, birthday, guardian detail, or other beneficiary personal detail is stored. Titles/messages are generated from the kind and counts: **Caseload updated** and, for example, **Your caseload changed: 3 added, 2 removed.**

Assignment authorization, the approved/verified receiving-Caseworker check and lock, authorization of every selected profile, missing/archived exclusion, duplicate-ID validation, and the existing 100-record limit remain in place. No assigned-only Registry, profile, editing, photo, or search restriction was added. Archive/restore and status updates are separate workflows and do not create assignment notifications.

The existing `forRecipient` scope admits assignment notices only for the authenticated, approved, verified Caseworker, with both recipient and account ownership enforced. Other recipients' read requests return 404. Previous Caseworkers who are pending, rejected, or unverified still receive stored removal notices, but those notices are excluded from list/count/read/read-all until they regain eligible staff access. They retain their existing account-decision notifications and account-status protection. Verified Directors retain their existing account-request notifications; no assignment notice is sent to the acting Director.

Every assignment destination is the relative `/my-caseload` route, without an owner filter or beneficiary profile link. The list always derives its owner from authentication, including when extra filters are forged. Removed, archived, or otherwise unavailable records therefore cannot create broken profile links. The bell uses its existing mark-read-then-visit action, read-all control, full unread count, latest-20 list, and 30-second visible-page polling. Stored notices are now labeled **Your notifications**; eligible Caseworkers see **Account and caseload updates**. The Activity feed remains a separate, unchanged authorized section. No timer, polling endpoint, CSS, or dashboard layout was added.

Changed files:

| File | Change |
| --- | --- |
| `database/migrations/2026_10_10_000001_add_assignment_counts_to_account_notifications_table.php` | Two default-zero count fields; authored only, not run against application data. |
| `app/Models/AccountNotification.php` | Assignment kind, typed counts, eligible recipient scope, safe count text, My Caseload destination. |
| `app/Services/AccountNotificationService.php` | Transaction-required, recipient-aggregated, idempotent stored notices; no assignment mail. |
| `app/Http/Controllers/BeneficiaryCaseworkerAssignmentController.php` | Skip unchanged saves, tally actual additions/removals after authorized saves, notify before commit. |
| `app/Http/Controllers/BeneficiaryController.php` | Notify the receiving Caseworker inside the existing creation transaction. |
| `resources/views/components/rlcp-notifications.blade.php` | General stored-notification labels using the existing bell and controls. |
| `resources/js/account-notifications.js` | General notification error text; existing polling/read/navigation logic reused. |
| `tests/Feature/BeneficiaryAssignmentNotificationTest.php` | Both assignment paths, row/bulk recipients, aggregation, no-ops/repeats, privacy, limits, transaction failures/rollback, ownership, and recipient caseload navigation. |
| `tests/Feature/CaseworkerCaseloadTest.php` | Expect assignment notices while continuing to prohibit assignment mail; archive/restore adds no notice. |
| `tests/Feature/AccountNotificationTest.php` | Updated stored-notification label checks; original account workflows still covered. |
| `tests/js/account-notifications.test.mjs` | Poll a new assignment notice, mark it read, and visit My Caseload with one existing timer. |
| `docs/account-notifications.md`, `docs/caseworker-dashboard.md` | Link this later extension and clarify current stored-notification behavior. |

Validation on October 10, 2026 uses the installed PHP 8.4.25 runtime and existing dependencies. All database tests use the shared bootstrap guard: testing environment, SQLite `:memory:` without a database URL, array mail/session/cache, and an isolated sync queue are required before migrations. Mail and Laravel notifications are faked. The new suite uses real commit boundaries in the guarded memory database and discards the PDO for cleanup. Synthetic storage/save failures and outer rollbacks never contact an application database or mail endpoint.

| Check | Result |
| --- | --- |
| New assignment-notification suite | 28 passed; 450 assertions. |
| Focused assignment/creation/caseload/Registry/approval/authentication/account-notification regressions | 395 passed; 3,945 assertions. |
| Focused JavaScript suites | 59 passed, including assignment notice polling/read/click navigation and existing timer cleanup. |
| Pint | Final check passed on all 8 changed PHP files. |
| Oxfmt | Final check passed on both changed JavaScript files. |
| Asset build | `npm run build` passed; the existing optional `fontaine` font-optimization warning remains. No package was installed. |

Re-run focused PHP checks with PHP 8.4.1 or newer and the existing mbstring, PDO SQLite, fileinfo, OpenSSL, and GD extensions enabled:

```powershell
php vendor/bin/phpunit tests/Feature/BeneficiaryAssignmentNotificationTest.php tests/Feature/CaseworkerCaseloadTest.php tests/Feature/DashboardTest.php tests/Feature/BeneficiaryCaseloadsTest.php tests/Feature/BeneficiaryRegistryTest.php tests/Feature/BeneficiarySearchTest.php tests/Feature/BeneficiaryCaseworkerAssignmentTest.php tests/Feature/BeneficiaryCreateCaseworkerTest.php tests/Feature/AccountApprovalAccessTest.php tests/Feature/DirectorAccountReviewTest.php tests/Feature/Auth tests/Feature/UserApprovalFoundationTest.php tests/Feature/AccountNotificationTest.php --no-progress
php vendor/bin/pint --test app/Models/AccountNotification.php app/Services/AccountNotificationService.php app/Http/Controllers/BeneficiaryCaseworkerAssignmentController.php app/Http/Controllers/BeneficiaryController.php database/migrations/2026_10_10_000001_add_assignment_counts_to_account_notifications_table.php tests/Feature/BeneficiaryAssignmentNotificationTest.php tests/Feature/CaseworkerCaseloadTest.php tests/Feature/AccountNotificationTest.php
node --test tests/js/account-notifications.test.mjs tests/js/caseload-dashboard.test.mjs tests/js/registry-live-search.test.mjs tests/js/registry-selection.test.mjs
npm run build
```

PHP is not on this workspace shell's PATH. Its existing test runtime/configuration can be selected without editing any configuration:

```powershell
$assignmentRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
& (Join-Path $assignmentRuntime 'php.exe') -c (Join-Path $assignmentRuntime 'registry.ini') vendor/bin/phpunit tests/Feature/BeneficiaryAssignmentNotificationTest.php --no-progress
```

Deployment requires the existing beneficiary assignment, role/email-verification/approval, and account-notification schemas, plus this new count-field migration. Deploy the count fields before enabling this source change; do not repeat approval backfills or alter existing assignments. The following command is a deployment prerequisite and was not executed against the application database:

```powershell
php artisan migrate --path=database/migrations/2026_10_10_000001_add_assignment_counts_to_account_notifications_table.php --force
```

Publish the source and built assets together, refresh deployment config/route/view caches, and restart any long-lived PHP processes through the normal deployment procedure. The build regenerated the manifest and `app-DE8DLbYb.js`; CSS remains `app-HGMHLt2J.css`. Package manifests and lockfiles are unchanged. Assignment notices require no SMTP provider or queue worker. Earlier account-decision email delivery prerequisites remain separately documented in [account-notifications.md](account-notifications.md).

No real migrations, records, emails, mail configuration, or dependencies were changed. Deployment and an authenticated bell-to-My-Caseload smoke check remain operational work. No assignment-notification implementation remains within this scope. Assignment email, dashboard redesign, Attendance, and NFC work remain outside this task.
