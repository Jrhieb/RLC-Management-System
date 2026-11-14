import assert from "node:assert/strict";
import test from "node:test";
import { delay, registrationBrowser } from "./support/registration-browser.mjs";

const binary = process.env.REGISTRATION_UI_BROWSER;
const fixtures = process.env.REGISTRATION_UI_FIXTURES;
const confirmation = "If this email is available, a verification email has been queued. Check your inbox shortly.";

test("registration-login-fallback opens the staff overlay", { skip: !binary || !fixtures, timeout: 30000 }, async () => {
    const ui = await registrationBrowser(binary, fixtures);
    try {
        await ui.navigate('/register');
        await ui.evaluate("document.querySelector('.registration-login a').click()");
        await ui.until("document.querySelector('#staff-login-dialog')?.open");
        assert.equal(await ui.evaluate('location.pathname'), '/');
        assert.equal(await ui.evaluate('document.activeElement.id'), 'staff-login-email');
        assert.equal(await ui.evaluate("document.querySelector('#staff-login-dialog').matches(':modal')"), true);
        assert.equal(ui.stats.emailPosts, 0);
    } finally { await ui.close(); }
});

test("Caseworker registration presentation, using only intercepted requests", { skip: !binary || !fixtures, timeout: 120000 }, async (t) => {
    const ui = await registrationBrowser(binary, fixtures);
    const { evaluate, until, command, key } = ui;
    const open = async () => {
        await evaluate("document.querySelector('footer [data-registration-open]').click()");
        await until("document.querySelector('[data-registration-dialog]').open");
        await delay(200);
    };
    const close = async () => {
        await key("Escape");
        await until("!document.querySelector('[data-registration-dialog]').open && !document.body.classList.contains('registration-scroll-locked')");
    };
    const submit = () => evaluate("document.querySelector('[data-registration-form]').requestSubmit()");
    try {
        await ui.viewport(1440);
        await ui.navigate();
        await until("document.querySelector('[data-registration-dialog]').dataset.initialized === 'true'");
        await t.test("login switches to registration without navigation or sending email", async () => {
            await evaluate("document.querySelector('[data-staff-login-open]').click()");
            await until("document.querySelector('#staff-login-dialog').open");
            await evaluate("document.querySelector('#staff-login-dialog [name=password]').value = 'Synthetic-Secret'; document.querySelector('#staff-login-dialog [data-registration-open]').click()");
            await until("document.querySelector('[data-registration-dialog]').open && !document.querySelector('#staff-login-dialog').open");
            await delay(200);
            assert.equal(await evaluate("location.pathname"), "/");
            assert.equal(await evaluate("document.querySelectorAll('dialog[open]').length"), 1);
            assert.equal(await evaluate("document.activeElement.id"), "registration-email");
            assert.equal(await evaluate("document.querySelector('#staff-login-dialog [name=password]').value"), "");
            assert.equal(ui.stats.emailPosts, 0);
            await evaluate("document.querySelector('[data-registration-dialog] [data-staff-login-open]').click()");
            await until("document.querySelector('#staff-login-dialog').open && !document.querySelector('[data-registration-dialog]').open");
            assert.equal(await evaluate("location.pathname"), "/");
            assert.equal(await evaluate("document.activeElement.id"), "staff-login-email");
            assert.equal(await evaluate("document.querySelector('main').inert"), false);
            assert.equal(await evaluate("document.body.classList.contains('registration-scroll-locked')"), false);
            await evaluate("document.querySelector('#staff-login-dialog [data-registration-open]').click()");
            await until("document.querySelector('[data-registration-dialog]').open && !document.querySelector('#staff-login-dialog').open");
            await close();
            assert.equal(await evaluate("document.activeElement.matches('[data-staff-login-open]')"), true);
        });
        await t.test("real fallback link, keyboard opening, focus containment, Escape and scroll restoration", async () => {
            assert.equal(await evaluate("document.querySelector('footer [data-registration-open]').getAttribute('href').endsWith('/register')"), true);
            await evaluate("document.querySelector('footer [data-registration-open]').focus()");
            const scrollBefore = await evaluate("scrollY");
            await key("Enter");
            await until("document.querySelector('[data-registration-dialog]').open");
            assert.equal(await evaluate("document.activeElement.id"), "registration-email");
            assert.equal(await evaluate("document.querySelector('main').inert"), true);
            assert.equal(await evaluate("document.querySelector('[data-registration-dialog]').matches(':modal')"), true);
            assert.equal(await evaluate("getComputedStyle(document.body).position"), "fixed");
            assert.equal(await evaluate("document.querySelector('[data-registration-dialog]').getAttribute('aria-labelledby')"), "registration-team-title");
            await evaluate("document.querySelector('[data-registration-close]').focus()");
            await key("Tab", 8);
            assert.equal(await evaluate("new URL(document.activeElement.href).pathname"), "/login");
            await key("Tab");
            assert.equal(await evaluate("document.activeElement.matches('[data-registration-close]')"), true);
            assert.equal(await evaluate("getComputedStyle(document.activeElement).outlineStyle"), "solid");
            await close();
            assert.equal(await evaluate("document.activeElement.matches('footer [data-registration-open]')"), true);
            assert.equal(await evaluate("document.querySelector('main').inert"), false);
            assert.ok(Math.abs(await evaluate("scrollY") - scrollBefore) < 2);
            assert.equal(ui.stats.emailPosts, 0);
            const prevented = await evaluate(`(() => { let intercepted; document.addEventListener('click', event => { intercepted = event.defaultPrevented; event.preventDefault(); }, { once: true }); document.querySelector('footer [data-registration-open]').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true })); return intercepted; })()`);
            assert.equal(prevented, false, "Modified clicks retain normal new-tab behavior");
        });

        await t.test("desktop/tablet/mobile layout, clear close button, backdrop and reduced motion", async () => {
            await evaluate("scrollTo({top: 0, behavior: 'instant'})");
            await open();
            for (const [width, height] of [[1440, 950], [768, 1024], [390, 844], [320, 568]]) {
                await ui.viewport(width, height);
                const layout = await evaluate(`(() => { const dialog = document.querySelector('dialog'); const box = dialog.getBoundingClientRect(); return { columns: getComputedStyle(dialog.querySelector('.registration-panel')).gridTemplateColumns.split(' ').length, overflow: dialog.scrollWidth > dialog.clientWidth, left: box.left, right: box.right, height: box.height, viewport: innerWidth, allImages: [...dialog.querySelectorAll('img')].every(image => image.complete && image.naturalWidth > 0) }; })()`);
                assert.equal(layout.columns, width <= 700 ? 1 : 2);
                assert.equal(layout.overflow, false);
                assert.ok(layout.left >= 0 && layout.right <= layout.viewport && layout.height <= height);
                assert.equal(layout.allImages, true);
            }
            await ui.viewport(1440, 950);
            await evaluate("document.querySelector('dialog').scrollTop = 0");
            assert.match(await evaluate("getComputedStyle(document.querySelector('dialog'), '::backdrop').backdropFilter"), /blur/);
            await ui.screenshot("desktop");
            await ui.viewport(390, 844);
            await evaluate("document.querySelector('dialog').scrollTop = 0");
            await ui.screenshot("mobile");
            await evaluate("document.querySelector('[data-registration-form]').scrollIntoView({block: 'center'})");
            await ui.screenshot("mobile-form");
            const closeVisible = await evaluate("(() => { const box = document.querySelector('[data-registration-close]').getBoundingClientRect(); return box.top >= 0 && box.bottom <= innerHeight; })()");
            assert.equal(closeVisible, true);
            await command("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "reduce" }] });
            assert.equal(await evaluate("getComputedStyle(document.querySelector('dialog')).animationName"), "none");
            assert.equal(await evaluate("getComputedStyle(document.querySelector('.registration-loading-dots span')).animationName"), "none");
            await close();
            await open();
            assert.equal(await evaluate("getComputedStyle(document.querySelector('dialog')).animationName"), "none");
        });

        await t.test("validation, pending close/reopen, repeated clicks and accepted queue confirmation", async () => {
            await evaluate("document.getElementById('registration-email').value = 'invalid'");
            await submit();
            await delay(50);
            assert.equal(ui.stats.emailPosts, 0);
            await evaluate("document.getElementById('registration-email').value = 'synthetic@example.invalid'");
            await submit();
            await until("document.querySelector('[data-registration-form]').getAttribute('aria-busy') === 'true'");
            assert.equal(await evaluate("document.querySelector('[data-test=registration-email-button]').disabled"), true);
            assert.equal(await evaluate("document.querySelector('[data-registration-loading]').hidden"), false);
            await submit(); await submit();
            await delay(50);
            assert.equal(ui.stats.emailPosts, 1);
            await close();
            assert.match(await evaluate("document.querySelector('[data-registration-notice-text]').textContent"), /does not cancel/);
            await open();
            assert.equal(await evaluate("document.querySelector('[data-test=registration-email-button]').disabled"), true);
            await submit();
            await close();
            await ui.reply(202, { message: confirmation });
            assert.equal(ui.stats.emailPosts, 1);
            assert.equal(await evaluate("document.querySelector('[data-registration-notice-text]').textContent"), confirmation);
            await open();
            assert.equal(await evaluate("document.querySelector('[data-registration-feedback]').textContent"), confirmation);
            assert.equal(await evaluate("document.querySelector('[data-registration-idle]').textContent"), "Resend verification code");
            assert.equal(await evaluate("document.querySelector('[data-registration-code-form]').hidden"), false);
            assert.equal(await evaluate("document.querySelector('[autocomplete=one-time-code]').getAttribute('maxlength')"), "8");
            assert.equal(await evaluate("document.querySelector('.registration-steps [aria-current]').textContent.includes('Verify Email')"), true);
            assert.equal(await evaluate("document.querySelector('[data-test=registration-email-button]').disabled"), false);
            assert.equal(ui.stats.csrfPresent, true);
        });

        await t.test("safe validation, dispatch failure, session/rate-limit and network errors with no automatic retry", async () => {
            for (const [status, data, expected] of [
                [422, { errors: { email: ["Enter a valid email address."] } }, /valid email/],
                [503, { message: "Private transport detail must not be shown" }, /could not be submitted/],
                [429, {}, /Too many/],
                [419, {}, /session has expired/],
                ["network", {}, /could not confirm whether your request was queued/],
                [202, {}, /could not confirm/],
            ]) {
                await submit();
                await ui.reply(status, data);
                assert.match(await evaluate("document.querySelector('[data-registration-error]').textContent"), expected);
                assert.equal(await evaluate("document.querySelector('[data-registration-feedback]').textContent"), "");
                assert.equal(await evaluate("document.querySelector('[data-test=registration-email-button]').disabled"), false);
            }
            const count = ui.stats.emailPosts;
            await close(); await open(); await delay(100);
            assert.equal(ui.stats.emailPosts, count);
            assert.equal(await evaluate("localStorage.length + sessionStorage.length"), 0);
            assert.equal(await evaluate("location.pathname"), "/");
        });

        await t.test("JavaScript-disabled homepage fallback reaches the native registration form", async () => {
            await close();
            const count = ui.stats.emailPosts;
            await command("Emulation.setScriptExecutionDisabled", { value: true });
            await ui.navigate();
            await evaluate("document.querySelector('footer [data-registration-open]').click()");
            await until("location.pathname === '/register' && document.readyState === 'complete'");
            assert.equal(await evaluate("document.querySelector('[data-registration-form]').getAttribute('method')"), "POST");
            assert.equal(await evaluate("document.querySelector('dialog') === null"), true);
            assert.equal(await evaluate("document.querySelector('[data-test=registration-email-button]').disabled"), false);
            assert.equal(ui.stats.emailPosts, count);
            await command("Emulation.setScriptExecutionDisabled", { value: false });
        });

        await t.test("direct registration uses the same handler and completion preserves native errors/password clearing", async () => {
            await ui.navigate("/register");
            await evaluate("document.getElementById('registration-email').value = 'direct@example.invalid'");
            await submit(); await ui.reply(202, { message: confirmation });
            assert.equal(await evaluate("document.querySelector('[data-registration-feedback]').textContent"), confirmation);
            await ui.viewport(1440, 1000);
            await ui.navigate("/register/complete");
            await ui.screenshot("completion-desktop");
            assert.equal(await evaluate("document.querySelectorAll('input[autocomplete=new-password]').length"), 2);
            await evaluate("document.querySelector('[data-password-toggle]').click()");
            assert.equal(await evaluate("document.querySelector('input[name=password]').type"), "text");
            await evaluate("document.querySelector('[data-password-toggle]').click(); document.querySelector('input[name=name]').value = 'Synthetic Caseworker'; document.querySelector('input[name=password]').value = 'Synthetic-Password-123!'; document.querySelector('input[name=password_confirmation]').value = 'Different-Password-123!'; document.querySelector('[data-registration-completion]').requestSubmit()");
            await until("document.readyState === 'complete' && !!document.querySelector('input[name=password][aria-invalid=true]')");
            assert.equal(await evaluate("[...document.querySelectorAll('input[autocomplete=new-password]')].every(input => input.value === '')"), true);
            assert.equal(await evaluate("location.pathname"), "/register/complete");
            await evaluate("document.querySelector('input[name=password]').value = 'Synthetic-Password-123!'; window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }))");
            assert.equal(await evaluate("document.querySelector('input[name=password]').value"), "");
            await ui.navigate("/register/awaiting-approval");
            assert.equal(await evaluate("document.body.textContent.includes('Your account is awaiting Director approval.')"), true);
        });
        assert.deepEqual(ui.errors, []);
        await ui.report();
    } finally { await ui.close(); }
});
