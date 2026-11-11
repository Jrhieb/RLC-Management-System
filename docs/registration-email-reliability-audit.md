# Registration and email-delivery reliability audit

Dates: 2026-10-18–19, Asia/Manila. Scope: existing email-first registration/resend, existing-account verification, Director decisions, their mail/jobs and the LAN queue adapter. Source/migrations and synthetic SQLite `:memory:` only; no live schema, configuration contents or queue inventory was inspected.

## Findings

| ID | Severity | Confirmed evidence | Status |
| --- | --- | --- | --- |
| RER-01 | Medium | With the log mailer selected in isolated config, a new address received 422 while existing verified **and unverified** accounts received 202. `EmailRegistrationService::requestLink` used to return for existing accounts before checking the globally unsafe transport. Two regression datasets failed before the fix. | **Fixed:** run the existing safe-transport check before the existing-account shortcut (`app/Services/EmailRegistrationService.php:38`). These unsafe-configuration errors create no account/mail/job. Normal configured-mail responses are unchanged. |
| RER-02 | Medium | Standard `SendQueuedMailable` propagated the original decision-mail transport exception. A synthetic credential marker survived in its exception string for both approval and rejection. The LAN adapter already sanitized this path, but standard Laravel job handling did not. Two regression datasets failed before the fix. | **Fixed:** `AccountDecisionMail::send` (`app/Mail/AccountDecisionMail.php:34`) wraps delivery failures in a static exception without a previous exception. Tests also verify safe persisted `failed_jobs.exception`. Existing tries/backoff/decision persistence and payload shape remain. |
| RER-03 | Medium | Before the follow-up fix, existing accounts returned generic JSON 202/web success without dispatch, while unused addresses received JSON 422/web registration errors on dispatch failure. Eight failing-first web/JSON datasets reproduced this for unused, resend, existing verified and existing unverified addresses. | **Fixed with the owner's explicit no-email-job exception:** both paths use `EmailRegistrationService::queueVerification`; existing accounts enqueue `SkipRegistrationVerification`, containing only queue metadata. Any observed dispatch exception uses the existing static 503 response, without redirect or success/error flash. Unused generations are invalidated; no-email jobs cannot send. The helper recognizes and safely consumes them. Timing and broader side channels are not claimed fixed. |

The expected-failure simulations themselves are not production incidents. No real transport failure or credential exposure was observed. No migration is required for these fixes.

## Isolation and baseline before edits

The revised brief explicitly permits the existing temporary-bootstrap harness despite a live project cache being present. Only the existence of `bootstrap/cache/config.php` was checked. Its contents, `.env`, live database files, real queue/failed-job rows, logs and worker state were **not read**. No cache was cleared/rebuilt.

The existing `tests/TestCase.php` and `tests/Support/TestIsolation.php` were inspected before boot. They reject non-testing environment, unsafe database URLs, non-memory SQLite configuration, alternate default/driver, read/write connection overrides, disabled foreign keys and bootstrap/environment path overrides. Each app selects a fresh owned temporary bootstrap/cache directory before kernel bootstrap; existing selected caches fail the guard. Effective SQLite `:memory:` and fake mail/session/cache/queue settings are checked before providers and before migration access.

An additional isolation gap was found directly in the harness source: it isolated bootstrap and storage, but Laravel's `LoadEnvironmentVariables` would still search the project environment directory. `.env.testing` was absent (existence check only), so running the original harness would fall back to the prohibited project `.env` (`vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/LoadEnvironmentVariables.php:26`).

To honor **baseline before file edits**, the baseline process loaded the unchanged `Tests\TestCase` source into memory with two safety-only additions: select its already-owned temporary storage as the environment directory, and use a synthetic 32-byte application key. No application or test assertion was changed for that run, and no project file was edited. After it passed, equivalent protection was persisted in `tests/TestCase.php`: temporary environment path, synthetic test key, empty previous-key list, canonical path checks before bootstrap, and teardown of the test-only environment variables. This is test safety hardening, not a production registration behavior change.

The selected baseline files were:

- `tests/Feature/Auth/RegistrationTest.php`
- `tests/Feature/Auth/RegistrationDeliveryQueueTest.php`
- `tests/Feature/Auth/EmailVerificationTest.php`
- `tests/Feature/DirectorAccountReviewTest.php`
- `tests/Feature/AccountNotificationTest.php`
- `tests/Unit/TestIsolationTest.php`

**Baseline: 157 tests / 1,251 assertions, passed** (PHP 8.4.25, existing `registry.ini`; 17.8 seconds). Mail/notifications were fake. The isolated queue tests use actual commit boundaries and discard their memory PDO; no worker starts.

## Implementation map

| Path / component | State, authorization and transaction behavior | Mail/queue behavior | Response |
| --- | --- | --- | --- |
| `GET /register`, `POST /register` — `RegistrationController::show/requestLink`, `EmailRegistrationService::requestLink` | Guest routes. Lowercase/trim email, validate required string/email/max:255. Existing accounts, including unverified legacy accounts, are left unchanged; their no-email job creates no registration row/token. New address: one upsert by normalized email replaces token/session generation inside a default-connection transaction. No User is created yet. | New address: hash stored; ciphertext, request ID and generation hash enter `SendRegistrationVerification`. Existing address: `SkipRegistrationVerification` has only connection/tries metadata. Both dispatch through `queueVerification` in `DB::afterCommit`; failures invalidate only the unused matching generation and log a static warning. | Normal web 302 `/register` with old email/status; JSON 202 `{message}` with unchanged conditional queued confirmation. Dispatch exception: **503, identical static body for web and JSON**, no redirect or flash. Input validation still uses web 302 errors / JSON 422. |
| Email-first resend | Same endpoint/service, not a separate route/job/type. Same row ID and original created time retained; link hash/expiry and session state replaced; old links/copied sessions revoked. | Same after-commit verification job, same rate limits; no automatic resend. | Same conditional queued response or existing errors/throttle behavior. |
| `GET /register/verify/{token}` — `EmailRegistrationService::verify` | Guest route. Exact lowercase hex token syntax; atomic conditional update requires current hash, unexpired link, null verified/completed state. Consume hash and create hashed 15-minute completion-session secret; transaction allows 3 framework concurrency attempts. | No mail. Session ID rotates; bearer URL not retained as previous URL (`RegistrationSafeSession`). | 302 to token-free completion page, or back to `/register` with generic invalid/expired/used error. |
| `GET/POST /register/complete`, awaiting approval | Active hashed session required. Atomic session consumption, email-existence recheck, save one verified **pending Caseworker**, transactional Director in-app notices. No login and no `Registered` event; no conflicting framework verification send. | No verification/approval email from completion; only stored Director notification records. | Completion 302 to awaiting-approval page. Invalid/expired/superseded session returns token-free `/register` error. Existing profile/password validation and sensitive old-input protection retained. |
| Existing-account verification: `GET /email/verify`, `POST /email/verification-notification`, signed `GET /email/verify/{id}/{hash}` | Fortify routes (`vendor/laravel/fortify/routes/routes.php:87`); authenticated User, signed GET, current ID/email hash. Signed expiry defaults to 60 minutes. `/register` does not create a second account/token for this user. | Framework `Illuminate\Auth\Notifications\VerifyEmail` is **synchronous**, not queued. No `registration_requests` row; no persistent token generation or database sent/claim marker. Resend does not supersede older signed links; each remains valid until its expiry/current-email change/verification. | Resend web 302 with `verification-link-sent`; JSON 202 with JSON empty string. Already verified: JSON 204 / existing redirect. Signed invalid/expired URL 403. Successful verification uses the existing approval-aware redirect. |
| Director approve/reject: `POST /account-requests/{user}/review` | `AccountRequestController`, `ReviewCaseworkerAccount`. Auth/verified/staff middleware plus Director gate. Validate decision, confirmation and rejection reason. Conditional update requires pending Caseworker; decision/reviewer/time and stored account notice in one transaction, 3 framework concurrency attempts. | `AccountNotificationService::decisionSaved:54` stores unique notice and registers `DB::afterCommit` callback. Captures registered address + immutable decision scalars, never a serialized User. Queue failure is caught and logged statically; decision/notice remain committed. | Success web 302/status or JSON 200 `{message}` saying account approved/rejected — **does not claim mail was sent**. Repeated/changed review: web errors / JSON 409. Validation 302/422. Unauthorized roles denied under existing policy. |
| `RegistrationVerificationMail` | Existing markdown template, link built only in delivery service from configured origin and remaining expiry. | Sent synchronously **inside** verification job; not independently queued. Subject unchanged. No own retry/failure hook. | Transport return means send returned, not inbox receipt. |
| `AccountDecisionMail` / `SendQueuedMailable` | Existing subject/template and safe configured-origin login/status destination. Delivery now sanitizes thrown details (RER-02). | Queued after commit, default queue connection/name. Standard wrapper calls mailable `send`; decision and address are snapshots. See job table. | No asynchronous inbox claim in Director response. |

`ProtectRegistrationResponse` and registration-specific exception rendering in `bootstrap/app.php` retain private/no-store, no-referrer and noindex protections and static failure messages. Token-bearing session/redirect/debug handling remains in the existing components. Generic registration HTTP exceptions (including 429/503) are **plain static text even for JSON requests**, while normal queued responses and validation errors use JSON. The JS flow already handles text errors; no response/UI redesign was made.

### Jobs, source queue settings and failure hooks

| Job/notification | Connection / queue | Commit, tries, backoff, timeout, uniqueness and failure handling |
| --- | --- | --- |
| `SendRegistrationVerification` | Explicit `database`; no per-job queue name, so configured database queue (source default `default`). | `ShouldQueueAfterCommit`, plus explicit service after-commit callback. `tries=1`; no backoff, timeout, retry-until, uniqueness interface or custom `failed()` method. Delivery re-reads current request/generation/email existence; shared cache claim suppresses duplicate generation sends. Sanitized exception on failure; normal framework failer handles terminal errors. |
| `SkipRegistrationVerification` | Explicit `database`, same configured queue as verification. | Owner-authorized no-email job for an already-existing address. `ShouldQueueAfterCommit`, shared service after-commit callback, `tries=1`. Only serialized application properties are `connection` and `tries`; no recipient, account ID, token/hash, request ID, backoff, timeout, retry-until, uniqueness or `failed()` hook. Empty handler: no account lookup/change, claim or external delivery. |
| `SendQueuedMailable(AccountDecisionMail)` | Inherits configured default connection/queue; no explicit override. LAN helper requires `database`. | Mailable `ShouldQueueAfterCommit` becomes wrapper `afterCommit=true`; explicit service after-commit callback also applies. `tries=3`, backoff `[60,300]`; no job-specific timeout/retry-until/uniqueness. Wrapper `failed()` delegates only if mailable has a `failed()` method; this mailable has none (no-op). Existing immutable-review/unique notice prevents a second dispatch for repeated decisions. New send wrapper sanitizes errors without changing retry metadata. |
| Framework `VerifyEmail` | **Not applicable — synchronous**, no job/queue. | No job tries/backoff/timeout/failed/uniqueness or after-commit setting. It sends for an already-existing authenticated User; there is no registration transaction to roll back. It remains outside the LAN helper. |
| Other mail found | App mail/job inventory contains the two mail classes and two jobs above. | Stock password-reset notifications remain synchronous/outside this registration/decision fix; the helper does not accept arbitrary notification/mail jobs. Only the explicitly authorized no-email job was added. |

From **source** `config/queue.php`: default connection fallback `database`, database driver/table fallback `database`/`jobs`, queue fallback `default`, `retry_after=90`, **`after_commit=false`**; failed driver fallback `database-uuids`, database from configured DB connection, table `failed_jobs`. Live `.env`/cached overrides were not read, so these are **not claims about effective LAN configuration**.

Tests restore the actual Laravel database queue driver against the guarded `sqlite/:memory:` connection, assert that database name, and verify both rollback and commit for verification/approval/rejection despite `after_commit=false`. No `queue:work`, `queue:listen`, worker daemon or live helper processing is invoked.

### Rate limits and keys

| Path | Source configuration / key | Verification |
| --- | --- | --- |
| Email-first request/resend | `FortifyServiceProvider:71`: 10/minute `ip:{IP}`, 30/hour `ip-hour:{IP}`, 1/minute `email:{SHA256(lower(trim(email)))}`, 5/hour `email-hour:{same hash}`; non-string input hashes empty text. Standard named-throttle key hashing also applies. | Existing tests cover 1st/2nd email-minute, 10th/11th IP-minute, 5th/6th email-hour with changed IP. New tests cover 30th/31st IP-hour after minute windows reset, normalized variants and plus tags. |
| Email-first token GET | `throttle:60,1`, guest request-signature/IP key. | Route inspected; HTTP boundary coverage is **partial**, not 60-request load verification. |
| Completion POST | `throttle:10,1`, guest request-signature/IP key. | Route and invalid/expired completion checks covered; exact 10/11 completion rate boundary **not separately tested**. |
| Existing-account resend / signed GET | Fortify default `6,1`, authenticated request signature keyed by User ID. | New resend test asserts requests 1–6 accepted, 7th 429, then accepted after 61 seconds. Signed verification auth/hash/already-verified tests reused; expiry tested separately. |

Email-first throttle runs before validation/dispatch. Admitted invalid requests and failed dispatches consume limits even though no job is queued; admitted existing-account requests now queue only a no-email job. This remains an admission counter, not a sent-mail counter. The invalid-input test consumes the 10-IP window without queuing; the outage datasets verify the same email cooldown and recovery for both address types. `%`/Gmail dot or plus canonicalization is not added: case and whitespace normalize; distinct plus tags remain distinct addresses under existing rules. Existing 429 wording/Retry-After and all four keys/limits remain unchanged. Prior traffic can affect counters; no complete rate-limit side-channel protection is claimed.

## Schema and lifecycle

`2026_10_09_000001_create_registration_requests_table.php` defines unique normalized `email`, nullable unique 64-character `token_hash`, non-null `expires_at`, nullable `verified_at`, nullable unique `session_token_hash`, nullable `session_expires_at`, nullable `completed_at`, and created/updated timestamps. `RegistrationRequest` hides both hashes in array/JSON and uses immutable datetime casts for lifecycle times.

There is no `consumed_at`, `superseded_at`, DB delivery-claim or sent-at column. Consumption is represented by null token hash + verified/session state; supersession replaces the row generation; completion sets completed time and clears session credentials. These absent columns are **Not applicable**, not migration recommendations. Delivery claim is a shared-cache atomic add keyed by request ID and generation hash, expiring with the link, with a boolean value (no bearer token).

Link lifetime is 30 minutes; completion-session lifetime is 15 minutes from successful verification, independent of later link expiry. `AppServiceProvider` selects immutable dates; the new literal timestamp test verifies created/updated/expiry/verified/session-expiry times across resend and late verification without changing date logic. User verification uses `users.email_verified_at`; standard existing-account signed links have no stored bearer token. Director review state stays in existing approval/reviewer/reason/timestamp fields and unique account-notification events.

`0001_01_01_000002_create_jobs_table.php` defines jobs with indexed queue, payload, attempts, nullable reserved time, available/created Unix times; failed jobs have unique UUID, connection/queue, payload, exception and failed time with connection/queue/time index. The isolated tests use these migrations. Live schema, existing jobs and failed-job contents were not inspected. No schema change is needed/prepared/applied.

## LAN queue helper: read-only audit of implementation

`scripts/lan-mail-queue.ps1` is a local operations launcher/status/stop wrapper. It selects the verified PHP runtime plus `registry.ini`, checks for existing worker processes, serializes startup with a mutex and launches a hidden process only after safe inventory permits it. Stop writes a graceful-stop sentinel. **None of these live commands was executed in this audit.**

`scripts/lan-mail-queue.php` is a scoped adapter around Laravel's existing database worker. Normal execution reads the deployment cache and file-backed queue; **those branches were not executed**. Only its existing `--self-test` branch was run: it executes before live-cache loading, uses a new raw SQLite memory fixture, does not boot the application, send mail, create jobs or start a worker. The original **28 checks passed**; the RER-03 follow-up expands this to **36 passing checks**.

Supported serialized shapes:

- `CallQueuedHandler@call` containing `App\Jobs\SendRegistrationVerification`, valid request ID/hash/ciphertext, no chains/middleware. Obsolete generations remain recognized so the service can consume them without SMTP and count them as skipped.
- `CallQueuedHandler@call` containing `App\Jobs\SkipRegistrationVerification`, exactly database connection + one-try metadata and no additional properties. It has no delivery target; the existing before-send guard rejects any attempted message. Completion increments `processed`/`skipped` and records only `registration_skipped_without_email` plus job ID, never an SMTP-success result.
- `CallQueuedHandler@call` containing `Illuminate\Mail\SendQueuedMailable` with `AccountDecisionMail`, one valid recipient, approved/rejected decision, smtp/default mailer, no cc/bcc/chains/middleware. Current account must have Caseworker role and matching decision status. The helper's eligibility query does **not additionally require verified email**; no stronger rule was invented.
- Arbitrary mailables, Laravel queued notifications, password-reset mail jobs, other job classes, multi-recipient jobs and unsupported mailers are not supported. Framework existing-account verification is synchronous, so there is no helper job to process. Supported shapes on a different queue are not selected; normal app jobs use the configured queue.

Before sending, the helper rechecks current recipient/state/subject, permits at most one message per job, and blocks unrelated/reserved/previously attempted jobs. It uses an exclusive worker lock and conditional first reservation. It forces one attempt for **all three** job types, overriding the standard decision mailable's three attempts **only in this helper**; failures are stored but not retried. Existing failed jobs are inspected/reported, never automatically replayed.

The helper records sanitized job IDs/counters/static event names. On a send-return event it retains only a parsed SMTP reply code; code 250 increments its acceptance counter. Raw SMTP debug, credentials, links and bodies are not written by that reporting code. Send return / SMTP acceptance / inbox receipt remain different facts. No actual acceptance or receipt was observed here.

## Coverage matrix

Aliases: **R** `Auth/RegistrationTest`; **Q** `Auth/RegistrationDeliveryQueueTest`; **V** `Auth/EmailVerificationTest`; **A** `AccountNotificationTest`; **D** `DirectorAccountReviewTest`; **N** new `Auth/RegistrationEmailReliabilityTest`. All are under `tests/Feature`. Cells show final coverage: C covered, P partial, N not covered, NA Not applicable. The cited methods distinguish reused baseline coverage from additions.

| Sections 4–10 check | Email-first request/resend | Token verify/complete | Existing-account verification | Approval/rejection | LAN helper |
| --- | --- | --- | --- | --- | --- |
| Normal new/existing privacy and no duplicate account | C R `test_request_queues_delivery_stores_only_hashes_and_never_creates_a_user`, `test_an_existing_email_is_not_mailed_or_changed_including_mixed_case_legacy_emails`; Q `test_json_request_returns_queued_confirmation_without_calling_smtp_and_preserves_account_privacy` | C R `test_verification_rotates_session_and_completion_forces_verified_pending_caseworker_without_logging_in` | C N `test_existing_unverified_account_uses_fortify_resend_and_its_six_per_minute_user_limit` | NA | NA |
| Case/whitespace/plus normalization | C N `test_normalization_preserves_plus_tags_and_case_whitespace_cannot_bypass_email_cooldown` | C R email-forgery/account-claim tests | C N legacy case/whitespace request | C A registered-recipient test | C synthetic case-insensitive recipient lookup |
| Repeated/interleaved operations | C R resend tests; Q superseded-generation tests | C R `test_interleaved_completions_can_create_only_one_account`, `test_atomic_completion_rechecks_a_session_invalidated_after_the_form_was_authorized`, duplicate-email race simulation | P V already-verified/hash cases; signed resends intentionally do not supersede | C A `test_interleaved_decisions_create_one_notification_and_one_mail`; D conditional-review tests | P synthetic reservation/classification only; true concurrency unverified |
| After commit / rollback / actual DB queue with after_commit false | C Q outer commit/rollback; N `test_database_queue_with_after_commit_false_discards_rollback_and_enqueues_after_commit` | C A outer registration rollback | NA (sync for existing user) | C A `test_decision_notification_is_transactional_and_mail_waits_for_outer_commit`, `test_rolled_back_decision_discards_stored_notification_and_after_commit_email`; N actual driver case | P serialized shapes/self-test; no worker run |
| Expired/consumed/superseded/duplicate jobs and links | C Q `test_obsolete_delivery_is_skipped_without_mail`, `test_superseded_job_does_not_invalidate_or_send_the_new_request`, `test_duplicate_delivery_of_the_same_generation_sends_only_once` | C R exact expiry/replay/tamper/copied-session tests; N timestamp test | C V hash/already-verified; N signed-expiry case. Persistent generation supersession NA | NA (decision immutable, not bearer-token job) | C obsolete request synthetic guards |
| Deleted/changed account state | C Q account-claimed/deleted-request cases | C R claimed email cannot overwrite account | P V current hash/auth rules; deletion not simulated through real session provider | C N `test_standard_decision_job_uses_its_captured_recipient_after_account_change_or_deletion` documents snapshot behavior, not a current-state gate | C self-test recipient/status recheck; actual running worker unverified |
| Resend limits and counters | C R minute email/IP and hourly email tests; N hourly IP, invalid-counting and normalized variants | P token/completion exact throttle boundaries not added | C N six/seven request boundary and reset | NA (review CAS, not email resend route) | NA (no resend creation) |
| Dispatch failure truthful response | C R `test_enqueue_failure_invalidates_the_new_link_without_logging_details_during_queue_dispatch_failure`; N `test_registration_returns_a_generic_retry_later_response_during_queue_dispatch_failure` (8 datasets, including recovery) | NA | NA (sync) | C A `test_queue_failure_leaves_successful_decision_and_notification_and_logs_only_static_message` | C synthetic no-email shape/target/attempt guards; P no live dispatch |
| Transport failure and safe diagnostics | C Q delivery failure/tampered ciphertext/log-transport tests; N `test_transport_failure_leaves_no_live_link_but_a_fresh_request_after_cooldown_can_send` | NA | **P** notification dispatch fake; stock synchronous transport failure details not end-to-end tested | C N `test_decision_transport_failure_has_no_sensitive_exception_chain_or_failed_job_diagnostics`; A transport failure cannot undo committed decision | P source sanitized wrapper; no real SMTP/worker failure |
| Claims / possible acceptance / duplicate risk | C Q duplicate/supersession cases; N `test_simulated_acceptance_followed_by_error_does_not_retry_the_same_generation` | NA | NA (no claim mechanism) | P existing tries/backoff and snapshot behavior; no exactly-once send claim | P one-attempt guards/self-test; no crash/concurrency proof |
| Director authorization, truthful saved decision, no repeated emails | NA | NA | NA | C D authorization/confirmation/review-repeat tests; A failure/duplicate tests | C synthetic recipient/status restrictions; live operation unverified |
| Payload/queue compatibility and responses | C Q ciphertext-only serialization + N actual DB payload, including minimal no-email metadata/rollback | C R token-free session/redirect tests | C N existing sync response/limit checks | C A safe mail content/registered origin; N standard payload/tries/backoff | C 36 self-test checks; P production adapter behavior unverified |
| Privacy under failure variants | C N RER-01 and RER-03 regressions; no timing/global guarantee | C R debug-token protection | P framework path, no global guarantee asserted | C N sanitized failure diagnostics | P source plus synthetic scope checks, no live contents inspected |

The baseline already covered most registration/session/decision atomicity. New coverage fills actual-driver commit behavior, the two regressions, hourly-IP boundary, no-queue counter consumption, plus variants, fresh-generation recovery, ambiguous acceptance, legacy resend/expiry, literal event times and captured-recipient changes. No production hook or new notification type was added.

## Checklist verdicts

| Check | Verdict | Evidence / limit |
| --- | --- | --- |
| Isolated environment, bootstrap caches, storage and database before boot | **OK** | Persisted `tests/TestCase.php` safeguards and `TestIsolationTest`; root environment/cache contents never loaded. |
| Normal registration privacy, normalization and single-account completion | **OK** | R/Q reused tests and N normalized-variant, legacy and timestamp cases in the matrix; interleavings are simulations. |
| Unsafe transport configuration privacy | **Defect, fixed: RER-01** | Two failing then passing verified/unverified regression datasets. |
| Queue-outage response privacy | **Defect, fixed: RER-03** | N eight failing-first datasets now assert literal 503/body, no redirect/flash, no accepted job/mail/live token, unchanged accounts, unchanged cooldown and recovery. No-email exception explicitly authorized. |
| Verification/decision enqueue after commit, rollback and payload visibility | **OK** | N three actual database-driver cases with `after_commit=false`, SQLite `:memory:` only; reused Q/A rollback tests. |
| Obsolete links/jobs, exact expiry and duplicate-generation claims | **OK** | R/Q lifecycle cases and N fresh-request/ambiguous-send simulations; no exactly-once SMTP guarantee asserted. |
| Resend limiter keys, normalization, counters and boundary responses | **OK** | R email/IP minute and email-hour cases; N hourly-IP, invalid-counting and legacy six/seven cases. |
| Token-GET and completion exact throttle boundaries | **Unverifiable by this test run** | Source middleware inspected; exact 60/61 and 10/11 request boundaries not exercised. |
| Saved Director decisions, authorization and repeated reviews | **OK** | Existing D/A tests; queued mail failure preserves the committed decision and does not claim delivery. |
| Standard decision transport error secrecy | **Defect, fixed: RER-02** | N approval/rejection exception and isolated failed-job persistence regressions. |
| Standard decision-job behavior after an account changes or is deleted | **OK under existing snapshot behavior; policy conflict recorded** | N deleted/email/status-changed cases prove captured-recipient sends; current-state revalidation is not provided by the standard job, while the helper has that gate. No new rule imposed. |
| Synthetic helper job-shape compatibility and safe reporting | **OK within synthetic coverage** | 36-check `--self-test`, actual memory queue payload tests and source inspection; live adapter operation not exercised. |
| Real SMTP acceptance, inbox receipt, live helper/queue and true concurrency | **Unverifiable** | No network, workers or live data/configuration access allowed or performed. |
| Dedicated consumed/superseded/sent-at columns, custom failed hooks, new migration | **Not applicable** | Existing lifecycle uses hashes/state and a cache claim; relevant jobs have no custom `failed()` hook; no schema change required. |

## Claims, duplicate-send risk and owner policy

**Verification generation:** a cache claim is taken **before** SMTP. It is a claim, not a sent/delivered timestamp. Failure leaves that claim but invalidates only its matching token generation; one automatic attempt is intentional. A fresh user request after normal cooldown creates a new hash and can send. The new recovery test proves this is not a permanent resend lock. Clearing old claims or automatically replaying ambiguous sends could duplicate mail and was not implemented.

**Possible SMTP acceptance followed by error/crash:** sender/transport exceptions do not prove that SMTP rejected a message. The same-generation claim and `tries=1` reduce duplicate risk; an already accepted link may then be invalidated after an ambiguous error. A manually requested new generation can produce another email by existing design. The simulation records no real SMTP acceptance. Cache eviction/nonshared cache, process death and multi-process timing are not proven by array-cache memory tests.

**Decision mail:** standard processing retains existing three attempts and no delivery claim. Acceptance followed by lost acknowledgment, job deletion failure or process crash can lead to duplicates on retry. LAN helper intentionally uses one attempt instead; that can leave uncertain/failed/reserved jobs needing manual review. No automatic retry/reset/scheduler/recovery command was added.

Owner decision resolved for RER-03; other policy observations remain:

1. **RER-03 decision adopted:** prioritize uniform truthful dispatch failures and explicitly permit a minimal no-email job for existing accounts, plus helper support. That is the only exception to the previous zero-job rule. Normal responses/accounts/rate limits and verification/decision retries are preserved. No outbox, queue probe, automatic resend, recovery command or claim reset was introduced. No further owner decision blocks this fix.
2. **Dispatch loss after a saved decision.** Existing policy preserves the decision and stored notice if queueing fails; repeated approval cannot resend. Choose whether/when any manual recovery may send after an uncertain prior result before adding it. No emailed/sent flag is falsely persisted today.
3. **Recipient snapshot vs current-state revalidation.** Standard decision jobs send the registered address/decision captured at review even if the account is later deleted, changed or has its email changed; three in-process array-transport cases confirm no stale-model exception loop. The helper instead blocks/rechecks current role/status/email. Replacing that existing snapshot policy would require an explicit decision and payload/helper compatibility review; it was not guessed.

These policy observations are not a request to retry any live job. No account review was repeated.

## RER-03 follow-up: owner-authorized no-email jobs (October 19)

Inspection and the protected baseline preceded all follow-up file edits: **176 tests / 1,549 assertions passed** across the seven registration/delivery/reliability/verification/Director/notification/isolation test files. The established temporary environment/bootstrap/cache/storage guard was reused unchanged; the live cache contents and `.env` were never read. No baseline repeat was needed after the owner answered the contract question because no file had changed in between.

The conflict was concrete: an existing-account branch that never calls the dispatcher cannot observe an arbitrary dispatch exception. A catch-only 503 change would still distinguish success from failure. A separate readiness probe cannot guarantee the subsequent dispatch result. The owner explicitly authorized no-email jobs and corresponding helper support, while preserving accounts, normal responses, rate limits and the ban on public-registration mail for existing accounts.

### Endpoint/address response map

`S` = “If this email is available, a verification email has been queued. Check your inbox shortly.” `F` = “Registration is temporarily unavailable. Please try again later.”

| Endpoint / address type | Dispatch / catch | Normal status/message/redirect | Dispatch-failure status/body/redirect/flash | Rate-limit effect |
| --- | --- | --- | --- | --- |
| `POST /register`, unused address, including resend | `SendRegistrationVerification`, after commit; shared `queueVerification` catch | JSON 202 `{message: S}`; web 302 `/register`, old email + `status=S` | **503, literal body F**, both web and JSON; no Location, status flash, validation errors or old input | Same 10/min + 30/hour IP and 1/min + 5/hour normalized-email admission limits; failures consume them |
| `POST /register`, existing verified or unverified account | `SkipRegistrationVerification`, same database connection/queue and after-commit catch; no request row/token | Same JSON/web response S as unused address; account unchanged; no public-registration mail | **503, literal body F**, both web and JSON; same absent redirect/flash/error structure | Same four limits; immediate retry 429, retry after 61 seconds works in fixtures |
| `POST /email/verification-notification`, authenticated unverified / verified account | Synchronous framework notification / already-verified shortcut; no queue dispatch | Unverified: JSON 202 empty string / web 302 back with `verification-link-sent`. Verified: JSON 204 / existing redirect | Queue-dispatch outage **Not applicable**; this authenticated path is unchanged | Existing 6/minute User limit |
| `POST /account-requests/{user}/review`, eligible pending Caseworker | After-commit decision mail; existing notification-service catch preserves saved review | JSON 200 decision-saved message / web 302 Account Requests with decision-saved status | Same truthful saved-decision response, no mail-delivery claim; unused/missing account IDs fail existing routing/authorization | Existing review rules, no public-registration limiter |
| `POST /forgot-password`, existing / unused address | Synchronous framework password-reset notification / broker account-not-found result; no queue dispatch | Existing broker success/error messages, JSON 200/422 or web back with status/errors | Queue-dispatch outage **Not applicable**; response privacy outside this registration fix is unchanged | Existing broker cooldown |

Before the fix, the first row's failure was JSON **422** with `message`/`errors.registration`, or web **302** `/register` with registration errors. The existing-account row never dispatched and returned JSON **202** or web **302** with success status instead. These exact divergent responses caused the eight initial regression failures.

The fix reuses the 503 renderer already defined once in `bootstrap/app.php`; that renderer deliberately returns the same literal text body even for requests accepting JSON. No controller, exception renderer, UI or template change was needed. Dispatch exceptions are static and unchained; failed unused generations have null token hashes and no completion credentials. Existing accounts never create a registration row/token. No sent-at or queued-at marker was added. The ordinary cooldown remains; no permanent retry-blocking state was found in the simulated outage/recovery cases.

### Regression and compatibility evidence

- Replaced the old RER-03 characterization with `test_registration_returns_a_generic_retry_later_response_during_queue_dispatch_failure`: unused, resend, existing verified, existing unverified × web/JSON. **All 8 failed before the application fix** for explicit expected-503 vs actual 302/422/202 status differences. Tests assert the literal body and missing redirect/flash/errors individually, no accepted fake job or mail, invalidated unused token/session state, unchanged accounts, same cooldown, and normal success after restoring dispatch. Unused successful jobs send only through fake mail; no-email jobs send nothing.
- Updated the existing static-log regression to the 503 contract. Preserved all other normal-path assertions except the specifically authorized zero-job expectation for existing accounts; authenticated legacy verification still adds no further job.
- Extended the existing actual database-driver after-commit test with a fourth `existing` dataset. It proves rollback discards the no-email job, outer commit creates one database/default job despite `after_commit=false`, serialized properties are exactly `connection=database` and `tries=1`, and the empty handler changes no registration/account-notification state and sends no array-transport message.
- Added eight helper self-test checks. Before helper support, `no_email_job_allowed` and `no_email_job_has_no_delivery_target` failed. After support, **36/36 checks pass**. Additional properties, unsupported connection/tries, reserved/attempted/failed jobs remain blocked by the shape/reservation guards. No-email jobs have no expected recipient; any attempted SMTP message fails the existing before-send guard. No helper work/inspect/start/status/stop branch was run.

| Follow-up check | Result |
| --- | --- |
| Baseline before edits | **176 tests / 1,549 assertions passed** |
| Failing-first outage regressions | **8 failed**, all for the expected response-status divergence |
| Focused seven-file suite | **184 tests / 1,821 assertions passed** |
| Helper isolated self-test | **36 checks passed**, `mail_sent=false`, `jobs_created=false` |
| Full isolated PHP suite, once | **825 tests / 10,713 assertions passed**, exit 0, 198.6 seconds |
| Pint, five changed PHP files only | **Passed**, after the full suite |
| Temporary-storage cleanup | **Passed:** zero remaining test/cache-guard/isolation directories; sanitized temporary result file removed |

Current-task files: `app/Services/EmailRegistrationService.php`, new `app/Jobs/SkipRegistrationVerification.php`, `scripts/lan-mail-queue.php`, `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/Auth/RegistrationEmailReliabilityTest.php`, this document, `docs/local-lan-mail-queue.md`, and `docs/queued-registration-delivery.md`. Test harness, controller, renderer, decision-mail code and templates are unchanged by this follow-up. No migration was prepared/applied.

### Limits and restart requirement

Response uniformity is not complete timing protection: existing and unused requests perform different database/token work and serialize different job shapes. Shared rate limits still reflect prior traffic. Validation, authentication, legacy verification/password-reset behavior and failures outside dispatch retain their existing contracts; this is not a cross-endpoint privacy redesign. Real queue rejection/partial acceptance, SMTP, file-backed SQLite concurrency and running helper behavior were not exercised. Array/fake transports and single-connection fixtures do not prove exactly-once delivery or behavior under process loss.

Deploy the job, service and helper together, then gracefully restart the existing LAN helper **before resuming LAN registration testing**. An already-running helper retains its old allowlist and can stop on the new job shape. Use the existing `Stop`, `Status`, `Start`, `Status` operations in `docs/local-lan-mail-queue.md`; do not start a second worker or replay jobs. No restart or live inventory inspection was performed here. No owner decision remains necessary for RER-03; the separate decision-mail recovery/snapshot observations above remain outside this fix.

## Original audit changes and verification (before RER-03 follow-up)

| Changed file | Reason |
| --- | --- |
| `app/Services/EmailRegistrationService.php` | RER-01: apply existing transport safety check uniformly before account-existence shortcut. |
| `app/Mail/AccountDecisionMail.php` | RER-02: static, unchained delivery exception for standard queued decisions; no changed subject/template/retry metadata. |
| `tests/TestCase.php` | Isolate environment loading/key material as well as bootstrap/storage before boot; clear synthetic variables on teardown. |
| `tests/Feature/Auth/RegistrationEmailReliabilityTest.php` | 19 meaningful dataset cases, in-memory queue/failure/array-transport fixtures; no workers. |
| `docs/registration-email-reliability-audit.md` | This map, coverage, evidence and policy/operational limits. |

| Check | Result |
| --- | --- |
| Protected baseline before file edits | **157 / 1,251 assertions passed** |
| Regressions before application fixes | **4 failed**: two unsafe-config privacy cases, two sensitive-exception cases |
| Same four after fixes | **4 / 42 assertions passed** |
| Focused regression run with first 16 added cases | **173 / 1,519 assertions passed** |
| Additional captured-recipient deletion/change cases | **3 / 30 assertions passed** using array transport only |
| Existing LAN helper `--self-test` | **28 guard scenarios passed**, `mail_sent=false`, `jobs_created=false`; before any live-config branch |
| Pint, four changed PHP files only | **Passed** |
| Full isolated PHP suite after fixes/focused checks | **817 tests / 10,441 assertions passed**, exit 0, 346.9 seconds |
| Temporary-storage cleanup | **Passed:** zero remaining `rlcp-tests-*`, `rlcp-cache-guard-*` or `rlcp-isolation-*` directories. One verified October 18 test-harness leftover was removed after checking its exact temporary path and rejecting reparse points; the sanitized temporary result file was also removed. |

The initial final-suite session result became unavailable after the conversation resumed on October 19. No test process remained, and no reliable completion result could be recovered. The isolated full-suite command was repeated once solely to obtain a recorded exit code and summary; no PHP files changed between those runs. The completed result above is from that repeat, not an inferred result from the lost session.

Reproduction after persisted harness hardening:

```powershell
$taskRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
& (Join-Path $taskRuntime 'php.exe') -c (Join-Path $taskRuntime 'registry.ini') -d memory_limit=512M vendor/bin/phpunit --do-not-cache-result
& (Join-Path $taskRuntime 'php.exe') -c (Join-Path $taskRuntime 'registry.ini') vendor/bin/pint --test app/Services/EmailRegistrationService.php app/Mail/AccountDecisionMail.php tests/TestCase.php tests/Feature/Auth/RegistrationEmailReliabilityTest.php
```

No migration was prepared/applied; new pre-flight query and rollback are **Not applicable**.

## Remaining operational limits

- Real SMTP delivery/acceptance/inbox receipt, running worker behavior, LAN configuration parity, live schema, live `jobs`/`failed_jobs`, and production helper behavior remain **unverified by design**. No worker start/stop, queue work/listen, live helper inspection, real send or real record/job/file operation occurred.
- Source defaults are documented, not asserted as live settings. The application cache and `.env` were neither read nor altered. All database driver exercises and failer writes used the guarded memory database; all transport attempts were fake or array-only.
- True multi-process/file-backed SQLite behavior, cache eviction/shared-cache behavior, timeouts/process crashes and automatic failed-job lifecycle events were not reproduced. Custom `failed()` hooks are absent; framework failure plumbing was inspected and safe persistence exercised directly, not by a worker loop.
- Legacy existing-account verification remains synchronous. Fake notification dispatch/limits, signed expiry and existing verification tests passed; synchronous transport failure sanitization is not fully covered here. No claim of universal failure privacy or timing protection is made. Password-reset responses and other endpoints were not changed by the RER-03 fix.
- Long-running workers must be restarted through the normal authorized operational process to load changed PHP classes. No restart was performed. No production deployment or live browser verification is claimed.
- Real data/configuration, LAN caches, worker processes, SMTP, APP_URL, mail driver settings, UI/page/email templates/assets, dependencies, permissions, approval policy and retry rules were unchanged. The only normal processing exception is the explicitly authorized no-email job. No outbox, new format/route/notification type, automatic resend, scheduler, recovery command or claim reset was introduced.
