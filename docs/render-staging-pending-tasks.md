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

## References

- Render Free service limits: https://render.com/docs/free
- Render Blueprint configuration: https://render.com/docs/blueprint-spec
- Supabase Laravel setup: https://supabase.com/docs/guides/getting-started/quickstarts/laravel
