import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { registrationEmailForm } from "../../resources/js/registration-email.js";

const message =
    "If this email is available, a verification email has been queued. Check your inbox shortly.";
const reply = (status, data = {}) => ({ status, json: async () => data });

function harness(request, valid = true) {
    const calls = [];
    let focused = false;
    const form = {
        action: "http://192.0.2.41:8000/register",
        reportValidity: () => valid,
        elements: {
            namedItem: () => ({
                focus: () => {
                    focused = true;
                },
            }),
        },
    };
    const data = { email: "synthetic@example.invalid", _token: "synthetic-csrf" };
    const model = registrationEmailForm({
        request: (...args) => {
            calls.push(args);
            return request(...args);
        },
        formData: () => data,
    });
    return {
        model,
        calls,
        form,
        data,
        submit: () => model.submit({ currentTarget: form }),
        focused: () => focused,
    };
}

test("loading starts immediately and repeated submissions make only one request", async () => {
    let finish;
    const h = harness(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    const pending = h.submit();
    assert.equal(h.model.submitting, true);
    assert.equal(h.model.feedback, "");
    await h.submit();
    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0][1].body, h.data);
    assert.equal(h.calls[0][1].headers.Accept, "application/json");
    assert.equal(h.calls[0][1].credentials, "same-origin");
    assert.equal(h.calls[0][1].redirect, "error");
    finish(reply(202, { message }));
    await pending;
    assert.equal(h.model.submitting, false);
    assert.equal(h.model.hasRequested, true);
    assert.equal(h.model.feedback, message);
    assert.doesNotMatch(h.model.feedback, /delivered/i);
});

test("network failure restores controls, reports uncertainty, and never retries or exposes exception details", async () => {
    const h = harness(async () => {
        throw new Error("sensitive transport detail");
    });
    await h.submit();
    assert.equal(h.model.submitting, false);
    assert.equal(h.model.hasRequested, false);
    assert.equal(h.calls.length, 1);
    assert.match(h.model.error, /could not confirm.*queued/);
    assert.doesNotMatch(h.model.error, /sensitive/);
    assert.equal(h.model.feedback, "");
});

test("validation failure restores controls and focuses the email input", async () => {
    const h = harness(async () =>
        reply(422, { errors: { email: ["Enter a valid email address."] } }),
    );
    await h.submit();
    assert.equal(h.model.submitting, false);
    assert.equal(h.model.error, "Enter a valid email address.");
    assert.equal(h.focused(), true);
    assert.equal(h.model.hasRequested, false);
});

test("queue failure uses the safe server validation message without claiming delivery", async () => {
    const h = harness(async () =>
        reply(422, {
            errors: {
                registration: ["We could not queue a verification email. Please try again later."],
            },
        }),
    );
    await h.submit();
    assert.match(h.model.error, /could not queue/);
    assert.equal(h.model.submitting, false);
    assert.equal(h.model.feedback, "");
});

test("rate limits, expired sessions, and server errors restore controls without retrying", async () => {
    for (const [status, expected] of [
        [429, /Too many/],
        [419, /session has expired/],
        [503, /could not be submitted/],
    ]) {
        const h = harness(async () => reply(status, { message: "Untrusted server detail" }));
        await h.submit();
        assert.equal(h.model.submitting, false);
        assert.match(h.model.error, expected);
        assert.equal(h.calls.length, 1);
        assert.doesNotMatch(h.model.error, /Untrusted/);
    }
});

test("invalid success responses never show queued confirmation", async () => {
    const h = harness(async () => reply(202, {}));
    await h.submit();
    assert.equal(h.model.feedback, "");
    assert.equal(h.model.hasRequested, false);
    assert.equal(h.model.submitting, false);
    assert.match(h.model.error, /could not confirm/);
});

test("native invalid forms do not submit and an explicit retry after failure is allowed", async () => {
    const invalid = harness(async () => reply(202, { message }), false);
    await invalid.submit();
    assert.equal(invalid.calls.length, 0);
    assert.equal(invalid.model.submitting, false);
    const h = harness(async () => reply(503));
    await h.submit();
    await h.submit();
    assert.equal(h.calls.length, 2);
});

test("form preserves native POST, accessible busy/error feedback, animated dots, and reduced motion", () => {
    const view = readFileSync(
        new URL("../../resources/views/components/registration/email-form.blade.php", import.meta.url),
        "utf8",
    );
    const css = readFileSync(
        new URL("../../resources/css/registration-email.css", import.meta.url),
        "utf8",
    );
    assert.match(view, /method="POST"/);
    assert.match(view, /@csrf/);
    assert.match(view, /data-registration-form/);
    assert.match(view, /data-registration-loading hidden/);
    assert.match(view, /aria-busy="false"/);
    assert.match(view, /aria-live="polite" aria-atomic="true"/);
    assert.match(view, /role="alert"/);
    assert.match(view, /registration-loading-dots" aria-hidden="true"/);
    const direct = readFileSync(new URL("../../resources/views/livewire/auth/register.blade.php", import.meta.url), "utf8");
    const dialog = readFileSync(new URL("../../resources/views/components/registration/dialog.blade.php", import.meta.url), "utf8");
    assert.match(direct, /x-registration.email-form/);
    assert.match(dialog, /x-registration.email-form/);
    assert.match(css, /@keyframes registration-dot/);
    assert.match(css, /prefers-reduced-motion: reduce/);
});
