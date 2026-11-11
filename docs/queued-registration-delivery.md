Queued email-first registration delivery, October 16, 2026.

The slow Send Verification Link response came from `EmailRegistrationService::requestLink`: it saved the request, then called SMTP synchronously before redirecting. Connection setup, TLS, authentication, and SMTP submission blocked the response. The form showed no progress while waiting. No real message was sent to diagnose or implement this change.

The service now replaces the request in a transaction and dispatches `SendRegistrationVerification` only after the outer commit. The job explicitly uses the existing `database` queue, preventing a synchronous default from putting SMTP back into the HTTP response. It reuses the existing verification mailable, email-first flow, account privacy, rate limits, unique request row, single-use verification, registration session, pending Caseworker creation, Director review, and decision emails.

JavaScript submissions receive HTTP 202: “If this email is available, a verification email has been queued. Check your inbox shortly.” Existing accounts receive the same confirmation without a registration request/token or email; the owner-authorized October 19 update queues only `SkipRegistrationVerification`, containing no recipient data and performing no delivery or account changes. Native POST still redirects to `/register` with that message. Both address types share one after-commit dispatch/catch path. Dispatch failure invalidates the unused address's matching generation and returns the same generic HTTP 503 body for web and JSON, with no redirect or success flash. See [RER-03 evidence and limits](registration-email-reliability-audit.md#rer-03-follow-up-owner-authorized-no-email-jobs-october-19); response uniformity is not complete timing-side-channel protection.

The job contains the request ID, generation hash, and a token encrypted with the existing application encrypter. It contains no plaintext token, bearer URL, recipient address, email body, or User model. The worker resolves the request address and builds the link from `APP_URL` only when sending. Web and worker processes must use the same existing application key. Payloads remain private operational data and must not be printed.

Delivery skips expired, missing, verified/consumed, completed, superseded, or already-registered requests. The decrypted token must match the current generation. An atomic claim in the existing shared cache prevents another attempt for that generation until expiry, including after a worker interruption; a resend gets a new hash. This uses the cache already needed for rate limits. SMTP failure gets one attempt, invalidates only its generation, and logs a static warning without chaining the original transport exception into failed-job diagnostics. There is no automatic resend.

No schema change or migration is required. Existing `registration_requests`, `jobs`, `failed_jobs`, and cache storage are reused. No real migration, registration, review, SMTP/credential change, or email delivery is part of implementation. Registration now refuses log mail transports, including local log mail and composite fallbacks, so links cannot enter mail logs.

The form reuses Flux and Alpine. It immediately announces “Queuing verification email”, shows animated dots, marks the form busy, disables repeat submission, and restores controls after success/failure. It retains the entered email. Validation focuses the email field; 429/session/server errors use accessible feedback. A lost response is reported as uncertainty without retrying automatically. Reduced-motion preferences disable the dot animation. Native POST remains available without JavaScript.

The [LAN helper](local-lan-mail-queue.md) supports **account_decisions_and_registration_verification**. Worker reuse, duplicate protection, graceful stop, one-attempt behavior, and sanitized SMTP reporting remain. Obsolete/duplicate verification jobs finish without SMTP and are recorded as `verification_skipped_without_send`; no-email jobs are recognized separately and recorded as `registration_skipped_without_email`, without SMTP acceptance. Unrelated, reserved, or previously attempted queued jobs still stop it for review. Existing decision mail still requires the saved Caseworker decision. Deploy the updated service/job/helper together and gracefully restart the existing helper before further LAN testing; no worker was inspected or restarted during the October 19 implementation.

Restart the long-running worker after deployment; the old process cannot acquire the new scope. It is stopped during implementation to prevent real delivery. From `C:\rlcsystem\app`:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\lan-mail-queue.ps1 -Action Inspect
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\lan-mail-queue.ps1 -Action Stop
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\lan-mail-queue.ps1 -Action Start
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\lan-mail-queue.ps1 -Action Status
```

No configuration-cache rebuild or SMTP change is required. The wrapper uses verified PHP 8.4.25 and `registry.ini`. Closing its terminal normally leaves the worker running; Windows shutdown/sign-out requires manual startup. Graceful stop waits for an active send. Windows has no hard `pcntl` job alarm. Queue blockers and failed jobs require review, never a bulk retry.

Checks use guarded SQLite `:memory:`, fake mail/queues, and synthetic recipients. They cover commit/rollback, no SMTP in POST, private payloads, active/stale/duplicate delivery, existing accounts, rate limits, safe failures, registration, and Director decisions. JavaScript checks cover loading, repeated submission, success, validation, 429/419/503, network uncertainty, restored controls, and reduced motion. Helper self-tests create no persisted jobs or mail. Assets are rebuilt with existing dependencies.

Confirmed checks:

| Check | Result |
| --- | --- |
| Focused registration/delivery/notification PHP cases | 93 passed; 1,024 assertions. |
| Full feature regression suite | 607 passed; 7,435 assertions. |
| JavaScript suites | 118 passed, including 8 new registration cases. |
| LAN helper scope self-test | 28 passed using SQLite `:memory:`; no sends or persisted jobs. |
| Headless Edge registration check | Passed against the LAN form with every POST intercepted/mocked. Verified disabled controls, normal/reduced motion, queued confirmation, 429, network uncertainty, and validation recovery. |
| PHP style | Pint passed on all 7 changed PHP files. |
| Asset build | `npm run build` passed. Existing optional `fontaine` optimization notice only; no package installed. |
| Real queue at handover | Worker stopped, 0 pending and 0 failed. New scope is reported by `Inspect`. No real sends or approvals performed. |

The browser check can be repeated without permitting any real POST:

```powershell
$env:REGISTRATION_UI_BROWSER = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
node --test .\tests\browser\registration-email.test.mjs
```

Manual verification, when a real send is intended:

1. Inspect the queue and start exactly one updated worker. Do not retry failed/reserved jobs.
2. Open the [LAN registration form](http://192.168.110.41:8000/register) and browser Network tools. Submit once using an unused inbox you control. Confirm immediate dots/busy feedback and a disabled button; rapid clicks/Enter must produce one POST. Expect 202 and queued confirmation, or 302 to `/register` without JavaScript.
3. Check one sanitized send result and a numeric SMTP 250 when captured. Confirm Inbox/Spam separately. Keep request bodies, links, SMTP debug, tokens, and email bodies private.
4. In an isolated test environment, check obsolete requests and mail failures. With browser network requests blocked, confirm an uncertainty message, restored controls, and no automatic retry. Actual repeated requests retain the existing 429 limit.
5. Privately open the link and complete registration. Confirm the account remains pending and signed out. Existing Account Requests approval/rejection and decision mail continue unchanged.

Changed files:

| Area | Files |
| --- | --- |
| Delivery | `app/Jobs/SendRegistrationVerification.php`, `app/Services/EmailRegistrationService.php`, `app/Http/Controllers/RegistrationController.php` |
| LAN worker | `scripts/lan-mail-queue.php` |
| Registration UI | `resources/views/livewire/auth/register.blade.php`, `resources/js/registration-email.js`, `resources/css/registration-email.css` |
| Asset imports | `resources/js/app.js`, `resources/css/app.css` |
| PHP checks | `tests/Feature/Auth/RegistrationDeliveryQueueTest.php`, `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/AccountNotificationTest.php` |
| JavaScript/browser checks | `tests/js/registration-email.test.mjs`, `tests/browser/registration-email.test.mjs` |
| Docs | This report, `docs/email-first-registration.md`, `docs/local-lan-mail-queue.md`, `docs/account-notifications.md` |
| Rebuilt assets | `public/build/manifest.json`, `public/build/fonts-manifest.json`, generated assets including `app-BT8HI8ul.css` and `app-Dc15XX5v.js` |

Dependencies, lockfiles, SMTP settings, cached configuration, and schema are unchanged.
