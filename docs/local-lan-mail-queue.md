Local Windows LAN account-email queue operation, verified October 16, 2026.

The existing review service commits one account decision and one stored notification, then queues the existing `AccountDecisionMail` to the registered address. Its event key prevents repeated reviews from creating another decision notice or mail. Approval and rejection use that same implementation. [Registration verification is now queued after commit](queued-registration-delivery.md), using the existing verification mailable and request table. Account permissions, review routes, and stored notification behavior are unchanged.

Verified configuration: PHP **8.4.25**, the existing `registry.ini`, database queue connection **database**, table **jobs**, queue **default**, reservation interval **90 seconds**, and Gmail SMTP on port **587**. SMTP credentials are configured and were not displayed or changed. Before switching from the test scope, pending and failed queues were both empty. Earlier approval job #4 had already completed with an observed SMTP 250 reply, and its recipient confirmed receipt. It was not recreated or processed again.

Run these commands in PowerShell from `C:\rlcsystem\app`:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\lan-mail-queue.ps1 -Action Inspect
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\lan-mail-queue.ps1 -Action Start
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\lan-mail-queue.ps1 -Action Status
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\lan-mail-queue.ps1 -Action Stop
```

The execution-policy option applies only to the launched PowerShell process; it does not change Windows or user execution-policy settings.

`Start` launches one hidden background PHP process using the verified runtime and ini, without relying on `php` being on PATH:

```powershell
$queueRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
# This is the PHP entry point used by Start. Use the PowerShell helper to
# check existing workers and avoid running this alongside another worker.
& (Join-Path $queueRuntime 'php.exe') -c (Join-Path $queueRuntime 'registry.ini') .\scripts\lan-mail-queue.php --work
```

The PowerShell helper rejects a duplicate worker process and serializes concurrent startup commands with a named mutex. The PHP process also holds an exclusive file lock for its entire lifetime. It verifies the runtime/version/configuration on each startup and refuses to start if the runtime has been removed from the temporary directory. Status checks actual process existence, independently of the last saved state, so an old state file after reboot does not establish that a worker is running.

The processing scope is **account_decisions_and_registration_verification**. All registered Caseworkers are eligible for their existing approval/rejection email when the saved decision matches. Pending accounts, Directors, unregistered recipients, and mismatched decisions remain excluded from decision mail. The helper also supports `SendRegistrationVerification` for current public registration requests and the owner-authorized `SkipRegistrationVerification` for already-existing addresses. The latter carries only database-connection/one-try metadata, has no recipient or token, and performs no account changes or delivery. The old test-recipient restriction remains removed. Account access rules are unchanged.

The adapter reuses Laravel's worker, database job, queued handler, and failed-job provider. Before each reservation, it checks all queued jobs in the same transaction. It processes `AccountDecisionMail`, `SendRegistrationVerification` and `SkipRegistrationVerification` jobs on `default`. Immediately before SMTP, it checks the actual recipient/subject against the reserved job and rechecks the saved decision or current unconsumed registration generation. Recognized obsolete verification jobs and no-email jobs finish without sending instead of blocking startup. No-email jobs increment processed/skipped, emit `registration_skipped_without_email` with only the job ID, and never increment SMTP acceptance. Their expected delivery target is always null, so the before-send guard rejects any unexpected message. Processing does not create a job or repeat a review.

Unrelated job classes/recipients, CC/BCC, chains, custom middleware, already-attempted jobs, and reserved jobs remain blocked. Any such queued row stops this helper before reservation, even if eligible decision mail is also waiting. Its numeric job ID is reported for review; it is not deleted, retried, or processed. Failed jobs are inspected but never retried or deleted by startup. This conservative guard is needed because decision mail currently shares `default` with other work.

For this local worker only, each job receives one attempt. A failed send is recorded as failed using the existing failed-job provider, with generic exception details, without automatically creating a retry job. The original mailable's three-attempt production configuration stays unchanged. Completed jobs are never replayed. An interrupted/reserved job requires inspection before this helper will run again.

`Stop` writes a stop request for this local worker. An idle worker normally exits within a few seconds. A running job finishes before the worker stops; the command waits up to ten seconds, after which `Status` can show whether it is still finishing. It does not kill another worker or broadcast `queue:restart` to other projects.

Because `Start` creates a background process, closing the launching terminal normally leaves it running. Signing out, shutting down, or restarting Windows stops it. There is no Windows service, scheduled task, or automatic boot startup. Run `Start` again after sign-in. Jobs remain in the database while the worker is stopped. Use graceful `Stop` before shutdown when possible: an interrupted send may already have been accepted by SMTP, so reserved/attempted jobs must be reviewed instead of resent automatically. If the PHP entry point is run directly in a terminal, keep that terminal open; use the helper's graceful stop command from another terminal.

This Windows runtime has no `pcntl`, so Laravel's nominal 60-second job timeout is not a hard process alarm. The existing SMTP socket timeout is 60 seconds. The helper does not add a process killer or supervisor; a stop request waits for the current send to return.

Status and privacy-safe event records are under `storage/framework/lan-mail-queue/`:

| File | Purpose |
| --- | --- |
| `state.json` | Worker PID, scope, heartbeat, current job ID, and processed/success/skipped/failure/SMTP counts for this run. Use `Status` to confirm the process exists. |
| `events.jsonl` | Job IDs, lifecycle outcomes, skipped verification jobs, and numeric SMTP reply codes only. SMTP 250 records acceptance, not Inbox/Spam receipt. |
| `worker-*.out.log` / `worker-*.err.log` | Background process output; errors produced by this helper omit payload and transport details. |

SMTP debug is inspected only in memory to extract its final numeric reply and is not saved. No credentials, recipient addresses, tokens, signed links, email bodies, or queued payloads are printed in inspection/status/events. `Inspect` reports pending/failed counts and sanitized per-job classifications. A missing numeric reply means acceptance was not captured; a successful job alone does not prove Inbox/Spam receipt. Receipt of each new message remains for its recipient to confirm.

Focused scope validation uses only a synthetic SQLite `:memory:` database and serialized in-memory mail objects, without application bootstrap, mail transport, persisted jobs, or changes to real accounts:

```powershell
$queueRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
& (Join-Path $queueRuntime 'php.exe') -c (Join-Path $queueRuntime 'registry.ini') .\scripts\lan-mail-queue.php --self-test
```

The 36 checks cover approved/rejected Caseworkers, excluded decision recipients, mismatched decisions, recipient rechecking, CC/BCC, multiple recipients, other mailers, chains/middleware, unrelated job classes, attempted/reserved/failed jobs, current verification, obsolete verification states, and no-email job recognition/minimal metadata/absent recipient plus connection/retry/reservation guards. Syntax and Pint are checked separately. No persisted test job, account, or email is created by these checks.

The PowerShell wrapper is unchanged by queued registration. Its PHP adapter and this runbook now support the new job. See [queued registration delivery](queued-registration-delivery.md) for application changes, tests, restart requirements, and manual checks. `.env`, cached configuration, SMTP settings, dependencies, and schema are unchanged.

October 19 follow-up: public registration now performs a real after-commit dispatch for both unused and existing addresses. Existing addresses enqueue only the no-email job, so queue-dispatch exceptions can return the same truthful generic 503 response for both address types. Normal responses and rate limits are preserved. See [RER-03 evidence and limits](registration-email-reliability-audit.md#rer-03-follow-up-owner-authorized-no-email-jobs-october-19); uniform responses do not establish complete timing-side-channel protection.

Deploy the service, job class and PHP helper together. **Gracefully restart the existing helper before further LAN registration testing** using `Stop`, then `Status` until stopped, `Start`, and `Status`. A running helper retains its previous allowlist and may stop on the no-email job until restarted. Duplicate-worker protection and graceful-stop behavior remain unchanged. This implementation step did not inspect live queues, restart workers, process jobs, alter configuration, or send email.
