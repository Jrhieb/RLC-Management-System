# RLCP staff branding review

Implemented 2026-11-08. Rebranding only; no application workflow, data, dependency, or public-site changes.

## Theme and scope

The existing public site is the palette source: crimson `#b91c1c`, hover `#991b1b`, gold `#ffc107`, brown `#6b4226`, charcoal `#181818`, ivory `#fff9ef`. `resources/css/staff-brand.css` owns the staff light/dark variables and maps the existing `rlc-*` component tokens and violet/zinc utility palette. The `.rlcp-staff-brand` body scopes every new theme rule. Semantic success, warning, failure and destructive colors remain separate. Existing spacing, radii, typography sizes, breakpoints, card counts, column definitions, sidebar width and menu order are preserved.

Scope was inventoried from `routes/web.php`, `routes/settings.php`, existing views and policies without booting the live application. Both roles can view reports and manage activities under the existing policies. **Beneficiary registration is Director-only**, and Caseworker access to profiles/history is assignment-scoped. Those rules were not changed. Caseworker management, assistance history and attendance history are existing panels/dialogs, not separate invented modules.

## Page-by-page checklist

**Covered** means its existing view inherits the staff theme or its print colors were updated. **NV** means browser verification was not achieved, not a visual pass. **N/A** means not an HTML/themed page. No page is marked visually OK based only on source inspection.

### Director

| Page / state | Route | Existing view | Coverage | Light OK | Dark OK | Mobile OK |
|---|---|---|---|---|---|---|
| Dashboard | `/dashboard` | `dashboard.blade.php` | Covered | NV | NV | NV |
| Registry: list, cards, search, filters, pagination | `/beneficiaries` | `beneficiaries/index.blade.php` | Covered | NV | NV | NV |
| Caseworker caseload directory and selected caseload | `/beneficiaries?caseworker=?` | `beneficiaries/partials/caseworker-caseloads.blade.php` | Covered | NV | NV | NV |
| Beneficiary registration | `/beneficiaries/create` | `beneficiaries/create.blade.php` | Covered | NV | NV | NV |
| Beneficiary profile, assistance and attendance history | `/beneficiaries/{beneficiary}` | `beneficiaries/show.blade.php` | Covered | NV | NV | NV |
| Beneficiary edit | `/beneficiaries/{beneficiary}/edit` | `beneficiaries/edit.blade.php` | Covered | NV | NV | NV |
| Archived registry and restore dialog | `/beneficiaries/archived` | `beneficiaries/archived.blade.php` | Covered | NV | NV | NV |
| Record assistance | `/beneficiaries/{beneficiary}/assistance/create` | `assistance/create.blade.php` | Covered | NV | NV | NV |
| Attendance workspace: no selection and selected activity | `/attendance` | `activities/show.blade.php` | Covered | NV | NV | NV |
| Activity list | `/activities` | `activities/index.blade.php` | Covered | NV | NV | NV |
| Create activity | `/activities/create` | `activities/create.blade.php` | Covered | NV | NV | NV |
| Edit activity | `/activities/{activity}/edit` | `activities/edit.blade.php` | Covered | NV | NV | NV |
| Activity detail and attendance records | `/activities/{activity}` | `activities/show.blade.php` | Covered | NV | NV | NV |
| Reports: attendance and assistance tabs | `/reports` | `reports/index.blade.php` | Covered | NV | NV | NV |
| NFC lookup | `/nfc` | `nfc/lookup.blade.php` | Covered | NV | NV | NV |
| Account requests: pending/approved/rejected/all | `/account-requests` | `account-requests/index.blade.php` | Covered | NV | NV | NV |
| Profile settings | `/settings/profile` | `livewire/settings/profile.blade.php` | Covered | NV | NV | NV |
| Security, password, passkeys and two-factor/recovery dialogs | `/settings/security` | `livewire/settings/security.blade.php` | Covered | NV | NV | NV |
| Appearance: light/dark/system | `/settings/appearance` | `livewire/settings/appearance.blade.php` | Covered | NV | NV | NV |
### Caseworker

| Page / state | Route | Existing view | Coverage | Light OK | Dark OK | Mobile OK |
|---|---|---|---|---|---|---|
| Dashboard | `/dashboard` | `dashboard/caseworker.blade.php` | Covered | NV | NV | NV |
| My Caseload: assigned list/cards/filters | `/my-caseload` | `beneficiaries/index.blade.php` | Covered | NV | NV | NV |
| Registry: list, cards, search, filters, pagination | `/beneficiaries` | `beneficiaries/index.blade.php` | Covered | NV | NV | NV |
| Beneficiary profile, assistance and attendance history | `/beneficiaries/{beneficiary}` | `beneficiaries/show.blade.php` | Covered | NV | NV | NV |
| Beneficiary edit | `/beneficiaries/{beneficiary}/edit` | `beneficiaries/edit.blade.php` | Covered | NV | NV | NV |
| Record assistance | `/beneficiaries/{beneficiary}/assistance/create` | `assistance/create.blade.php` | Covered | NV | NV | NV |
| Attendance workspace: no selection and selected activity | `/attendance` | `activities/show.blade.php` | Covered | NV | NV | NV |
| Activity list | `/activities` | `activities/index.blade.php` | Covered | NV | NV | NV |
| Create activity | `/activities/create` | `activities/create.blade.php` | Covered | NV | NV | NV |
| Edit activity | `/activities/{activity}/edit` | `activities/edit.blade.php` | Covered | NV | NV | NV |
| Activity detail and attendance records | `/activities/{activity}` | `activities/show.blade.php` | Covered | NV | NV | NV |
| Reports: attendance and assistance tabs | `/reports` | `reports/index.blade.php` | Covered | NV | NV | NV |
| NFC lookup | `/nfc` | `nfc/lookup.blade.php` | Covered | NV | NV | NV |
| Profile settings | `/settings/profile` | `livewire/settings/profile.blade.php` | Covered | NV | NV | NV |
| Security, password, passkeys and two-factor/recovery dialogs | `/settings/security` | `livewire/settings/security.blade.php` | Covered | NV | NV | NV |
| Appearance: light/dark/system | `/settings/appearance` | `livewire/settings/appearance.blade.php` | Covered | NV | NV | NV |

### Shared, embedded and edge pages

| Page / component | Role | Coverage | Light OK | Dark OK | Mobile OK |
|---|---|---|---|---|---|
| Sidebar/header/mobile navigation, user menu and notifications | Both | Covered | NV | NV | NV |
| Alternate existing header layout | Both; not currently routed by app wrapper | Covered | NV | NV | NV |
| Registry search, result fragments, pagination, empty/error/loading states | Both | Covered | NV | NV | NV |
| Assignment dialog and Add Caseworker dialog | Director | Covered | NV | NV | NV |
| Archive confirmation/selection UI and restore dialog | Director | Covered | NV | NV | NV |
| NFC registration/replace/manual-entry dialog | Director; assigned profile actions for Caseworker | Covered | NV | NV | NV |
| Attendance records fragment, scanner feedback, retry and profile card | Both | Covered; semantic feedback retained | NV | NV | NV |
| Delete-account confirmation and two-factor recovery codes | Both, when available | Covered via settings layout | NV | NV | NV |
| Account status | Restricted account | Covered via existing auth theme | NV | NV | NV |
| Login | Guest/staff entry | Covered via existing auth theme | NV | NV | NV |
| Forgot password | Guest/staff entry | Covered via existing auth theme | NV | NV | NV |
| Reset password | Guest/staff entry | Covered via existing auth theme | NV | NV | NV |
| Confirm password | Authenticated | Covered via existing auth theme | NV | NV | NV |
| Two-factor challenge | Staff sign-in | Covered via existing auth theme | NV | NV | NV |
| Verify email | Authenticated unverified | Covered via existing auth theme | NV | NV | NV |
| Alternate auth card and split layouts | Shared, not default auth wrapper | Covered; geometry retained | NV | NV | NV |
| Attendance PDF export | Both, existing policy applies | Standalone print palette updated | NV (print) | N/A | N/A |
| Assistance PDF export | Both, existing policy applies | Standalone print palette updated | NV (print) | N/A | N/A |
| Attendance/assistance CSV exports and activity attendance export | Both | Unchanged data downloads; no CSS | N/A | N/A | N/A |
| `/settings` redirect; protected photos; JSON suggestion/polling endpoints | Existing permissions | No standalone UI; consumers covered | N/A | N/A | N/A |
| 403 / 404 / 419 / 500 | Shared | No project error templates exist; vendor responses left unchanged | NV | NV | NV |
| Registration, complete registration, awaiting approval | Public registration flow | Excluded: separate public registration theme | N/A | N/A | N/A |
| Public homepage and public registration dialog | Public | Unchanged, excluded | N/A | N/A | N/A |

## Validation and limitations

- All **144 existing JavaScript tests passed**, with zero failures/skips. Coverage includes notification polling, registry search/filter/selection, caseload refresh, NFC registration, attendance ordering/feedback, login and registration form handling using mocks.
- `npm run build -- --config <temporary-config>` succeeded using the existing Vite configuration with only `envDir` pointed at an empty temporary directory. No project `.env` was read by Vite. The final build emits production assets under `public/build`. A non-fatal existing warning says optional `fontaine` is unavailable; no dependency was installed.
- Source comparison verifies all existing layout CSS declarations are unchanged after removing the new color-variable wrappers. Director four-card/three-column and Caseworker three-card/two-column layouts and their SVG artwork markup are unchanged.
- Blade comparison verifies original directives, bindings, IDs, hooks, dynamic values and content, allowing only color substitutions, the staff body marker and the requested shared logo replacement. Original `public/images/branding/rlc-logo.png` bytes are unchanged; images use `object-fit: contain` with a white backing for both themes.
- The existing dashboard browser check was attempted using prior **synthetic** fixtures produced by `DashboardBrandingTest`, with current compiled assets. The harness intercepts requests and blocks writes/external access. Edge timed out at `Page.enable`, before navigating to a fixture. There is therefore **no fresh browser evidence** for screenshots, per-page contrast, viewport overflow, navigation, keyboard/focus, menus, theme switching or JS console errors. Existing screenshots/results in `docs/qa/staff-branding` predate this change and are not evidence for this revision.
- Fresh PHP fixture rendering and PHP feature/PDF tests are unavailable: this session is denied access to the Herd PHP directory; accessible XAMPP PHP is 8.2.12, while Composer requires PHP >=8.4.1. No platform check was bypassed and no live server was contacted. Every NV cell above remains unverified for these reasons.
- `git status` / `git diff --stat` are unavailable: this folder is not a Git repository. Before/after SHA-256 comparisons instead verified unchanged backend source, route/policy/service files, migrations, configuration source and dependency manifests/lockfiles. No database connection, records, email delivery or real application action was used; no database or live cache files were modified by this work.
- Public CSS, JavaScript, Blade components, public registration presentation and original branding assets match their baseline hashes. The shared app CSS changes are restricted by staff tokens with legacy fallback colors outside that scope.

## Remaining color literals and shared-layout effects

Legacy `violet-*` utility names remain to preserve markup and dynamic JS-generated class names; their values are overridden only within the staff theme. Legacy purple/cool hex values in CSS remain solely as custom-property fallbacks outside the staff scope. Green/red/amber status literals remain intentionally. `rlcp-staff-theme.css` is a pre-existing **unimported** provisional stylesheet and was left untouched; the active centralized theme is `staff-brand.css`. PDF uses explicit print-safe hex colors because browser CSS variables are not a dependable Dompdf feature.

The intentional shared-layout effect is that all staff pages now inherit the same warm canvas, neutral utilities, focus colors, primary controls and dropdown palette. Staff-themed login/password/verification/account-status layouts receive those colors and the original logo; public registration does not. No sidebar/menu item, public module or error page was added.

## Changed source files

- `resources/css/account-notifications.css`
- `resources/css/attendance-workspace.css`
- `resources/css/beneficiary-registry.css`
- `resources/css/rlcp-dashboard.css`
- `resources/css/staff-brand.css`
- `resources/views/beneficiaries/archived.blade.php`
- `resources/views/components/app-logo-icon.blade.php`
- `resources/views/reports/pdf.blade.php`
- `resources/views/layouts/app/header.blade.php`
- `resources/views/layouts/auth/card.blade.php`
- `resources/views/layouts/auth/simple.blade.php`
- `resources/views/layouts/auth/split.blade.php`
- `tests/browser/dashboard-branding.test.mjs`

Also generated: production build assets, this report, and `docs/rlcp-branding-verification.json`. The browser regression assertion now expects staff modules to inherit branding while retaining geometry, replacing its previous dashboard-only color-isolation assumption.
