Email-first registration is complete. A guest submits an email, receives a single-use link valid for 30 minutes, and verifies it to establish a separate 15-minute registration session. Complete Registration collects name, password, and password confirmation. The email comes from the verified request; the server explicitly assigns `role=caseworker`, the verification timestamp, and `approval_status=pending`. Successful completion shows “Your account is awaiting Director approval.” and leaves the guest unauthenticated.

Verification and registration session tokens are hashed in the request table. A resend atomically replaces the previous link and revokes any verified session. Verification and completion use conditional database updates; completion consumes the session and creates the user in one transaction. The existing unique email constraint and a case-insensitive lookup protect existing accounts. Failed creation rolls back the claim. Existing password rules, Flux authentication styling, mobile sizing, labels, error announcements, and light/dark colors are reused.

Requests and resends share limits of one per minute and five per hour per normalized email, plus ten per minute and thirty per hour per IP. Verification and completion also have IP limits. Standard Fortify registration remains disabled, with its unused registration view/action bindings removed. The six custom routes are unique. Existing account and login regressions pass.

Verification URLs are confined to email content. Registration responses use `no-store` and `no-referrer`; session URL tracking stores the registration entry URL rather than a bearer link. Validation redirects target the appropriate form explicitly. Unexpected registration failures produce generic responses and log only a static message and exception class. Mail transport exceptions are not reported. Verification mail now rejects log transports in every environment, including composite fallbacks, to keep bearer links out of logs.

October 16 update: [verification delivery now runs through the existing database queue after commit](queued-registration-delivery.md). The form provides accessible immediate feedback and truthful queued confirmation; the LAN helper supports both verification and existing decision mail. No schema change is required. The original rollout files, validation results, and deployment observations below are historical; use the linked update and current queue runbook for operation.

Changed source and test files:

| File | Change |
| --- | --- |
| `app/Services/EmailRegistrationService.php` | Issue, verify, revoke, and atomically complete requests; guard mail transport. |
| `app/Http/Controllers/RegistrationController.php` | Guest flow, form validation, session lifecycle, and confirmation. |
| `app/Models/RegistrationRequest.php` | Request timestamps and hidden token hashes. |
| `app/Mail/RegistrationVerificationMail.php` | Existing verification email, now rendered/sent inside the queued worker. |
| `app/Http/Middleware/ProtectRegistrationResponse.php` | Registration response privacy headers. |
| `app/Http/Middleware/RegistrationSafeSession.php` | Prevent session URL tracking from persisting verification links. |
| `app/Providers/FortifyServiceProvider.php` | Registration rate limits and removal of unused Fortify registration bindings. |
| `bootstrap/app.php` | Register middleware and safe registration exception handling. |
| `routes/web.php` | Six custom guest routes; Pint also normalized controller imports. |
| `database/migrations/2026_10_09_000001_create_registration_requests_table.php` | New request table only; migration authored but not run against a real database. |
| `resources/views/livewire/auth/register.blade.php` | Reuse the existing registration view for email entry and resend. |
| `resources/views/livewire/auth/complete-registration.blade.php` | Verified email display and completion fields. |
| `resources/views/livewire/auth/registration-awaiting-approval.blade.php` | Approval confirmation without automatic login. |
| `resources/views/livewire/auth/login.blade.php` | Link to the custom registration flow. |
| `resources/views/mail/registration-verification.blade.php` | Verification email template. |
| `tests/TestCase.php` | Reject unsafe database and infrastructure configuration before migration hooks; fake mail and notifications. |
| `tests/Feature/Auth/RegistrationTest.php` | Replace skipped starter tests with 47 email-first flow/security cases. |
| `docs/email-first-registration.md` | Change, validation, deployment, and mail setup report. |

The asset build regenerated `public/build/manifest.json`, `public/build/fonts-manifest.json`, and the files under `public/build/assets/`. No frontend source, package manifest, lockfile, approval screen, notification feature, caseload logic, or NFC logic was changed.

Validation completed using the existing PHP 8.4.25 runtime and installed project dependencies:

| Check | Result |
| --- | --- |
| Focused registration, authentication, approval foundation, and existing caseworker account creation tests | 125 passed; 1,100 assertions. |
| Registration cases | Expiry, replay, resend invalidation, session expiry, bypass, tampering, duplicate emails, interleaved completion, forced role, and verified pending creation passed. |
| Database/email isolation | Shared test bootstrap rejects anything except SQLite `:memory:` with isolated cache/sessions/queues and fakes mail before database refresh. |
| Concurrency | Deterministic competing submissions were interleaved inside the guarded memory database; no external database or worker was used. |
| Pint on the 12 changed PHP files | Passed. |
| `php artisan route:list --path=register --json` | Six distinct custom routes; no standard Fortify registration POST route. |
| `npm run build` | Passed. The existing font plugin emitted an optional `fontaine` optimization warning; no dependency was added. |
| Effective mail configuration | Environment `local`, mailer `log`, transport `log`; log-only, no inbox delivery. |

Focused tests can be rerun with PHP 8.4.1 or newer:

```powershell
php vendor/bin/phpunit tests/Feature/Auth tests/Feature/UserApprovalFoundationTest.php tests/Feature/CaseworkerAccountCreationTest.php --no-progress
npm run build
```

Deployment commands below were not executed. The approval foundation migration must already be deployed. Apply only the new request-table migration before enabling this code for guests, then refresh deployment caches:

```powershell
php artisan migrate --path=database/migrations/2026_10_09_000001_create_registration_requests_table.php --force
php artisan config:cache
php artisan route:cache
```

Actual email delivery still needs a configured delivery provider. For the existing SMTP transport, set `MAIL_MAILER=smtp` and supply the provider's `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, and `MAIL_PASSWORD` through the deployment's secret/configuration mechanism. Set an authorized `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`, and set `APP_URL` to the application's public HTTPS origin. Refresh configuration after setting these values. Do not select the default failover transport in a non-local environment while it includes the log fallback: registration refuses any transport chain that could log a link there.

No real database migrations, real email deliveries, or dependency installations were performed. Delivery testing against the actual provider remains for deployment setup.
