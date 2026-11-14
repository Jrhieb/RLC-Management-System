# Render and Supabase staging task tracker

Updated: 2026-11-13 (Asia/Manila).

Scope: finish the Render Free staging deployment, verify hosted Laravel behavior, and prepare any later data import or production release. This is not a whole-repository audit. Unchecked items are pending; listing them does not authorize data imports, production changes, or paid resources.

## Current baseline

Previously reported by the user, not independently repeated during Render preparation:

- Supabase staging connectivity, required SSL, and the private `laravel` schema passed checks.
- All 20 migrations ran; 15 application tables plus `migrations` exist.
- Database-role restrictions and live Data API exclusion passed.
- 38 application checks and 23 Edge browser checks passed using synthetic data; cleanup restored the baseline.

Verified during local Render preparation:

- Existing Dockerfile, Caddyfile, and Docker exclusions were inspected and reused.
- `render.yaml` selects a Free Docker web service named `rlc-staging`, Singapore region, manual deployments, required database SSL, and `DB_SEARCH_PATH=laravel`.
- Hosted `APP_ENV=production` retains existing production safeguards; the service and database target are staging.
- `config/database.php` supports `DB_SEARCH_PATH` while retaining `public` as the PostgreSQL default when unset.
- Two schema-configuration tests passed; Pint, YAML parsing/settings checks, and the frontend build passed.
- SQLite, local `.env`, `.env.staging`, and the configuration cache were unchanged by hash comparison.

The Render changes remain local and uncommitted. No Render deployment or container build has been verified. Render account access is not connected in this session. No imports ran.

## P0 — Required to bring hosted staging online

### 1. Publish only reviewed deployment changes to GitHub

- [ ] Review `render.yaml`, `config/database.php`, and `tests/Unit/DatabaseSchemaConfigurationTest.php` together.
- [ ] Confirm which branch Render should deploy; the current Blueprint selects `main`.
- [ ] Keep unrelated staged and unstaged work out of the deployment commit. Do not use a blanket `git add .`.
- [ ] Verify that the selected revision includes the existing Dockerfile, Caddyfile, `.dockerignore`, and matching dependency lock files.
- [ ] Commit and push the scoped changes through the project's normal integration process.

Completion: the intended deployment revision is available on GitHub, reviewed, and contains no credentials. Any unrelated local frontend changes remain outside scope unless explicitly selected for release.

### 2. Connect Render and create the staging service

- [ ] User signs in to Render and grants access to `Jrhieb/RLC-Management-System`, or connects the Render integration for assisted setup.
- [ ] Check for an existing staging service before creating another.
- [ ] Create the service from the reviewed Blueprint and confirm Docker, Free compute, Singapore region, and the intended branch.
- [ ] Confirm manual deployments, `/up` health checks, and no additional paid databases, workers, or disks.

Completion: Render can access the intended repository and the service configuration matches the reviewed Blueprint. Account access is a user action; no secrets should be pasted into chat.

### 3. Configure private hosted environment values

- [ ] Provide a stable, dedicated staging `APP_KEY` through Render's environment settings. Do not regenerate it on each deploy or reuse a production key.
- [ ] Set `DB_URL` to the already-tested Supabase staging Session Pooler connection string privately.
- [ ] Verify the effective host/database identifies staging, `DB_SEARCH_PATH=laravel`, `DB_SSLMODE=require`, and `APP_DEBUG=false`.
- [ ] Verify `APP_URL` resolves to the actual Render HTTPS address; set it explicitly if the Blueprint reference does not resolve.
- [ ] Confirm database sessions/cache, secure cookies, proxy trust, and the staging session-cookie/domain configuration.
- [ ] Confirm `.env.staging`, SQLite files, cached local configuration, and other private files are excluded from the Docker image.

Completion: the hosted configuration targets the private staging schema and supports HTTPS without exposing credentials. Render supplies environment variables; the local `.env.staging` file is not uploaded.

### 4. Complete the first Docker build and deployment

- [ ] Build the image on Render or another available Docker runtime; inspect dependency installation, frontend asset compilation, and runtime startup.
- [ ] Confirm the server binds to Render's assigned port and `/up` is healthy.
- [ ] Confirm the home page and built CSS/JavaScript load over HTTPS.
- [ ] Verify database access and migration status without rerunning completed migrations or importing data.
- [ ] Review sanitized application and deployment logs for startup failures or leaked sensitive values.

Completion: Render reports a healthy deploy and the application is reachable. Local frontend build success alone does not prove the container or hosted runtime works.

## P1 — Required before calling hosted staging validated

### 5. Verify hosted HTTPS, sessions, and authorization

- [ ] Use a controlled synthetic test-account setup; do not bulk-run seeders or expose a permanent setup endpoint.
- [ ] Test login/logout, session persistence, secure cookies, CSRF, proxy redirects, and generated HTTPS links.
- [ ] Test role, approval, email-verification, and assigned-record restrictions on the hosted instance.
- [ ] Recheck beneficiary, activity, attendance, and assistance browser interactions with synthetic records only.
- [ ] Confirm expected validation/authorization responses and no unexpected JavaScript or HTTP failures.
- [ ] Remove only test-owned records and sessions; compare staging data with the pre-test baseline.

Completion: the hosted user flows pass with cleanup evidence, without resetting or truncating the shared database.

### 6. Make an explicit email-delivery decision

- [ ] Keep `MAIL_MAILER=array` during the initial deployment: it discards email and does not prove delivery.
- [ ] If registration, password reset, or verification delivery is required, select an email service supported over HTTPS and review existing installed transports before adding dependencies.
- [ ] Configure provider credentials and sender verification privately.
- [ ] Test intended delivery and failure handling using controlled recipients; prevent accidental delivery to real beneficiaries.
- [ ] Decide which work can remain synchronous and whether any queued work actually needs a worker.

Completion: either email-dependent features remain explicitly out of the staging test scope, or controlled end-to-end delivery works. Render Free blocks common SMTP ports, so the previous port-587 setup cannot be assumed to work.

### 7. Resolve persistent file storage if uploads are in scope

- [ ] Identify which workflows write uploads or generated documents to local storage.
- [ ] For disposable synthetic tests, record that local files may disappear on restart, redeploy, or idle spin-down.
- [ ] Before keeping real uploads, configure durable object storage, access permissions, and the application's disk settings.
- [ ] Verify upload, authorized retrieval, and deletion; verify survival after redeploy.
- [ ] Review the missing `storage:link` in the existing container if public-disk URLs are used. A symlink alone does not make storage persistent.

Completion: tested workflows either use durable storage or are explicitly limited to disposable files. Supabase PostgreSQL stores application records; it does not automatically persist Laravel's local uploads.

## P2 — Required before importing existing records or relying on untested features

### 8. Plan and validate the SQLite-to-PostgreSQL import, if needed

- [ ] Confirm whether existing SQLite records need to be copied at all.
- [ ] Create and verify a recoverable source backup and inspect the staging destination before any import.
- [ ] Define table order, ID preservation, foreign-key handling, timestamp/time-zone mapping, booleans, constraints, and conflict rules.
- [ ] Review the caseworker-approval migration behavior against imported account data.
- [ ] Define treatment of users, encrypted fields, sessions, queues, cache, and credentials. Identify values tied to the source `APP_KEY` without copying secrets into reports.
- [ ] Prepare a dry-run/rehearsal and recovery strategy; verify counts, relationships, representative records, and PostgreSQL ID sequences.
- [ ] Obtain explicit approval of the concrete import procedure before transferring existing records.

Completion: a reviewed, reproducible import procedure with validation and recovery steps. No production cutover is implied.

### 9. Test remaining feature and operational gaps

- [ ] Test password reset, 2FA, and passkeys where enabled, after required hosted/email setup exists.
- [ ] Test simultaneous attendance check-ins and assistance submissions for duplicate/conflict handling.
- [ ] Test physical NFC hardware through its real supported connection path; browser test UIDs do not verify hardware.
- [ ] Test any required queue jobs and scheduled tasks, including retry/failure behavior. Do not add infrastructure for unused features.
- [ ] Add another browser/device check based on actual intended users.

Completion: each feature has evidence of a pass or an explicit remaining limitation; no blanket production-readiness claim.

## P3 — Polish and release preparation

### 10. Address existing build and asset warnings

- [ ] Investigate the unresolved `/images/homepage/placeholders/hero.svg` reference if used by the deployed page.
- [ ] Decide whether the optional optimized font-fallback warning needs action; do not add a dependency without approval.
- [ ] Check favicon/logo loading in the hosted browser and fix actual failures if reproduced.

Completion: material visual/asset failures are resolved; harmless warnings are documented without unnecessary dependency changes.

### 11. Record operating limits and maintenance procedure

- [ ] Record the final service, branch, deployed commit, verified HTTPS address, and sanitized verification evidence.
- [ ] Document manual deploy and rollback procedures, distinguishing a code rollback from database recovery.
- [ ] Record Free-plan idle sleep, cold starts, ephemeral files, and usage limits; check the dashboard for current account limits.
- [ ] Define who reviews deployment errors and usage, and how credentials are rotated privately.

Completion: another maintainer can understand and operate staging without access to this conversation or plaintext secrets in the repo.

### 12. Prepare production separately, only when requested

- [ ] Choose the production hosting/resources, cost limits, database, durable storage, and mail configuration.
- [ ] Plan backups, restore verification, migration execution, data cutover, and monitoring.
- [ ] Repeat applicable HTTPS, authorization, delivery, and operational checks against the production configuration.
- [ ] Obtain approval for the concrete production release and any paid resources before proceeding.

Completion: production has its own reviewed release plan. A working free staging deployment is not a production launch.

## Evidence and completion log

For each completed task, append the date, selected commit/service when relevant, checks run, sanitized results, remaining limitations, and cleanup outcome. Never record `APP_KEY`, database passwords, full connection strings, API secrets, session tokens, or real beneficiary data.

### Hosted deployment follow-up — 2026-11-13

- User's Render screenshot reports deployment `98a3e52` as Live. The same commit is present in the local checkout. This supersedes the initial local-only deployment status above; account settings and container-build logs have not been independently inspected.
- User reports HTTP 500 on the hosted home page. Hosted application verification remains incomplete even though Render reports Live.
- Supplied runtime log contains `Cannot modify header information - headers already sent`, with output beginning in Symfony's `Response::sendContent()` and the exception stack passing through Laravel's shutdown handler.
- Local source inspection confirms that this stack involves an attempt to render another exception response after response content was sent. It does not identify the initial cause of the home-page failure.
- Next evidence needed: the first Laravel error and preceding log lines for the same failing request. Keep `APP_DEBUG=false`; check effective `LOG_CHANNEL=stderr` and `LOG_LEVEL=error` if application errors are missing from Render logs.
- No application code, environment values, database records, or dependencies were changed as part of this investigation. No migrations or resets were run.

### Confirmed encryption configuration failure — 2026-11-14

- User-supplied runtime trace at 14:21:32 UTC reaches `EncryptionServiceProvider::key()` through its `tap()` callback before the secondary response-header failures. Inspection of the installed provider confirms that this callback throws `MissingAppKeyException` when the effective `config('app.key')` is empty. This identifies an empty effective key, rather than the malformed-key/cipher validation path.
- The trace also reaches `Kernel::terminateMiddleware()` after response content was sent, explaining why another attempt to render the encryption exception triggers `headers already sent`.
- `config/app.php` reads `APP_KEY` and uses AES-256-CBC. The Dockerfile does not generate keys or run `config:cache`; `.dockerignore` excludes private `.env*` files and `bootstrap/cache/*`. Local credentials are intentionally not uploaded.
- Render account access remains unavailable. It is not possible to distinguish an absent/empty hosted variable, an unapplied environment edit, the wrong service/deployment, or an unexpected runtime configuration cache from this trace alone. No key was generated, rotated, displayed, or changed.
- Minimum dashboard action: in the existing `rlc-staging` service, verify the exact `APP_KEY` variable contains the existing valid dedicated staging key, then select Save and deploy if the corrected environment has not been applied. Preserve an existing valid key. Verify the active deployment after that change; do not repeatedly redeploy or change vendor response handling.
- If a new request still reaches the same key callback after that deployment, inspect effective configuration and configuration-cache presence privately through authenticated service access. Do not expose a diagnostic endpoint or print credentials.
- Hosted homepage, assets, HTTPS redirects, sessions, and login remain unvalidated. No application source changes, deployment, database changes, or test-account writes were performed for this diagnosis.

### Application release verification — 2026-11-14

- User authorized publishing the latest public pages, staff login dialog, registration code flow, and required public images to `main`. Architecture artifacts, generated QA screenshots, README/discovery documents, the unrelated Supabase CLI dependency addition, and root image deletions remain outside the release.
- Release review found that failed logins submitted from a public-page dialog returned to a closed dialog. Validation failures on `login.store` now redirect to the existing login screen, retaining the email/error and excluding the password. A regression test reproduced the old redirect and passes with the fix; login throttling remains enforced.
- Verification passed: Pint; frontend build; 152 affected PHP tests with 2,430 assertions; 144 JavaScript tests; and 26 isolated Edge browser checks. Browser checks intercept requests and use synthetic memory fixtures. Their allowlist now serves the installed Livewire script and locally generated bootstrap assets, and their simulated nonstandard HTTP response includes an explicit reason phrase. No live accounts or mail delivery were used.
- The initial feature run overlapped an asset rebuild and temporarily failed to find a font file. The subsequent complete affected-test runs passed after the build. The initial browser run exposed fixture transport/session-continuity problems; these were corrected and all browser checks then passed.
- Build warnings remain: optional optimized font fallbacks lack the optional `fontaine` package, and the root-relative hero SVG is resolved at runtime. The SVG exists and is tracked; neither warning prevented the successful build.
- Integrity hashes confirm `.env`, `.env.staging`, and `bootstrap/cache/config.php` are unchanged. The local SQLite file hash changed during the verification window while other local PHP processes were active. Static review found no SQLite write path in the Node asset build, and feature tests guard SQLite `:memory:` before provider boot. The writer is not established; the database was not restored, reset, or overwritten. SQLite byte-for-byte preservation therefore cannot be claimed.
- The plugin registry now reports Render installed, but this conversation still exposes no Render service/deploy tools. Account authorization, hosted environment values, and the active deployed revision cannot be inspected here. Installation alone does not establish working account access.
- Required connection step: start a new Codex thread after plugin installation, invoke Render `list_workspaces`, and approve the browser OAuth prompt if requested. Select the workspace containing the existing Free `rlc-staging` service. Do not create another service or rotate its key. Render documents this initialization at https://render.com/agents/codex-desktop.
- Hosted deployment remains pending authenticated access. Preserve a valid hosted staging key; if its effective configuration is empty, apply the existing valid dedicated staging key privately. The local `.env.staging` key was validated for the configured cipher without displaying it. Do not upload environment files. Recheck homepage/assets, HTTP-to-HTTPS redirects, secure session persistence, and sanitized runtime logs after deployment; `/up` alone is insufficient.

## References

- Render Free service limits: https://render.com/docs/free
- Render Blueprint configuration: https://render.com/docs/blueprint-spec
- Supabase Laravel setup: https://supabase.com/docs/guides/getting-started/quickstarts/laravel

## Verified staging recovery � 2026-11-15 (Asia/Manila)

- Authenticated Render access verified in confirmed My Workspace; exact service `rlc-staging` (`srv-dbrcj6l6e07s73b8stl0`), existing Free Docker service, branch `main`.
- Confirmed runtime failure: PostgreSQL connection refused at `127.0.0.1:5432`. Running configuration was falling back to localhost instead of the existing Supabase staging database.
- Merged existing private `.env.staging` Supabase `DB_URL` securely into Render; set `DB_CONNECTION=pgsql`, `SESSION_CONNECTION=pgsql`, `DB_SEARCH_PATH=laravel`, `DB_SSLMODE=require`, `APP_DEBUG=false`, and the actual staging HTTPS `APP_URL`. Existing `APP_KEY` was omitted from the merge and preserved. No database migration or data operation was required.
- Render automatically deployed the configuration update as `dep-dbs90iuk1f9s738kfm10`, live at 2026-11-14 16:46:43 UTC, using existing GitHub commit `e1e85942cb3654b003b97ed0e0a68b44057308dd`. No application code change was needed.
- Verified `/up`, homepage, and database-session `/login`: HTTP 200. Seven referenced build assets (fonts, CSS, JavaScript) returned 200. Login CSRF token persisted across requests with the same cookie jar; session cookies were Secure. HTTP redirected to HTTPS.
- Queried application `production.ERROR` logs from the corrected deployment's live timestamp after verification requests; no matching new errors returned.
- Remaining limits: no authenticated staff workflow exercised; no claim of full migration inventory or cache-table audit. Successful database-backed sessions demonstrate connectivity and session-table access for the verified requests.

## Brevo HTTPS registration delivery � setup and verification

- Laravel mail transport `brevo` sends existing rendered mail through `https://api.brevo.com/v3/smtp/email` over HTTPS. No dependencies added. Requires HTTP 201 and nonempty messageId; acceptance is not proof of inbox delivery. Failures expose static errors only, with no provider body or chained sensitive exception. No automatic retry after ambiguous acceptance.
- Registration jobs honor QUEUE_CONNECTION; staging uses sync because the Free web service has no queue worker. Failure invalidates the current registration generation and returns the existing unavailable response. Existing code/link expiry, one-time use, rate limits, and Director review remain unchanged.
- Brevo setup: verify an owned sender in Settings > Senders, Domains, IPs > Senders; confirm Transactional > Email is enabled (request activation from Brevo support if disabled); create an API key under SMTP & API > API Keys, not an SMTP key.
- Enter BREVO_API_KEY privately in the existing Render service Environment. Set MAIL_FROM_ADDRESS to the verified sender and MAIL_FROM_NAME to RLC Management System; MAIL_MAILER=brevo and QUEUE_CONNECTION=sync. Preserve APP_KEY, database settings, and APP_DEBUG=false. Never place the key in Git or chat.
- Without a custom domain, Brevo may rewrite a free-address sender using a compliant Brevo domain. This is a temporary provider behavior, not assurance of account eligibility or delivery. The onrender.com website hostname is not an owned sending domain.
- Automated checks: transport and existing registration tests cover success, unauthorized/sender rejection/rate-limit/server errors, malformed acceptance, connection failure, no network with missing key, sync success and failure, expiry, invalid/reused codes, and resend limits. All requests in these tests are faked and SQLite is isolated in memory.
- Pending real verification: user must confirm Brevo transactional activation, verified sender, secure API-key entry, and provide an owned test recipient. Must then confirm actual inbox receipt, verify through the existing code/link flow, resend within limits, inspect fresh sanitized logs. Do not treat provider acceptance alone as successful delivery.
- Official references: https://developers.brevo.com/reference/send-transac-email ; https://help.brevo.com/hc/en-us/articles/115000188150-Troubleshooting-Issues-with-Brevo-SMTP ; https://help.brevo.com/hc/en-us/articles/14925263522578-Comply-with-Gmail-Yahoo-and-Microsoft-s-requirements-for-email-senders
