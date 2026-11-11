# Mobile LAN login 419

Confirmed October 22, 2026, with the user's private phone sign-ins and a temporary local-only observer. Only request status and cookie/CSRF presence/match booleans were reported. No credentials, cookie/token values, request bodies or session payloads were recorded by the observer.

| Philippine time (UTC+08:00) | Evidence |
| --- | --- |
| 08:11:39 | Phone GET `/login`: 200; valid decrypted session cookie; rendered form CSRF matches its session. |
| 08:11:43 | Phone POST `/login`: 302; session rotates. Read-only session metadata confirms an authenticated session at this time. |
| 08:11:44 | Another phone POST `/login`: 419, carrying the original GET's session cookie; submitted CSRF is present but mismatches the now-recreated anonymous session. No duplicate cookie names or origin mismatch. |

The repeated pre-login submission arrives after normal authentication/session rotation. Its stale session/token produces the 419 and can replace the successful login's cookie. The exact trigger of the second submission (repeated tapping, autofill, or browser retry) was not captured. Laravel correctly retains CSRF enforcement; the fix prevents normal repeat form submissions rather than weakening that check.

The login form now allows one pending native submission, immediately disables its existing submit button and reports busy state. Canceled submissions restore controls. Browser history restoration blocks stale submissions while fetching a fresh GET form instead of reusing a stale token or replaying credentials. New validation-response pages initialize an enabled form. Ordinary native submission, Fortify session rotation, approval/role behavior and SMTP remain unchanged. Direct scripted `form.submit()` or external HTTP clients can bypass client-side guards; backend CSRF remains enforced.

Retained implementation changes: `resources/js/login.js`, its import in `resources/js/app.js`, and the login-only Alpine bindings in `resources/views/livewire/auth/login.blade.php`, plus rebuilt `public/build` assets. Regression coverage is in `tests/js/login.test.mjs` and `tests/Feature/Auth/LoginSessionIntegrityTest.php`. No lasting PHP application/controller/middleware change was needed.

The separate stale local APP_URL was corrected to `http://192.168.254.113:8000` and configuration rebuilt with PHP 8.4.25 plus `registry.ini`. Only APP_URL and its derived public-file/passkey origins changed; mail, session and database settings were compared unchanged. The running mail helper was not restarted; restart it gracefully before testing generated email links. No email was sent by this work.

Validation:

- Baseline: 35 authentication/approval tests, 701 assertions passed.
- Final focused PHP checks: 38 passed, 794 assertions. The three new database-session cases verify invalid-credential recovery, Director/Caseworker dashboard routing, logout/login, and rejection of a replayed pre-login cookie/token after successful authentication. Request services are reset between simulated requests to match real request lifetimes.
- All 124 JavaScript unit tests passed, including six new submission/cancellation/history/recovery cases. Pint passed for the new PHP test. Assets rebuilt successfully; the existing optional fontaine warning did not require a dependency change.
- Credential-free Edge check at a mobile viewport loaded the rebuilt app asset and actual Alpine form. Two submit events produced one intercepted POST, immediate disabled/busy state, and retained CSRF. Mocked validation navigation restored controls; a simulated history-restoration event issued GET only. Every POST was intercepted before reaching Laravel; external resources were blocked. This is not real phone confirmation.
- PHP regressions use the existing pre-boot guards, SQLite `:memory:`, fake mail/notifications, and temporary storage. Their database-session override uses that same isolated SQLite connection. Neither the PHP regressions nor JavaScript unit tests accessed the live database. Separate LAN checks used ordinary anonymous GETs and authorized read-only session metadata; the final browser check intercepted every POST.
- The temporary observer and its private comparison state were deleted. `AppServiceProvider.php` was verified byte-for-byte restored to its pre-probe SHA-256. No session or account cleanup was performed; no CSRF exemptions, session-driver changes, role changes or automatic POST retries were added.

Phone verification: after opening the current LAN login URL to load the rebuilt form and privately entering credentials, the user confirmed going directly to their dashboard. The phone 419 was not reproduced in that verification. Invalid-credential recovery, logout/login, and both staff roles' routing were verified in isolated automated tests; those additional flows were not separately repeated on the real phone. No web or queue worker restart was needed for the login changes. The mail worker remains untouched and needs a graceful restart before testing generated email links with the corrected APP_URL.

Cleanup limitation: automatic approval review rejected deletion of the following stopped diagnostic Edge profiles with only the reason `blocked by policy`. These are isolated browser profiles, not application/session/business records; the observer files are already removed. No diagnostic browser process remains running.

- `storage/framework/testing/mobile-login-profile-myQT1w`
- `storage/framework/testing/mobile-login-profile-zeRx4N`
- Earlier diagnosis also reported the same cleanup block for `C:/Users/Louise Angelo Encina/AppData/Local/Temp/rlcp-mobile419-CQu9CZ`.
