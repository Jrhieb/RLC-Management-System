import assert from "node:assert/strict";
import test from "node:test";
import { loginForm } from "../../resources/js/login.js";

const submission = () => ({
    defaultPrevented: false,
    preventDefault() {
        this.defaultPrevented = true;
    },
});

test("rapid repeated login submissions allow only the first native POST", async () => {
    const model = loginForm();
    const first = submission();
    const repeat = submission();
    model.submit(first);
    model.submit(repeat);
    await Promise.resolve();
    assert.equal(first.defaultPrevented, false);
    assert.equal(repeat.defaultPrevented, true);
    assert.equal(model.submitting, true);
});

test("another listener canceling the first submission restores controls", async () => {
    const model = loginForm();
    const canceled = submission();
    model.submit(canceled);
    canceled.preventDefault();
    await Promise.resolve();
    assert.equal(model.submitting, false);
    const retry = submission();
    model.submit(retry);
    assert.equal(retry.defaultPrevented, false);
    assert.equal(model.submitting, true);
});

test("an already canceled event does not start a login submission", () => {
    const model = loginForm();
    const canceled = submission();
    canceled.preventDefault();
    model.submit(canceled);
    assert.equal(model.submitting, false);
});

test("history restoration blocks stale submissions while fetching a fresh GET form", () => {
    const destinations = [];
    const model = loginForm({ freshLogin: (url) => destinations.push(url) });
    model.$root = { action: "http://192.0.2.41:8000/login" };
    model.submitting = true;
    model.restore({ persisted: true });
    assert.equal(model.submitting, true);
    assert.deepEqual(destinations, ["http://192.0.2.41:8000/login"]);
    const staleSubmission = submission();
    model.submit(staleSubmission);
    assert.equal(staleSubmission.defaultPrevented, true);
});

test("initial pageshow cannot unlock an in-flight submission", () => {
    let navigations = 0;
    const model = loginForm({ freshLogin: () => navigations++ });
    model.submitting = true;
    model.restore({ persisted: false });
    assert.equal(model.submitting, true);
    assert.equal(navigations, 0);
});

test("a newly rendered validation response permits another submission", () => {
    const previousPage = loginForm();
    previousPage.submit(submission());
    const nextPage = loginForm();
    const retry = submission();
    nextPage.submit(retry);
    assert.equal(retry.defaultPrevented, false);
    assert.equal(nextPage.submitting, true);
});
