# RLCP architecture inventory

## 1. Purpose, boundary and evidence
**Verified** - `artisan`, `routes/`, and `app/` exist at the expected project root. This is a source-only description of the existing beneficiary and program management application. No application boot, tests, database connection, message delivery or live records were used. View and JavaScript files were listed only. Backup/damaged files, vendor and compiled assets are excluded.

**Verified** means a source declaration or implementation was read, not that it was exercised in production. **Documented setup** denotes configuration names or deployment requirements, not active settings. **Inferred** denotes an architectural interpretation and receives a dashed outline in diagrams. **Not found** means absent from the inspected source; no relationship is drawn. Rule IDs below link diagram annotations to exact source symbols. Field names and route placeholders are schema metadata, never real identifiers or credentials.

Six diagram subjects: package dependencies; use cases; domain data model; registration and approval; attendance; assistance and reporting. The last four are selected documentation views because only the first two subjects were specified earlier. Native editable shapes, landscape pages, no image nodes.

## 2. Actors and access
All matrix rows are **Verified** by `app/Models/User.php::{canAccessStaff,isEligibleCaseworker,requiresAccountReview}`, the three policies in `app/Policies/`, `app/Http/Middleware/EnsureAccountApproval.php::handle`, and `bootstrap/app.php::withMiddleware`.

| Human / state | Access | Restrictions |
|---|---|---|
| Guest | Public home; email-first registration | Staff routes require authenticated verified eligible user |
| Applicant | Request/consume email link; complete registration | A registration request is not yet a login account; completion creates pending Caseworker |
| Pending/rejected Caseworker | Account status, own account notifications; allowlisted verification/security/password/passkey actions subject to their own middleware | Staff denied; global approval middleware redirects or returns 403; profile/appearance are not allowlisted |
| Approved Caseworker with verified email | Own assigned nonarchived beneficiaries, caseload, profile edit/NFC/assistance; organization-wide activity definitions; scoped attendance/reports | Cannot create, assign, archive or restore beneficiaries; cannot review accounts |
| Director with verified email | Staff workspace; organization-wide beneficiaries and historical reporting; create/assign/archive/restore; account review | Beneficiary record operations still enforce state; archived record access uses dedicated paths |
| Beneficiary | Managed record, not a login actor | No beneficiary login role or public card-verification route found |

## 3. Features
**Verified** - dashboards (`DashboardController::index/caseload`); beneficiary registry/search/photo/profile/history and soft archive/restore (`BeneficiaryController`, bulk controllers); assignment (`BeneficiaryCaseworkerAssignmentController::__invoke`); organization activity definitions (`ProgramActivityController`); manual and test-UID attendance (`ActivityAttendanceController`, `NfcAttendanceController`); NFC link/replace/unlink and staff lookup (`BeneficiaryNfcController`, `NfcLookupController`); assistance releases (`AssistanceTransactionController`); reports and CSV/PDF (`Report*Controller`, `ActivityAttendanceExportController`); account review and recipient-scoped notification polling (`AccountRequestController`, `AccountNotificationController`); profile/security/appearance (`app/Livewire/Settings/`). Controller paths are `app/Http/Controllers/<class>.php`.

**Not found** - routed direct Caseworker creation (`CaseworkerController::store` remains, but `UserPolicy::createCaseworker` returns false and routes do not reference it); public card verification; beneficiary self-service login; activity delete route; assistance edit/delete routes; a custom `app/Notifications` directory. Do not equate remaining UI filenames with available features.

**Inferred** - a USB reader could provide keyboard-style UID input; no physical device was checked. It is a supporting input device, never a human actor. The inspected NFC write path records `source = nfc_test`; hardware-backed `nfc` recording is not established by this route.

## 4. Route map
Every row is **Verified** from the named routes file and handler source. `S` = auth + verified + EnsureStaffAccess. `G` = guest. All web routes also run the globally registered EnsureAccountApproval and registration protection/session middleware. GET implies framework HEAD handling; generated vendor authentication/Livewire routes were not enumerated because vendor and runtime route listing are excluded. Controller shorthand resolves to `app/Http/Controllers/<class>.php`; settings classes resolve to `app/Livewire/Settings/`.

| Method | Path | Handler | Middleware / policy or scope | Role |
|---|---|---|---|---|
| GET | `/` | `view: welcome` | public; see handler / global middleware | Guest / any |
| GET | `/register` | `RegistrationController::show` | G; see handler / global middleware | Applicant (guest) |
| POST | `/register` | `RegistrationController::requestLink` | G, throttle:registration-email; see handler / global middleware | Applicant (guest) |
| GET | `/register/verify/{token}` | `RegistrationController::verify` | G, throttle:60,1; see handler / global middleware | Applicant (guest) |
| GET | `/register/complete` | `RegistrationController::showComplete` | G; see handler / global middleware | Applicant (guest) |
| POST | `/register/complete` | `RegistrationController::complete` | G, throttle:10,1; see handler / global middleware | Applicant (guest) |
| GET | `/register/awaiting-approval` | `RegistrationController::awaitingApproval` | G; see handler / global middleware | Applicant (guest) |
| GET | `/account/status` | `AccountStatusController::__invoke` | auth; see handler / global middleware | Authenticated; status redirect per eligibility |
| GET | `/account/notifications` | `AccountNotificationController::index` | auth, throttle:120,1; owned() -> UserPolicy::viewAccountNotifications + forRecipient | Recipient only |
| GET | `/account/notifications/unread-count` | `AccountNotificationController::count` | auth, throttle:120,1; see handler / global middleware | Recipient only; UserPolicy::viewAccountNotifications |
| POST | `/account/notifications/read-all` | `AccountNotificationController::readAll` | auth, throttle:120,1; see handler / global middleware | Recipient only; UserPolicy::viewAccountNotifications |
| POST | `/account/notifications/{notification}/read` | `AccountNotificationController::read` | auth, throttle:120,1; see handler / global middleware | Recipient only; UserPolicy::viewAccountNotifications |
| GET | `/account-requests` | `AccountRequestController::index` | S; reviewAccountRequests(User::class) | Director |
| POST | `/account-requests/{user}/review` | `AccountRequestController::review` | S; reviewAccountRequests(User::class) | Director |
| POST | `/beneficiaries/assign-caseworker` | `BeneficiaryCaseworkerAssignmentController::__invoke` | S; assignCaseworker(Beneficiary::class), assignCaseworker(beneficiary) | Director |
| POST | `/beneficiaries/bulk-restore` | `BeneficiaryBulkRestoreController::__invoke` | S; viewArchived(Beneficiary::class), restore(beneficiary) | Director |
| GET | `/beneficiaries/archived/{beneficiary}/photo` | `BeneficiaryBulkRestoreController::photo` | S; viewArchived(Beneficiary::class), restore(record) | Director |
| POST | `/beneficiaries/bulk-archive` | `BeneficiaryBulkArchiveController::__invoke` | S; viewArchived(Beneficiary::class), delete(beneficiary) | Director |
| GET | `/reports/export/{type}` | `ReportExportController::__invoke` | S; viewAny(Beneficiary::class), viewAny(ProgramActivity::class); visibleTo(user); beneficiary rows visibility-scoped | Director / approved Caseworker |
| GET | `/reports/pdf/{type}` | `ReportPdfExportController::__invoke` | S; viewAny(Beneficiary::class), viewAny(ProgramActivity::class); visibleTo(user); beneficiary rows visibility-scoped | Director / approved Caseworker |
| GET | `/reports` | `ReportController::index` | S; viewAny(Beneficiary::class), viewAny(ProgramActivity::class); visibleTo(user); beneficiary rows visibility-scoped | Director / approved Caseworker |
| GET | `/nfc` | `NfcLookupController::index` | S; viewAny(Beneficiary::class) | Director / approved Caseworker |
| POST | `/nfc/lookup` | `NfcLookupController::lookup` | S; viewAny(Beneficiary::class), view(beneficiary); visibleTo(user) | Director / approved Caseworker |
| GET | `/dashboard/beneficiary-suggestions` | `BeneficiarySearchController::__invoke` | S, throttle:120,1; viewAny(Beneficiary::class); visibleTo(user) | Director / approved Caseworker |
| GET | `/dashboard/notifications` | `DashboardNotificationController::__invoke` | S, throttle:60,1; viewAny(Beneficiary::class), viewAny(ProgramActivity::class); visibleTo(user) | Director / approved Caseworker |
| GET | `/dashboard` | `DashboardController::index` | S; viewAny(Beneficiary::class), viewAny(ProgramActivity::class), viewMyCaseload(Beneficiary::class) only in Caseworker branch | Director / approved Caseworker |
| GET | `/dashboard/caseload` | `DashboardController::caseload` | S, throttle:30,1; viewMyCaseload(Beneficiary::class) | Approved Caseworker |
| GET | `/my-caseload` | `MyCaseloadController::index` | S; viewMyCaseload(Beneficiary::class); visibleTo(user) | Approved Caseworker |
| GET | `/attendance` | `ProgramActivityController::attendance` | S; viewAny(ProgramActivity::class) | Director / approved Caseworker |
| GET | `/activities` | `ProgramActivityController::index` | S; viewAny(ProgramActivity::class) | Director / approved Caseworker |
| GET | `/activities/create` | `ProgramActivityController::create` | S; create(ProgramActivity::class) | Director / approved Caseworker |
| POST | `/activities` | `ProgramActivityController::store` | S; create(ProgramActivity::class) | Director / approved Caseworker |
| POST | `/activities/{activity}/attendance` | `ActivityAttendanceController::store` | S; recordAttendance(activity), view(beneficiary); visibleTo(user); beneficiary rows visibility-scoped | Director / approved Caseworker |
| GET | `/activities/{activity}/edit` | `ProgramActivityController::edit` | S; update(activity) | Director / approved Caseworker |
| PUT | `/activities/{activity}` | `ProgramActivityController::update` | S; update(activity) | Director / approved Caseworker |
| GET | `/activities/{activity}/attendance/export` | `ActivityAttendanceExportController::__invoke` | S; view(activity); visibleTo(user); beneficiary rows visibility-scoped | Director / approved Caseworker |
| POST | `/activities/{activity}/attendance/nfc` | `NfcAttendanceController::store` | S; recordAttendance(activity), view(beneficiary); visibleTo(user); beneficiary rows visibility-scoped | Director / approved Caseworker |
| GET | `/activities/{activity}` | `ProgramActivityController::show` | S; attendanceWorkspace -> view(activity), scoped rows | Director / approved Caseworker |
| GET | `/beneficiaries/archived` | `BeneficiaryController::archived` | S; viewArchived(Beneficiary::class) | Director |
| DELETE | `/beneficiaries/{beneficiary}` | `BeneficiaryController::destroy` | S; delete(beneficiary) | Director |
| POST | `/beneficiaries/{beneficiary}/restore` | `BeneficiaryController::restore` | S; viewArchived(Beneficiary::class), restore(record) | Director |
| GET | `/beneficiaries` | `BeneficiaryController::index` | S; viewAny(Beneficiary::class); visibleTo(user) | Director / approved Caseworker |
| GET | `/beneficiaries/create` | `BeneficiaryController::create` | S; create(Beneficiary::class), assignCaseworker(Beneficiary::class) | Director (create GET redirects Caseworker) |
| POST | `/beneficiaries/nfc/check` | `BeneficiaryNfcController::check` | S; create(Beneficiary::class) | Director (create GET redirects Caseworker) |
| POST | `/beneficiaries` | `BeneficiaryController::store` | S; create(Beneficiary::class), assignCaseworker(Beneficiary::class), assignCaseworker(record) | Director (create GET redirects Caseworker) |
| GET | `/beneficiaries/{beneficiary}/edit` | `BeneficiaryController::edit` | S; update(beneficiary) | Director / currently assigned Caseworker |
| PUT | `/beneficiaries/{beneficiary}` | `BeneficiaryController::update` | S; update(beneficiary) | Director / currently assigned Caseworker |
| GET | `/beneficiaries/{beneficiary}/assistance/create` | `AssistanceTransactionController::create` | S; update(beneficiary) | Director / currently assigned Caseworker |
| POST | `/beneficiaries/{beneficiary}/assistance` | `AssistanceTransactionController::store` | S; update(beneficiary) | Director / currently assigned Caseworker |
| PUT | `/beneficiaries/{beneficiary}/nfc` | `BeneficiaryNfcController::update` | S; update(beneficiary), update(record) | Director / currently assigned Caseworker |
| DELETE | `/beneficiaries/{beneficiary}/nfc` | `BeneficiaryNfcController::destroy` | S; update(beneficiary) | Director / currently assigned Caseworker |
| GET | `/beneficiaries/{beneficiary}` | `BeneficiaryController::show` | S; view(beneficiary) | Director / currently assigned Caseworker |
| GET | `/beneficiaries/{beneficiary}/photo` | `closure` | S; route can(view, beneficiary); missing photo -> 404 | Director / currently assigned Caseworker |
| GET | `/settings` | `redirect: settings/profile` | auth; see handler / global middleware | Own account; approval allowlist applies |
| GET | `/settings/profile` | `Profile` | auth; see handler / global middleware | Own account; approval allowlist applies |
| GET | `/settings/appearance` | `Appearance` | auth, verified; see handler / global middleware | Own account; approval allowlist applies |
| GET | `/settings/security` | `Security` | auth, verified, password.confirm; see handler / global middleware | Own account; approval allowlist applies |
| GET | `/.well-known/passkey-endpoints` | `closure` | public; see handler / global middleware | Own account; approval allowlist applies |

**Verified** - `bootstrap/app.php::withRouting` additionally declares health path `/up`; `routes/console.php` defines the `inspire` console command, not a web use case. No commands were run.

## 5. Data dictionary and constraints
All tables below are **Verified** schema declarations from `database/migrations/<filename>::up`; applied database state is unverified. `id()` = unsigned bigint primary key; `foreignId()` = unsigned bigint (a foreign key only when `constrained` follows); `string()` defaults to length 255; `timestamps()` = nullable created/updated timestamps; `softDeletes()` = nullable deletion timestamp; `rememberToken()` = nullable string(100). `?` / `nullable` means nullable; other columns required unless framework shorthand says otherwise. Literal business defaults are retained; no record values appear.

**Verified - `users` (create)**, `0001_01_01_000000_create_users_table.php::up`

`id()`; `string('name')`; `string('email')->unique()`; `timestamp('email_verified_at')->nullable()`; `string('password')`; `rememberToken()`; `timestamps()`

**Verified - `password_reset_tokens` (create)**, `0001_01_01_000000_create_users_table.php::up`

`string('email')->primary()`; `string('token')`; `timestamp('created_at')->nullable()`

**Verified - `sessions` (create)**, `0001_01_01_000000_create_users_table.php::up`

`string('id')->primary()`; `foreignId('user_id')->nullable()->index()`; `string('ip_address', 45)->nullable()`; `text('user_agent')->nullable()`; `longText('payload')`; `integer('last_activity')->index()`

**Verified - `cache` (create)**, `0001_01_01_000001_create_cache_table.php::up`

`string('key')->primary()`; `mediumText('value')`; `bigInteger('expiration')->index()`

**Verified - `cache_locks` (create)**, `0001_01_01_000001_create_cache_table.php::up`

`string('key')->primary()`; `string('owner')`; `bigInteger('expiration')->index()`

**Verified - `jobs` (create)**, `0001_01_01_000002_create_jobs_table.php::up`

`id()`; `string('queue')->index()`; `longText('payload')`; `unsignedSmallInteger('attempts')`; `unsignedInteger('reserved_at')->nullable()`; `unsignedInteger('available_at')`; `unsignedInteger('created_at')`

**Verified - `job_batches` (create)**, `0001_01_01_000002_create_jobs_table.php::up`

`string('id')->primary()`; `string('name')`; `integer('total_jobs')`; `integer('pending_jobs')`; `integer('failed_jobs')`; `longText('failed_job_ids')`; `mediumText('options')->nullable()`; `integer('cancelled_at')->nullable()`; `integer('created_at')`; `integer('finished_at')->nullable()`

**Verified - `failed_jobs` (create)**, `0001_01_01_000002_create_jobs_table.php::up`

`id()`; `string('uuid')->unique()`; `string('connection')`; `string('queue')`; `longText('payload')`; `longText('exception')`; `timestamp('failed_at')->useCurrent()`; `index(['connection', 'queue', 'failed_at'])`

**Verified - `passkeys` (create)**, `2024_01_01_000000_create_passkeys_table.php::up`

`id()`; `foreignId('user_id')->constrained()->cascadeOnDelete()`; `string('name')`; `string('credential_id')->unique()`; `json('credential')`; `timestamp('last_used_at')->nullable()`; `timestamps()`; `index('user_id')`

**Verified - `users` (table)**, `2025_08_14_170933_add_two_factor_columns_to_users_table.php::up`

`text('two_factor_secret')->after('password')->nullable()`; `text('two_factor_recovery_codes')->after('two_factor_secret')->nullable()`; `timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable()`

**Verified - `beneficiaries` (create)**, `2026_09_27_122858_create_beneficiaries_table.php::up`

`id()`; `timestamps()`

**Verified - `beneficiaries` (table)**, `2026_09_27_131325_add_profile_fields_to_beneficiaries_table.php::up`

`string('beneficiary_id')->unique()`; `string('full_name')`; `date('registered_at')`; `string('status')->default('active')`

**Verified - `beneficiaries` (table)**, `2026_09_27_133810_add_personal_details_to_beneficiaries_table.php::up`

`string('photo_path')->nullable()`; `date('birthdate')->nullable()`; `text('address')->nullable()`; `string('guardian_name')->nullable()`; `string('guardian_contact', 30)->nullable()`; `string('school')->nullable()`; `string('grade_level', 50)->nullable()`

**Verified - `beneficiaries` (table)**, `2026_09_29_011746_add_soft_deletes_to_beneficiaries_table.php::up`

`softDeletes()`

**Verified - `users` (table)**, `2026_09_29_014405_add_role_to_users_table.php::up`

`string('role', 30)->default('caseworker')`

**Verified - `program_activities` (create)**, `2026_09_29_060908_create_program_activities_table.php::up`

`id()`; `string('title')`; `date('activity_date')`; `time('start_time')->nullable()`; `string('development_area', 30)`; `string('location')->nullable()`; `text('description')->nullable()`; `foreignId('created_by')->nullable() ->constrained('users')->nullOnDelete()`; `timestamps()`

**Verified - `activity_attendances` (create)**, `2026_09_29_063658_create_activity_attendances_table.php::up`

`id()`; `foreignId('program_activity_id') ->constrained('program_activities') ->restrictOnDelete()`; `foreignId('beneficiary_id') ->constrained('beneficiaries') ->restrictOnDelete()`; `timestamp('checked_in_at')`; `string('source', 20)->default('manual')`; `foreignId('recorded_by')->nullable() ->constrained('users') ->nullOnDelete()`; `timestamps()`; `unique( ['program_activity_id', 'beneficiary_id'], 'activity_attendance_unique_beneficiary' )`

**Verified - `assistance_transactions` (create)**, `2026_09_29_083019_create_assistance_transactions_table.php::up`

`id()`; `foreignId('beneficiary_id') ->constrained('beneficiaries') ->restrictOnDelete()`; `string('category', 30)`; `string('item_name')`; `unsignedInteger('quantity')->default(1)`; `dateTime('released_at')`; `text('notes')->nullable()`; `foreignId('recorded_by')->nullable() ->constrained('users') ->nullOnDelete()`; `timestamps()`

**Verified - `beneficiaries` (table)**, `2026_09_30_084222_add_nfc_uid_to_beneficiaries_table.php::up`

`string('nfc_uid', 64)->nullable()->unique()`

**Verified - `assistance_transactions` (table)**, `2026_10_02_052643_add_submission_token_to_assistance_transactions_table.php::up`

`uuid('submission_token')->nullable()->unique()`

**Verified - `beneficiaries` (table)**, `2026_10_07_000001_add_caseworker_id_to_beneficiaries_table.php::up`

`foreignId('caseworker_id')->nullable()->constrained('users')->nullOnDelete()`

**Verified - `users` (table)**, `2026_10_08_000001_add_caseworker_approval_fields_to_users_table.php::up`

`enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending')`; `foreignId('approval_reviewed_by')->nullable()->constrained('users')->nullOnDelete()`; `timestamp('approval_reviewed_at')->nullable()`; `text('rejection_reason')->nullable()`

**Verified - `registration_requests` (create)**, `2026_10_09_000001_create_registration_requests_table.php::up`

`id()`; `string('email')->unique()`; `char('token_hash', 64)->nullable()->unique()`; `timestamp('expires_at')`; `timestamp('verified_at')->nullable()`; `char('session_token_hash', 64)->nullable()->unique()`; `timestamp('session_expires_at')->nullable()`; `timestamp('completed_at')->nullable()`; `timestamps()`

**Verified - `account_notifications` (create)**, `2026_10_09_000002_create_account_notifications_table.php::up`

`uuid('id')->primary()`; `foreignId('recipient_id')->constrained('users')->cascadeOnDelete()`; `foreignId('account_id')->constrained('users')->cascadeOnDelete()`; `string('kind', 32)`; `string('event_key', 80)`; `timestamp('read_at')->nullable()`; `timestamps()`; `unique(['recipient_id', 'event_key'])`; `index(['recipient_id', 'read_at', 'created_at'])`

**Verified - `account_notifications` (table)**, `2026_10_10_000001_add_assignment_counts_to_account_notifications_table.php::up`

`unsignedSmallInteger('added_count')->default(0)`; `unsignedSmallInteger('removed_count')->default(0)`


**Verified - DB versus application checks:** users.email, beneficiaries.beneficiary_id, beneficiaries.nfc_uid, assistance submission_token, registration email/hash fields, passkey credential_id and notification recipient/event pairs have declared uniqueness. Attendance has composite unique(activity, beneficiary). Archived beneficiary rows retain NFC ownership. Assignment foreign keys do not constrain a user to an approved Caseworker: `User::scopeEligibleCaseworkers` and assignment-controller validation/locking enforce that. Role is a string, while approval_status is an enum. Beneficiary status and assistance category/quantity limits are application validations, not equivalent DB enums/checks. Submission token is nullable in migration but required UUID in `AssistanceTransactionController::store`. Sessions.user_id is indexed but has no declared FK. RegistrationRequest has no FK to User: completion matches email in application logic. Attendance and assistance reference beneficiaries with restrict-on-delete; recorder and creator user references null on delete. Beneficiary uses SoftDeletes, not a separate archive table. User and Beneficiary are separate entities; caseworker_id denotes assignment, not beneficiary login ownership.

## 6. Flow evidence and rule IDs
| Rule | Status and source | Existing behavior / branches |
|---|---|---|
| AUTH-01 | Verified: `app/Models/User.php::canAccessStaff`; `app/Http/Middleware/EnsureStaffAccess.php::handle`; `routes/web.php` staff group | Verified Director or approved verified Caseworker required; otherwise 403. |
| AUTH-02 | Verified: `app/Http/Middleware/EnsureAccountApproval.php::handle`; `bootstrap/app.php::withMiddleware` | Pending/rejected Caseworker restricted to explicit status/security/verification/notification allowlist; other HTML redirects, JSON/Livewire denied. |
| AUTH-03 | Verified: `app/Policies/BeneficiaryPolicy.php::{view,update,create,delete,restore,assignCaseworker}`; `app/Models/Beneficiary.php::scopeVisibleTo` | Director organization scope; Caseworker current assignment and nonarchived rows; create/assign/archive/restore Director only. |
| AUTH-04 | Verified: `app/Policies/ProgramActivityPolicy.php`; `app/Http/Controllers/ProgramActivityController.php` | Activity definitions organization-wide to both eligible roles through staff routes; attendance data still scoped. |
| REG-01 | Verified: `RegistrationController::{requestLink,verify,complete}`; `EmailRegistrationService::{requestLink,verify,complete}` | Guest POST request validates normalized email; generic response for existing accounts. Upsert request, hash link, invalidate prior generation, queue after commit. Valid unused link atomically consumed; 15-minute session created (link lifetime 30 minutes); completion atomically consumes session, creates verified pending Caseworker and Director notification. Invalid/expired/used link/session rejected; duplicate email protected by app check and unique DB constraint. |
| REG-02 | Verified: `app/Jobs/SendRegistrationVerification.php::{__construct,handle}`; `EmailRegistrationService::{queueVerification,deliverLink}`; `SkipRegistrationVerification::handle` | Explicit database queue; single attempt; ciphertext carried to worker, cache generation claim prevents repeat delivery. Worker sends RegistrationVerificationMail synchronously. Existing email queues no-op job. Obsolete generation skips; enqueue/delivery failure invalidates link; transport logging blocked. Bootstrap registration exception handler returns sanitized errors. |
| REG-03 | Verified: `AccountRequestController::review`; `app/Actions/ReviewCaseworkerAccount.php::execute`; `AccountNotificationService::decisionSaved` | Director policy + confirmed decision; rejection reason required. Transaction conditionally updates pending Caseworker; notification stored; decision mail queued after commit. Stale/repeated review returns conflict; mail enqueue failure cannot undo committed decision. |
| ASSIGN-01 | Verified: `BeneficiaryCaseworkerAssignmentController::__invoke`; `AccountNotificationService::assignmentsChanged` | Director; maximum 100 distinct beneficiaries; eligible Caseworker or unassign; lock records, reject unavailable/archived members, store changed assignments and counts in same transaction. Repeating unchanged assignment makes no new change notification. |
| NFC-01 | Verified: `BeneficiaryNfcController::{check,update,destroy}`; `app/Support/NfcCardUid.php` | Normalize separators/case and validate supported numeric/hex formats. Table-level unique includes archives. Update reauthorizes locked record; unchanged UID returns already_registered; stale replacement returns 409 or validation; check_only writes nothing; DB race converted to ownership validation. Unlink clears UID. |
| NFC-02 | Verified: `NfcAttendanceController::store`; `Beneficiary::scopeVisibleTo`; `ActivityAttendance` migration | POST activity NFC route; activity policy, format validation; transaction scopes and locks beneficiary, requires active/nonarchived and view policy. firstOrCreate plus composite unique prevents duplicate attendance; records UTC timestamp, current recorder and source nfc_test. Missing/inaccessible card or inactive record fails validation. |
| ATT-01 | Verified: `ActivityAttendanceController::store`; `app/Http/Responses/AttendanceCheckInResponse.php::make` | Manual path validates beneficiary identifier, scopes/locks active record, uses insertOrIgnore then reads row. JSON created=201, duplicate=200; redirect variants retain success/duplicate feedback. No checkout flow found. |
| AST-01 | Verified: `AssistanceTransactionController::{create,store}` | Beneficiary update policy + active status; validate required UUID, category, quantity and local date/time; convert Manila to UTC. Find existing lowercased submission_token or create; catch only matching unique conflict; compare all payload fields including recorder. Exact replay succeeds without write; differing payload rejects; unsaved record fails. Redirect to beneficiary history. No financial payment gateway or stock movement found. |
| REP-01 | Verified: `ReportController::index`; `ReportExportController::__invoke`; `ReportPdfExportController::__invoke`; `ActivityAttendanceExportController::__invoke` | Staff + viewAny gates (activity export uses view); read-only scoped queries. Director historical rows include archives; Caseworker rows follow current assignment and exclude archives. Validate date range, inclusive Manila calendar days converted to UTC half-open interval; bad range rejects. Activity export optionally filters beneficiary search. |
| REP-02 | Verified: `ReportExportController::safeCell`; `ActivityAttendanceExportController::safeCell`; `app/Support/ExportRows.php::descending`; `ReportPdfExportController::__invoke` | CSV streams ordered cursor batches, escapes formula prefixes. PDF queries at most 1001 rows, rejects over 1000, loads reports.pdf, landscape A4, remote/PHP disabled. Download private/no-store. No business record write; empty set produces empty report. |
| ACC-01 | Verified: `AccountNotificationController::owned`; `AccountNotification::scopeForRecipient`; `app/Livewire/Settings/{Profile,Security,DeleteUserForm}.php` | Notifications recipient-scoped; reads/read-all update own read state. Profile edits reset email verification when address changes. Security changes own password/passkeys/2FA; deletion checks current password. Feature flags and middleware apply. |

Controller symbols without paths resolve to `app/Http/Controllers/<class>.php`; service symbols resolve to `app/Services/<class>.php`; model symbols resolve to `app/Models/<class>.php`. Direct action/helper source was followed only where imported by these controllers, to verify actual dependencies instead of inventing service layers.

## 7. Deployment configuration names only
**Documented setup** ? names extracted from source configuration declarations, without evaluating configuration or reading environment files. Values, active drivers, credentials, endpoints, hosts and operational readiness are not documented. Core registration verification explicitly selects the database queue (REG-02); decision mail uses configured queue behavior. draw.io export is a documentation operation, unrelated to Laravel runtime.

- **Documented setup**, `app.php`: `APP_DEBUG`, `APP_ENV`, `APP_FAKER_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_KEY`, `APP_LOCALE`, `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE`, `APP_NAME`, `APP_PREVIOUS_KEYS`, `APP_URL`
- **Documented setup**, `auth.php`: `AUTH_GUARD`, `AUTH_MODEL`, `AUTH_PASSWORD_BROKER`, `AUTH_PASSWORD_RESET_TOKEN_TABLE`, `AUTH_PASSWORD_TIMEOUT`
- **Documented setup**, `cache.php`: `APP_NAME`, `AWS_ACCESS_KEY_ID`, `AWS_DEFAULT_REGION`, `AWS_SECRET_ACCESS_KEY`, `CACHE_PREFIX`, `CACHE_STORAGE_DISK`, `CACHE_STORAGE_PATH`, `CACHE_STORE`, `DB_CACHE_CONNECTION`, `DB_CACHE_LOCK_CONNECTION`, `DB_CACHE_LOCK_TABLE`, `DB_CACHE_TABLE`, `DYNAMODB_CACHE_TABLE`, `DYNAMODB_ENDPOINT`, `MEMCACHED_HOST`, `MEMCACHED_PASSWORD`, `MEMCACHED_PERSISTENT_ID`, `MEMCACHED_PORT`, `MEMCACHED_USERNAME`, `REDIS_CACHE_CONNECTION`, `REDIS_CACHE_LOCK_CONNECTION`
- **Documented setup**, `database.php`: `APP_NAME`, `DB_CHARSET`, `DB_COLLATION`, `DB_CONNECTION`, `DB_DATABASE`, `DB_ENCRYPT`, `DB_FOREIGN_KEYS`, `DB_HOST`, `DB_PASSWORD`, `DB_PORT`, `DB_SOCKET`, `DB_SSLMODE`, `DB_TRUST_SERVER_CERTIFICATE`, `DB_URL`, `DB_USERNAME`, `MYSQL_ATTR_SSL_CA`, `REDIS_BACKOFF_ALGORITHM`, `REDIS_BACKOFF_BASE`, `REDIS_BACKOFF_CAP`, `REDIS_CACHE_DB`, `REDIS_CLIENT`, `REDIS_CLUSTER`, `REDIS_DB`, `REDIS_HOST`, `REDIS_MAX_RETRIES`, `REDIS_PASSWORD`, `REDIS_PERSISTENT`, `REDIS_PORT`, `REDIS_PREFIX`, `REDIS_URL`, `REDIS_USERNAME`
- **Documented setup**, `filesystems.php`: `APP_URL`, `AWS_ACCESS_KEY_ID`, `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `AWS_ENDPOINT`, `AWS_SECRET_ACCESS_KEY`, `AWS_URL`, `AWS_USE_PATH_STYLE_ENDPOINT`, `FILESYSTEM_DISK`
- **Documented setup**, `fortify.php`: `PASSKEYS_USER_HANDLE_SECRET`
- **Documented setup**, `logging.php`: `APP_NAME`, `LOG_CHANNEL`, `LOG_DAILY_DAYS`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_DEPRECATIONS_TRACE`, `LOG_LEVEL`, `LOG_PAPERTRAIL_HANDLER`, `LOG_SLACK_EMOJI`, `LOG_SLACK_USERNAME`, `LOG_SLACK_WEBHOOK_URL`, `LOG_STACK`, `LOG_STDERR_FORMATTER`, `LOG_SYSLOG_FACILITY`, `PAPERTRAIL_PORT`, `PAPERTRAIL_URL`
- **Documented setup**, `mail.php`: `APP_NAME`, `APP_URL`, `MAIL_EHLO_DOMAIN`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_HOST`, `MAIL_LOG_CHANNEL`, `MAIL_MAILER`, `MAIL_PASSWORD`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_SENDMAIL_PATH`, `MAIL_URL`, `MAIL_USERNAME`, `POSTMARK_MESSAGE_STREAM_ID`
- **Documented setup**, `queue.php`: `AWS_ACCESS_KEY_ID`, `AWS_DEFAULT_REGION`, `AWS_SECRET_ACCESS_KEY`, `BEANSTALKD_QUEUE`, `BEANSTALKD_QUEUE_HOST`, `BEANSTALKD_QUEUE_RETRY_AFTER`, `DB_CONNECTION`, `DB_QUEUE`, `DB_QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER`, `DB_QUEUE_TABLE`, `QUEUE_CONNECTION`, `QUEUE_FAILED_DRIVER`, `REDIS_QUEUE`, `REDIS_QUEUE_CONNECTION`, `REDIS_QUEUE_RETRY_AFTER`, `SQS_PREFIX`, `SQS_QUEUE`, `SQS_SUFFIX`
- **Documented setup**, `services.php`: `AWS_ACCESS_KEY_ID`, `AWS_DEFAULT_REGION`, `AWS_SECRET_ACCESS_KEY`, `POSTMARK_API_KEY`, `RESEND_API_KEY`, `SLACK_BOT_USER_DEFAULT_CHANNEL`, `SLACK_BOT_USER_OAUTH_TOKEN`
- **Documented setup**, `session.php`: `APP_NAME`, `SESSION_CONNECTION`, `SESSION_COOKIE`, `SESSION_DOMAIN`, `SESSION_DRIVER`, `SESSION_ENCRYPT`, `SESSION_EXPIRE_ON_CLOSE`, `SESSION_HTTP_ONLY`, `SESSION_LIFETIME`, `SESSION_PARTITIONED_COOKIE`, `SESSION_PATH`, `SESSION_SAME_SITE`, `SESSION_SECURE_COOKIE`, `SESSION_STORE`, `SESSION_TABLE`

## 8. UI filename inventory (contents not opened)

**Verified** - filenames only; filename presence does not prove a reachable feature.

### resources/views

- `resources/views/account-requests/index.blade.php`
- `resources/views/account-status.blade.php`
- `resources/views/activities/create.blade.php`
- `resources/views/activities/edit.blade.php`
- `resources/views/activities/index.blade.php`
- `resources/views/activities/partials/attendance-records.blade.php`
- `resources/views/activities/show.blade.php`
- `resources/views/assistance/create.blade.php`
- `resources/views/beneficiaries/archived.blade.php`
- `resources/views/beneficiaries/create.blade.php`
- `resources/views/beneficiaries/edit.blade.php`
- `resources/views/beneficiaries/index.blade.php`
- `resources/views/beneficiaries/partials/add-caseworker-dialog.blade.php`
- `resources/views/beneficiaries/partials/assign-caseworker-dialog.blade.php`
- `resources/views/beneficiaries/partials/caseworker-caseloads.blade.php`
- `resources/views/beneficiaries/partials/nfc-registration-dialog.blade.php`
- `resources/views/beneficiaries/partials/registry-pagination.blade.php`
- `resources/views/beneficiaries/show.blade.php`
- `resources/views/components/app-logo-icon.blade.php`
- `resources/views/components/app-logo.blade.php`
- `resources/views/components/auth-header.blade.php`
- `resources/views/components/auth-session-status.blade.php`
- `resources/views/components/dashboard-upcoming-activities.blade.php`
- `resources/views/components/desktop-user-menu.blade.php`
- `resources/views/components/passkey-registration.blade.php`
- `resources/views/components/passkey-verify.blade.php`
- `resources/views/components/placeholder-pattern.blade.php`
- `resources/views/components/public/brand.blade.php`
- `resources/views/components/public/icon.blade.php`
- `resources/views/components/registration/dialog.blade.php`
- `resources/views/components/registration/email-form.blade.php`
- `resources/views/components/registration/page.blade.php`
- `resources/views/components/registration/panel.blade.php`
- `resources/views/components/registration/password-field.blade.php`
- `resources/views/components/registry-beneficiary-person.blade.php`
- `resources/views/components/registry-beneficiary-status.blade.php`
- `resources/views/components/registry-menu.blade.php`
- `resources/views/components/registry-search.blade.php`
- `resources/views/components/rlcp-beneficiary-search.blade.php`
- `resources/views/components/rlcp-icon.blade.php`
- `resources/views/components/rlcp-notifications.blade.php`
- `resources/views/components/settings/layout.blade.php`
- `resources/views/dashboard/caseworker.blade.php`
- `resources/views/dashboard/partials/caseload-preview.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/flux/icon/book-open-text.blade.php`
- `resources/views/flux/icon/chevrons-up-down.blade.php`
- `resources/views/flux/icon/folder-git-2.blade.php`
- `resources/views/flux/icon/layout-grid.blade.php`
- `resources/views/flux/navlist/group.blade.php`
- `resources/views/layouts/app/header.blade.php`
- `resources/views/layouts/app/sidebar.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/layouts/auth/card.blade.php`
- `resources/views/layouts/auth/simple.blade.php`
- `resources/views/layouts/auth/split.blade.php`
- `resources/views/layouts/auth.blade.php`
- `resources/views/livewire/auth/complete-registration.blade.php`
- `resources/views/livewire/auth/confirm-password.blade.php`
- `resources/views/livewire/auth/forgot-password.blade.php`
- `resources/views/livewire/auth/login.blade.php`
- `resources/views/livewire/auth/register.blade.php`
- `resources/views/livewire/auth/registration-awaiting-approval.blade.php`
- `resources/views/livewire/auth/reset-password.blade.php`
- `resources/views/livewire/auth/two-factor-challenge.blade.php`
- `resources/views/livewire/auth/verify-email.blade.php`
- `resources/views/livewire/settings/appearance.blade.php`
- `resources/views/livewire/settings/delete-user-form.blade.php`
- `resources/views/livewire/settings/profile.blade.php`
- `resources/views/livewire/settings/security.blade.php`
- `resources/views/livewire/settings/two-factor/recovery-codes.blade.php`
- `resources/views/mail/account-decision.blade.php`
- `resources/views/mail/registration-verification.blade.php`
- `resources/views/nfc/lookup.blade.php`
- `resources/views/partials/head.blade.php`
- `resources/views/partials/settings-heading.blade.php`
- `resources/views/reports/index.blade.php`
- `resources/views/reports/pdf.blade.php`
- `resources/views/welcome.blade.php`

### resources/js

- `resources/js/account-notifications.js`
- `resources/js/app.js`
- `resources/js/attendance-workspace.js`
- `resources/js/beneficiary-registry.js`
- `resources/js/caseload-dashboard.js`
- `resources/js/login.js`
- `resources/js/nfc-registration.js`
- `resources/js/passkeys.js`
- `resources/js/public-home.js`
- `resources/js/registration-email.js`
- `resources/js/registration-ui.js`

## Verification boundary

**Not found / unverified:** deployed topology, active database engine, running queue worker, actual mail delivery, physical USB reader behavior and vendor-generated route inventory. None were probed. Migration schema is not evidence of applied migrations. Diagram PDF validation is recorded separately in `verification.md`.
