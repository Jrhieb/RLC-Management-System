Account-request and approval-decision notifications are complete. This change extends the existing [email-first registration](email-first-registration.md) and [Director account review](director-account-review.md) flows without adding another registration or review route. It adds no assignment notifications, dashboard features, SMTP configuration, Attendance/NFC changes, or dependencies.

Current local Windows LAN queue operation uses verified PHP 8.4.25 and Gmail SMTP. The [local queue runbook](local-lan-mail-queue.md) covers existing approval/rejection mail plus [queued registration verification](queued-registration-delivery.md), duplicate-worker prevention, graceful stop, and private delivery signals. This local helper makes one attempt per supported job and leaves unrelated, failed, or previously attempted jobs for review. Existing account review/decision notifications are unchanged. Deployment and log-mailer observations below describe the original implementation handover.

The approved Caseworker dashboard and My Caseload view were implemented in a later scoped task. Their ownership rules, reused Registry UI, polling, validation, and deployment prerequisites are recorded in [caseworker-dashboard.md](caseworker-dashboard.md). Notification behavior and delivery prerequisites below remain unchanged.

Count-only, in-system beneficiary assignment notifications are now implemented as a separate extension in [beneficiary-assignment-notifications.md](beneficiary-assignment-notifications.md). It reuses this stored-notification service/API/bell, adds two count fields, and links eligible recipients to their own My Caseload. Registration, review, decision email, and delivery behavior below remain unchanged; assignment notifications send no mail.

Successful registration completion creates one unread stored notification for each currently verified Project Director. Directors remain eligible independently of their approval field, matching their existing review permission. Requesting or verifying an email link creates no notification. The notification links to Account Requests, and the newly registered Caseworker remains signed out and pending.

A successful pending-Caseworker approval or rejection creates one stored notification for that Caseworker and queues one decision email to the account's registered email address. Approval email links to Sign In; rejection email links to account status, which uses the existing authentication and status routing. Stored decision notifications link to the recipient's account status. No beneficiary is assigned by either decision.

The stored rows contain recipient/account IDs, an event key, notification kind, read timestamp, and creation/update timestamps. Their text is generated from the kind. Account notifications and decision emails contain no passwords, verification tokens, beneficiary details, or rejection reason; the existing authenticated status screen displays the reason. Queued mail carries only the decision and registered recipient address, without serializing a User model. Email links use the configured `APP_URL` rather than a request Host header.

Notification inserts execute inside the existing registration/review transaction. The review still uses its original conditional update of a pending Caseworker. A unique `(recipient_id, event_key)` constraint prevents a second notification for the same registration or account decision, including service retries. An unchanged or competing review creates nothing and cannot replace reviewer metadata. Storage failure rolls back both the workflow and all notifications; an outer transaction rollback also discards the email callback. The decision email is queued through an after-commit callback and a mailable implementing `ShouldQueueAfterCommit`.

Queue or synchronous transport exceptions after commit cannot undo the decision or its stored notification. Queue-dispatch failure logs only `Account decision email could not be queued.`, without exception text or mail payload. Successfully queued jobs allow three attempts with 60/300-second backoff. A queue-dispatch failure has no automatic outbox retry; restoring queue availability requires an operational recovery decision. Neither retrying the review nor refreshing the page creates another notification/email.

The authenticated bell now appears once in the shared application header and once on the separate account-status layout. It retains the existing bell appearance, responsive panel, light/dark colors, keyboard controls, focus indicators, status announcements, and escaped text. Stored notices share the **Your notifications** section and unread badge; eligible Caseworkers see account and caseload updates. The existing read-only Activity feed is a clearly separate section available only to eligible staff; its controller and beneficiary/activity behavior are unchanged.

The unread count loads immediately and polls every 30 seconds while the document is visible, including when the dropdown is closed. Hidden pages stop polling. Alpine destruction and replacement stop the previous timer, remove the visibility listener, and abort old requests. Pending/rejected users' bells have no Activity feed endpoint. Read mutations ignore older count/list responses so an in-flight response cannot undo the visible read state.

The API uses the existing `auth` and web/CSRF middleware, a 120-per-minute throttle, a recipient policy, and private/no-store JSON responses:

| Method and route | Behavior |
| --- | --- |
| `GET /account/notifications` | Latest 20 eligible notifications owned by the current recipient, plus their full unread count. |
| `GET /account/notifications/unread-count` | Current recipient's eligible unread count, independent of the list limit. |
| `POST /account/notifications/{uuid}/read` | Mark an owned notification read once; later submissions preserve the first timestamp. |
| `POST /account/notifications/read-all` | Mark all of the current recipient's eligible unread notifications read. |

Only those four named routes were added to the pending/rejected account allowlist. Caseworkers can access only their own account's decision notifications and, when approved and verified, their own assignment notices. Restricted accounts' assignment notices remain excluded from list/count/read/read-all. Verified Directors can access only request notifications addressed to them. Unverified Directors, admins, and guests cannot read this API; another recipient's ID returns 404. Existing staff, photo, JSON, Livewire, login, 2FA, passkey, email-verification, and security protections remain in place.

Changed source, tests, and documentation:

| File | Change |
| --- | --- |
| `database/migrations/2026_10_09_000002_create_account_notifications_table.php` | New notification table, ownership foreign keys, event uniqueness, unread index. Authored only; no real migration run. |
| `app/Models/AccountNotification.php` | Typed account events, recipient filtering, generic text, and destinations. |
| `app/Services/AccountNotificationService.php` | Transactional recipients/events and safe after-commit email dispatch. |
| `app/Services/EmailRegistrationService.php` | Notify eligible Directors inside successful registration completion. |
| `app/Actions/ReviewCaseworkerAccount.php` | Notify/queue mail only after the existing conditional review succeeds. |
| `app/Mail/AccountDecisionMail.php` | Queued approval/rejection mail with after-commit behavior and bounded retries. |
| `resources/views/mail/account-decision.blade.php` | Generic decision email and Sign In/status link. |
| `app/Http/Controllers/AccountNotificationController.php` | Owned list/count/read endpoints, idempotent read timestamps, private responses. |
| `app/Policies/UserPolicy.php` | Account-notification recipient permission. |
| `app/Http/Middleware/EnsureAccountApproval.php` | Exact notification-route exceptions for restricted accounts. |
| `routes/web.php` | Four unique authenticated notification routes; existing registration/review routes reused. |
| `resources/views/components/rlcp-notifications.blade.php` | Existing bell extended with unread/read controls and separate Activity feed. |
| `resources/views/layouts/app/sidebar.blade.php` | One shared header bell on staff and account-security pages. |
| `resources/views/dashboard.blade.php` | Remove the old page-level bell now supplied by the shared header. |
| `resources/views/account-status.blade.php` | Own-notification bell without an Activity feed. |
| `resources/js/account-notifications.js` | Visible-page polling, timer/request cleanup, ownership API and read interactions. |
| `resources/js/app.js` | Import the bell module. |
| `resources/css/account-notifications.css` | Reused bell/panel styling with responsive, dark, and accessible states. |
| `resources/css/app.css` | Import notification styling; format with the installed formatter. |
| `tests/Feature/AccountNotificationTest.php` | Recipients, workflow timing, rollback, duplicate/concurrent decisions, ownership, safe mail and failures. |
| `tests/Feature/DirectorAccountReviewTest.php` | Update the existing approval assertion to expect one queued decision email. |
| `tests/js/account-notifications.test.mjs` | Polling while closed, visibility/navigation cleanup, read behavior and stale/error responses. |
| `docs/account-notifications.md` | Current implementation, checks and deployment/mail/queue handover. |
| `docs/director-account-review.md` | Link the review foundation to this later notification extension. |

The build regenerated `public/build/manifest.json`, `public/build/fonts-manifest.json`, and assets under `public/build/assets/`, including `app-Fq5v50vk.css` and `app-PANafCj7.js`. Package manifests and lockfiles are unchanged.

Validation on October 9, 2026 used the installed PHP 8.4.25 runtime and existing dependencies:

| Check | Result |
| --- | --- |
| New notification feature tests | 30 passed; 330 assertions. |
| Focused notifications/review/access/authentication/settings/legacy tests | 195 passed; 2,051 assertions. |
| Full feature regression suite | 503 passed; 4,023 assertions. Includes both assignment paths and existing authentication/access behavior. |
| New notification JavaScript tests | 9 passed. |
| All existing/new JavaScript suites | 94 passed. |
| Pint | Final check passed on all 12 changed PHP files. |
| Oxfmt | Final check passed on the 5 changed JavaScript/CSS/test files. |
| Asset build | `npm run build` passed. The existing font plugin reported its optional `fontaine` optimization warning; no dependency was installed. |
| Mail/queue configuration inspection | Allowlisted configuration output only: local environment, `mail=log`, `queue=database`. No credentials printed. |

The shared test bootstrap rejects any database other than SQLite `:memory:` and requires testing environment, array mail/session/cache, and an isolated sync queue before migrations can run. Mail and Laravel notifications are faked. The new tests use memory-only migrations and real commit boundaries, discarding the in-memory connection for cleanup. Storage/queue/worker failures use injected fakes. Competing review decisions are deterministically interleaved around the conditional write; no external database or concurrent production workers are used. JavaScript tests simulate visibility and navigation; visual/device checks remain deployment validation.

Re-run the focused checks with PHP 8.4.1 or newer:

```powershell
php vendor/bin/phpunit tests/Feature/AccountNotificationTest.php tests/Feature/DirectorAccountReviewTest.php tests/Feature/AccountApprovalAccessTest.php tests/Feature/Auth tests/Feature/Settings tests/Feature/UserApprovalFoundationTest.php tests/Feature/CaseworkerAccountCreationTest.php tests/Feature/DashboardTest.php --no-progress
node --test tests/js/account-notifications.test.mjs
php vendor/bin/pint --test app/Actions/ReviewCaseworkerAccount.php app/Services/EmailRegistrationService.php app/Services/AccountNotificationService.php app/Models/AccountNotification.php app/Mail/AccountDecisionMail.php app/Http/Controllers/AccountNotificationController.php app/Http/Middleware/EnsureAccountApproval.php app/Policies/UserPolicy.php routes/web.php database/migrations/2026_10_09_000002_create_account_notifications_table.php tests/Feature/AccountNotificationTest.php tests/Feature/DirectorAccountReviewTest.php
npm run build
```

Deploy the existing approval/registration prerequisites first if still pending, following [director-account-review.md](director-account-review.md). Do not repeat the legacy approval backfill or reset completed reviews. This task adds one migration, which must be deployed before this code's workflow/UI is enabled:

```powershell
php artisan migrate --path=database/migrations/2026_10_09_000002_create_account_notifications_table.php --force
```

For the configured database queue, the existing `jobs` and `failed_jobs` schema from `0001_01_01_000002_create_jobs_table.php` must already be deployed. No new queue-table migration or queue dependency was added. Publish the built assets and refresh caches/restart long-lived application and queue processes through the deployment procedure:

```powershell
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Run a supervised queue worker using the deployment's configured connection. Keep its timeout shorter than the configured queue retry interval; for a 90-second retry interval, this example uses a 60-second timeout:

```powershell
php artisan queue:work --tries=3 --timeout=60
```

Actual inbox delivery remains outstanding: this workspace's configured mail transport is **log-only**. Configure the deployment's provider transport and sender separately, ensure the application and worker share the configuration, and set `APP_URL` to the public HTTPS origin for both email links. The database queue needs a running worker and failed-job monitoring/recovery; starting a worker alone with the log transport still produces log mail rather than delivered inbox messages. Provider configuration, delivery checks, and worker supervision are operational prerequisites and were not changed here.

No real migrations, database records, emails, SMTP configuration, or dependency changes were performed. No implementation remains within the original account-notification scope. The later [My Caseload](caseworker-dashboard.md) and [assignment-notification](beneficiary-assignment-notifications.md) extensions have their own handovers. Assignment email, Attendance, and NFC changes remain outside this task.
