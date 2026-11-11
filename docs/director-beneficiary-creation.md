Beneficiary creation is Director-only as of October 13, 2026. The existing `BeneficiaryPolicy::create` requires both staff eligibility and the Director role. POST store and creation NFC-check gates enforce that permission before validation, uploads, or persistence. Approved Caseworker GET create requests redirect to their Registry with "Only Directors can add beneficiaries." Director GET requests still use the existing create gate.

Approved Caseworkers receive 403 for forged submissions, including requests without an assignment, with their own assignment ID, and with malformed fields. Their assigned-profile, edit, photo, assistance, and NFC permissions remain governed by the existing ownership rules. Guests keep login redirects for HTML requests and 401 for JSON requests. Pending, rejected, unverified, and applicant restrictions are preserved.

Director registration retains optional assignment, private photo uploads, optional NFC cards, validation recovery, transaction rollback, and count-only assignment notices. Existing creation buttons already use the policy; registry fragment metadata now also omits the creation URL for unauthorized users. Empty Caseworker registries explain that a Director can assign records. Folder artwork, layout, and styles are intact.

Files changed for this focused task:

| File | Change |
| --- | --- |
| [app/Policies/BeneficiaryPolicy.php](../app/Policies/BeneficiaryPolicy.php) | Director-only creation using existing staff eligibility. |
| [app/Http/Controllers/BeneficiaryController.php](../app/Http/Controllers/BeneficiaryController.php) | Redirect approved Caseworker creation GET requests with the restriction notice; retain the store gate. |
| [resources/views/beneficiaries/index.blade.php](../resources/views/beneficiaries/index.blade.php) | Authorized creation metadata, Caseworker empty-state text, and restriction flash message. |
| [tests/Feature/BeneficiaryCreateCaseworkerTest.php](../tests/Feature/BeneficiaryCreateCaseworkerTest.php) | Creation denial matrix, unchanged records/files/notices, guest redirects, assigned access, and UI links. |
| [tests/Feature/BeneficiaryCreateNfcTest.php](../tests/Feature/BeneficiaryCreateNfcTest.php) | Director registration fixtures and updated creation permission matrix; transaction and recovery coverage retained. |
| [tests/Feature/BeneficiaryAssignmentNotificationTest.php](../tests/Feature/BeneficiaryAssignmentNotificationTest.php) | Denied Caseworker creation creates no assignment notices. |
| [tests/Feature/BeneficiaryCaseworkerAssignmentTest.php](../tests/Feature/BeneficiaryCaseworkerAssignmentTest.php) | Caseworker direct creation URL redirects with the restriction notice. |
| [tests/Feature/BeneficiaryRegistryTest.php](../tests/Feature/BeneficiaryRegistryTest.php) | Creation links absent from populated, empty, and fragment Caseworker views. |
| [tests/Feature/AssignedBeneficiaryAccessTest.php](../tests/Feature/AssignedBeneficiaryAccessTest.php) | Replace former unassigned Caseworker creation expectation with denial and unchanged records. |
| [tests/browser/assigned-access.test.mjs](../tests/browser/assigned-access.test.mjs) | Assert creation links remain for Directors and are absent for Caseworkers across synthetic views. |
| [docs/assigned-beneficiary-access.md](assigned-beneficiary-access.md) | Replace the deferred creation decision with the current permission. |
| [docs/director-beneficiary-creation.md](director-beneficiary-creation.md) | Focused change report and verification steps. |

Checks for the Registry redirect follow-up:

| Check | Result |
| --- | --- |
| Focused creation, registry, assignment, notices, assigned-access, and account approval tests | 246 passed; 4,630 assertions. Includes plain and forged create URLs, the visible Registry notice, zero-assignment states, hidden creation links, unchanged records/files after denied POSTs, Director registration, and guest/restricted-account behavior. |
| Pint | The four PHP files changed by the redirect follow-up pass `--test`. |

The redirect follow-up changes the controller, Registry view, `BeneficiaryCreateCaseworkerTest.php`, `BeneficiaryCaseworkerAssignmentTest.php`, `AssignedBeneficiaryAccessTest.php`, and the two documentation files listed above. Its test output is `storage/framework/testing/director-creation-redirect-focused.json`.

Earlier checks for the initial Director-only creation change, before the GET redirect follow-up:

| Check | Result |
| --- | --- |
| Focused creation, registry, assignment, notices, and assigned-access tests | 213 passed; 3,881 assertions. |
| Full PHPUnit regression, including regenerated synthetic browser fixtures | 589 passed; 7,426 assertions. |
| Existing JavaScript tests | 110 passed. |
| Installed Edge browser checks | 28 passed across seven synthetic views, light/dark themes, and 375/1,440px widths. Creation-link visibility, empty states, folder art, and viewport bounds checked. |
| Pint | All seven changed PHP files pass `--test`. |
| Browser harness syntax | `node --check` passed. |

All database fixtures use guarded SQLite `:memory:`. The shared bootstrap checks the connection before database refresh and requires isolated mail, sessions, cache, and queues. Mail and notifications are faked; upload checks use fake local/public disks. No real records, emails, migration files, dependencies, or application configuration were changed.

Reproduce the full database checks using the existing PHP runtime:

```powershell
$creationRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
$env:APP_CONFIG_CACHE = Join-Path $env:TEMP 'rlcp-access-no-config.php'
$env:APP_ROUTES_CACHE = Join-Path $env:TEMP 'rlcp-access-no-routes.php'
& (Join-Path $creationRuntime 'php.exe') -c (Join-Path $creationRuntime 'registry.ini') vendor/bin/phpunit --no-progress
```

For browser fixtures, set `$env:ASSIGNED_ACCESS_UI_FIXTURES = '1'` before the same test command. Then set `$env:ASSIGNED_ACCESS_UI_BROWSER = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'` and run `node --test tests/browser/assigned-access.test.mjs`. This renderer uses an isolated workspace profile, strips scripts, and blocks HTTP/HTTPS resources. Browser execution required the existing sandbox exception for the headless renderer. Logs are in `storage/framework/testing/director-creation-{phpunit,focused}.json`, `director-creation-js.txt`, and `director-creation-browser.txt`.

Read-only browser verification steps:

1. As a verified Director, open the [Registry](http://localhost:8000/beneficiaries) and [Create form](http://localhost:8000/beneficiaries/create). Confirm Add Beneficiary is available and the form includes optional Caseworker assignment, photo upload, and NFC registration. Verify saves and validation recovery through the synthetic tests above.
2. As an approved Caseworker, review [Registry](http://localhost:8000/beneficiaries), [folders](http://localhost:8000/beneficiaries?tab=caseloads), [My Caseload](http://localhost:8000/my-caseload), and [Dashboard](http://localhost:8000/dashboard). Confirm registration links are absent and assigned profiles remain accessible. Open the [direct creation URL](http://localhost:8000/beneficiaries/create) and confirm the redirect to Registry displaying "Only Directors can add beneficiaries."
3. With no assignments, confirm the Registry has an empty state with Director assignment guidance and no creation button. Signed out, confirm the creation URL redirects to login; pending/rejected/unverified accounts retain their existing approval/verification restrictions.

Synthetic screenshots: [Caseworker Registry on mobile](../storage/framework/testing/assigned-access-ui/caseworker-registry-light-375.png), [empty Caseworker Registry](../storage/framework/testing/assigned-access-ui/empty-registry-light-1440.png), and [Director folders](../storage/framework/testing/assigned-access-ui/director-caseloads-light-1440.png).

Live authenticated browser sessions and physical NFC hardware were not exercised. Authentication, submitted requests, uploads, and transaction behavior were verified through Laravel feature tests; the browser checks cover rendered synthetic pages.
