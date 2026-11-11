Director account review and approval access protection are complete. The implementation reuses the approval fields, eligibility helpers, and email-first registration described in [email-first-registration.md](email-first-registration.md). No new migration or dependency is required by this task.

Account-request and approval-decision notifications were added in a later scoped task. Their current behavior, migration, validation, and mail/queue prerequisites are documented in [account-notifications.md](account-notifications.md). The review-foundation checks and deployment history below remain the record of the earlier task.

Verified Directors have an Account Requests sidebar entry and page at `/account-requests`. The page lists Caseworker names, emails, request dates, approval statuses, review dates, and rejection reasons. It defaults to pending requests and offers approved, rejected, and all-status filters with pagination. Only pending accounts have Approve/Reject confirmation dialogs. Rejection requires a trimmed reason of at most 2,000 characters. Validation returns to the selected filter and page and reopens the affected rejection dialog. The layouts use the existing Flux components, responsive sizing, labels, dialog names, error/status announcements, and light/dark styling.

Each decision uses one conditional update inside a database transaction, matching the account ID, `role=caseworker`, and `approval_status=pending`. Status, reviewer ID, review timestamp, and rejection reason are saved together. A repeated or competing decision cannot overwrite a completed review; JSON callers receive HTTP 409 and browser callers receive accessible feedback. Extra client fields cannot change roles, emails, verification state, or reviewer metadata. Approval never changes beneficiary assignments. The later notification extension stores the recipient's decision notification transactionally and queues decision mail after commit.

Pending and rejected Caseworkers can still authenticate. Password login, 2FA/recovery login, passkey login, and email-verification responses lead browser users to their own account-status page and clear a stale intended staff URL. Existing JSON authentication response formats are preserved; passkey login returns the status URL in its existing `redirect` field. Approved Caseworkers and Directors retain the original authentication responses and intended redirects.

A web middleware restricts pending/rejected users to status, logout, and the existing security and verification routes. Browser requests to other pages redirect to status; JSON and Livewire requests are denied with HTTP 403. Staff routes additionally require authentication, verified email, and an approved Caseworker or Director role, including beneficiary photos, fragments, downloads, and JSON endpoints. Directors' access remains independent of their approval field. The status route sends approved users to the dashboard or email verification, avoiding status/verification redirect loops.

Livewire replays the approval, staff-access, verification, and password-confirmation checks against each component's checksum-verified original route. A signed profile snapshot acquired before an account becomes pending or rejected cannot edit the profile afterward. Security updates remain available with the existing verification and recent-password requirements. The password-confirmation adapter preserves Laravel's responses and stops Livewire execution on an expired confirmation; Livewire's default persistent pipeline otherwise ignores the HTTP 423 JSON response.

Both existing beneficiary assignment paths retain their server-side verified/approved eligibility checks and transaction-time rechecks. The Registry assignment picker now loads the role and approval fields needed by the shared eligibility helper. Its options and the beneficiary creation picker include only eligible Caseworkers. Existing assignments and caseload behavior are preserved.

The existing Director-created Caseworker endpoint has been removed from routing and its creation permission disabled, so the old Registry dialog is hidden. Its unused controller/template remain inert. Email-first registration remains the sole enabled account-creation path; standard Fortify registration remains disabled.

Changed source and test files:

| File | Change |
| --- | --- |
| `app/Actions/ReviewCaseworkerAccount.php` | Authorize, validate, and atomically review a pending Caseworker. |
| `app/Http/Controllers/AccountRequestController.php` | Director list/filter/pagination and review responses. |
| `app/Http/Controllers/AccountStatusController.php` | Own-account status and approved/verification routing. |
| `app/Http/Middleware/EnsureAccountApproval.php` | Pending/rejected route restrictions and Livewire handling. |
| `app/Http/Middleware/EnsureStaffAccess.php` | Verified Director or verified approved Caseworker boundary. |
| `app/Http/Middleware/RequirePasswordConfirmation.php` | Preserve password-confirmation enforcement during Livewire updates. |
| `app/Http/Responses/ApprovalAwareResponse.php` | Preserve existing login protocols while routing restricted accounts to status. |
| `app/Models/User.php` | Shared review-required and staff-access helpers. |
| `app/Policies/UserPolicy.php` | Verified Director review permission and disabled Director creation. |
| `app/Policies/BeneficiaryPolicy.php` | Align beneficiary access with verified approved staff. |
| `app/Providers/AppServiceProvider.php` | Persist route security middleware for Livewire. |
| `app/Providers/FortifyServiceProvider.php` | Bind approval-aware login, 2FA, verification, and passkey responses. |
| `bootstrap/app.php` | Web approval guard and password-confirmation adapter alias. |
| `routes/web.php` | Unique review/status routes, staff/photo guards, and removal of the Director creation route. |
| `app/Http/Controllers/BeneficiaryController.php` | Load eligibility fields, filter assignment options, and clarify assignment validation. |
| `app/Http/Controllers/BeneficiaryCaseworkerAssignmentController.php` | Clarify the existing server-side eligibility error. |
| `resources/views/account-requests/index.blade.php` | Responsive review list, filters, accessible confirmations, and rejection feedback. |
| `resources/views/account-status.blade.php` | Pending/rejected status, reason, security/verification access, and logout. |
| `resources/views/layouts/app/sidebar.blade.php` | Director-only Account Requests navigation and restricted account navigation. |
| `resources/views/components/desktop-user-menu.blade.php` | Route restricted users' settings link to account security. |
| `resources/views/components/settings/layout.blade.php` | Show only status and security navigation for restricted accounts. |
| `resources/views/beneficiaries/create.blade.php` | Explain approved, verified assignment eligibility. |
| `resources/views/beneficiaries/partials/assign-caseworker-dialog.blade.php` | Explain approved, verified assignment eligibility. |
| `database/factories/UserFactory.php` | Default fixtures represent approved legacy staff; add an explicit pending state. Real model/database defaults stay pending. |
| `tests/Feature/DirectorAccountReviewTest.php` | Review authorization, confirmations, validation, metadata, stale/interleaved decisions, pagination, and unchanged assignments. |
| `tests/Feature/AccountApprovalAccessTest.php` | Status/access rules, photos/JSON, real signed Livewire HTTP requests, security expiry, authentication compatibility, and assignment eligibility. |
| `tests/Feature/CaseworkerAccountCreationTest.php` | Assert Director creation and standard Fortify registration remain disabled. |
| `tests/Feature/UserApprovalFoundationTest.php` | Exercise real pending defaults independently of approved legacy test fixtures. |
| `tests/Feature/BeneficiaryCaseworkerAssignmentTest.php` | Update eligibility error expectations. |
| `tests/Feature/BeneficiaryCreateCaseworkerTest.php` | Update disabled creation endpoint and eligibility expectations. |
| `tests/Feature/BeneficiaryRegistryTest.php` | Assert the Director creation dialog is hidden. |
| `docs/director-account-review.md` | Implementation, validation, and deployment handover. |

The asset build regenerates `public/build/manifest.json`, `public/build/fonts-manifest.json`, and the files under `public/build/assets/`. Package manifests, lockfiles, migration files, and Attendance/NFC feature implementations were not changed. Their staff routes use the common access boundary.

Validation completed with the existing PHP 8.4.25 runtime and installed dependencies:

Reinspection on October 9, 2026 confirmed the existing implementation covers the requested flow. This pass updated only this report; application source, routes, components, tests, and assets required no further changes. The focused checks below were rerun against the current files.

| Check | Result |
| --- | --- |
| Current focused revalidation | 280 passed; 2,510 assertions. Covers Director review/access, disabled Director creation, registration/authentication/settings compatibility, legacy-account preservation, both assignment paths, and Registry behavior. |
| Full feature suite from the completed implementation | 473 passed; 3,692 assertions. Includes all review/access, registration, authentication, settings, legacy-account, Registry, assignment, and existing feature regressions. |
| Focused security/authentication checks | 37 passed, including expired confirmation, pending 2FA, passkey login, and signed Livewire updates. |
| Review concurrency | Deterministic competing decisions were interleaved after the first conditional write and before commit; only the first review persisted. No external database or concurrent production worker was used. |
| Test isolation | Existing shared bootstrap rejects non-testing infrastructure, any database other than SQLite `:memory:`, and non-isolated cache/session/queue/mail configuration before refresh hooks. Mail and notifications are faked. |
| Pint | Final formatting check passed on all 24 changed PHP files. |
| Account route inspection | Exactly one list route, one review route, and one own-account status route; review routes require verified staff and Director authorization. |
| Asset build | `npm run build` passed. The existing font plugin reported its optional `fontaine` optimization warning; no dependency was installed. |
| Current production asset inspection | All 10 assets referenced by the existing manifest are present. No frontend changes required a rebuild during revalidation. |

Tests and formatting validate the rendered markup and server behavior. A visual browser check at deployment remains useful for the real device/browser combination. The passkey compatibility test exercises the existing HTTP login protocol with a mocked credential verifier; it does not replace hardware authenticator testing.

Re-run focused checks with PHP 8.4.1 or newer:

```powershell
php vendor/bin/phpunit tests/Feature/DirectorAccountReviewTest.php tests/Feature/AccountApprovalAccessTest.php tests/Feature/CaseworkerAccountCreationTest.php tests/Feature/Auth tests/Feature/Settings tests/Feature/UserApprovalFoundationTest.php tests/Feature/BeneficiaryCaseworkerAssignmentTest.php tests/Feature/BeneficiaryCreateCaseworkerTest.php tests/Feature/BeneficiaryRegistryTest.php --no-progress
php vendor/bin/pint --test app/Actions/ReviewCaseworkerAccount.php app/Http/Controllers/AccountRequestController.php app/Http/Controllers/AccountStatusController.php app/Http/Middleware/EnsureAccountApproval.php app/Http/Middleware/EnsureStaffAccess.php app/Http/Middleware/RequirePasswordConfirmation.php app/Http/Responses/ApprovalAwareResponse.php
npm run build
```

Deployment prerequisites are the existing user role/verification schema and the previously authored approval-foundation and registration-request migrations. The approval foundation preserves existing Caseworkers by backfilling them to approved without changing verification timestamps. Do not reapply that backfill or reset existing approval decisions. No additional migration was authored for account review, and no real migration was run here. If either prerequisite is still pending, deploy it in order before enabling this code:

```powershell
php artisan migrate --path=database/migrations/2026_10_08_000001_add_caseworker_approval_fields_to_users_table.php --force
php artisan migrate --path=database/migrations/2026_10_09_000001_create_registration_requests_table.php --force
```

Then deploy the built assets, refresh the deployment caches, and use the normal application restart procedure for any long-lived PHP processes:

```powershell
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

These deployment commands were not executed. Real records were not modified, real emails were not sent, and no dependency was added. The existing local mail transport is log-only as recorded in the registration handover; provider delivery setup remains in [email-first-registration.md](email-first-registration.md). The review-foundation task added no approval/rejection notifications or email-configuration changes; the later notification extension is documented separately above.

No implementation remains within the review-foundation task's scope. Deployment of any pending prerequisites, cache/assets publication, device/browser checks, and actual email-provider delivery remain operational setup. Director-created registration, My Caseload, Attendance features, and NFC features remain outside this task; account notifications are now covered by the later extension.
