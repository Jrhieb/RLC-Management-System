import test from "node:test";
import assert from "node:assert/strict";
import {
    RegistryLiveSearch,
    registrySearchUrl,
    registryRestoreFilters,
} from "../../resources/js/beneficiary-registry.js";

const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
};
const response = (html) => ({ ok: true, text: async () => html });
const settle = async () => {
    for (let i = 0; i < 10; i++) await Promise.resolve();
};

test("default browser APIs retain their Window receiver for timers and requests", async () => {
    const originals = {
        fetch: globalThis.fetch,
        setTimeout: globalThis.setTimeout,
        clearTimeout: globalThis.clearTimeout,
    };
    let callback,
        applied = false;
    try {
        globalThis.fetch = function () {
            assert.equal(this, globalThis);
            return Promise.resolve(response("rows"));
        };
        globalThis.setTimeout = function (next, delay) {
            assert.equal(this, globalThis);
            assert.equal(delay, 250);
            callback = next;
            return 1;
        };
        globalThis.clearTimeout = function () {
            assert.equal(this, globalThis);
        };
        const model = new RegistryLiveSearch({
            pending() {},
            apply() {
                applied = true;
            },
            failed() {
                assert.fail("Browser request failed");
            },
            settled() {},
        });
        model.change("search", { debounce: true });
        callback();
        await settle();
        assert.equal(applied, true);
        model.destroy();
    } finally {
        Object.assign(globalThis, originals);
    }
});

function fixture(request = async () => response("rows")) {
    let time = 0,
        timerId = 0;
    const timers = new Map(),
        calls = [],
        updates = [],
        states = [];
    const model = new RegistryLiveSearch({
        request: (url, options) => {
            calls.push({ url, options });
            return request(url, options);
        },
        pending: () => states.push("pending"),
        apply: (html, url) => {
            if (html === "invalid") throw new Error("Invalid fragment");
            updates.push({ html, url });
        },
        failed: () => states.push("failed"),
        settled: (applied) => states.push(applied ? "applied" : "idle"),
        schedule: (fn, delay) => {
            const id = ++timerId;
            timers.set(id, { fn, at: time + delay });
            return id;
        },
        unschedule: (id) => timers.delete(id),
    });
    return {
        model,
        calls,
        updates,
        states,
        type: (url) => model.change(url, { debounce: true }),
        tick(ms) {
            time += ms;
            for (const [id, timer] of [...timers])
                if (timer.at <= time) {
                    timers.delete(id);
                    timer.fn();
                }
        },
    };
}

test("typing waits 250ms after the most recent edit, then updates the actual Registry endpoint", async () => {
    const f = fixture();
    f.type("/beneficiaries?search=A");
    f.tick(200);
    assert.equal(f.calls.length, 0);
    f.type("/beneficiaries?search=Angel");
    f.tick(249);
    assert.equal(f.calls.length, 0);
    f.tick(1);
    await settle();
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].url, "/beneficiaries?search=Angel");
    assert.equal(f.calls[0].options.credentials, "same-origin");
    assert.deepEqual(f.calls[0].options.headers, {
        Accept: "text/html",
        "X-Registry-Fragment": "results",
    });
    assert.deepEqual(f.updates, [{ html: "rows", url: "/beneficiaries?search=Angel" }]);
});

test("URL building preserves leading zeros and caseworker/status, and resets pagination", () => {
    const values = {
        search: { value: " 000007 " },
        caseworker: { value: "9" },
        status: { value: "inactive" },
    };
    const form = {
        action: "https://rlc.test/beneficiaries?page=3",
        elements: { namedItem: (name) => values[name] },
    };
    assert.deepEqual(Object.fromEntries(new URL(registrySearchUrl(form)).searchParams), {
        search: "000007",
        caseworker: "9",
        status: "inactive",
    });
    values.search.value = "";
    values.caseworker.value = "unassigned";
    values.status.value = "";
    assert.deepEqual(Object.fromEntries(new URL(registrySearchUrl(form)).searchParams), {
        caseworker: "unassigned",
    });
});

test("My Caseload uses its own route and preserves zero-prefixed search/status with no caseworker selector", () => {
    const values = { search: { value: "" }, status: { value: "" } };
    const form = {
        action: "https://rlc.test/my-caseload",
        elements: { namedItem: (name) => values[name] },
    };
    registryRestoreFilters(
        form,
        new URL("https://rlc.test/my-caseload?search=000007&status=inactive&page=2&caseworker=9"),
    );
    assert.deepEqual(values, { search: { value: "000007" }, status: { value: "inactive" } });
    const url = new URL(registrySearchUrl(form));
    assert.equal(url.pathname, "/my-caseload");
    assert.deepEqual(Object.fromEntries(url.searchParams), {
        search: "000007",
        status: "inactive",
    });
    registryRestoreFilters(form, new URL("https://rlc.test/my-caseload"));
    assert.equal(values.search.value, "");
    assert.equal(values.status.value, "");
});

test("a new edit cancels a request before its replacement debounce and rejects late HTML", async () => {
    const old = deferred(),
        recent = deferred();
    let number = 0;
    const f = fixture(() => (++number === 1 ? old : recent).promise);
    f.type("old");
    f.tick(250);
    f.type("new");
    assert.equal(f.calls[0].options.signal.aborted, true);
    old.resolve(response("old rows"));
    await settle();
    assert.equal(f.updates.length, 0);
    f.tick(250);
    recent.resolve(response("new rows"));
    await settle();
    assert.equal(f.updates[0].html, "new rows");
});

test("late response bodies and failures never overwrite newer results or loading state", async () => {
    const body = deferred(),
        next = deferred();
    let number = 0;
    const f = fixture(async () =>
        ++number === 1 ? { ok: true, text: () => body.promise } : next.promise,
    );
    f.model.change("old");
    await settle();
    f.model.change("new");
    body.resolve("old rows");
    await settle();
    assert.equal(f.updates.length, 0);
    assert.equal(f.states.at(-1), "pending");
    next.reject(new Error("Offline"));
    await settle();
    assert.deepEqual(f.states.slice(-2), ["failed", "idle"]);
});

test("filters, pagination, clear, and retry run immediately and replace any queued typing", async () => {
    const f = fixture();
    f.type("queued");
    f.model.change("filtered");
    await settle();
    f.tick(250);
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].url, "filtered");
    for (const url of ["page-2", "clear", "retry"]) {
        f.model.change(url);
        await settle();
    }
    assert.deepEqual(
        f.updates.map((update) => update.url),
        ["filtered", "page-2", "clear", "retry"],
    );
});

for (const [name, request] of [
    ["HTTP errors", async () => ({ ok: false })],
    ["login redirects", async () => ({ ok: true, redirected: true })],
    [
        "network errors",
        async () => {
            throw new Error("Offline");
        },
    ],
    ["invalid fragments", async () => response("invalid")],
])
    test(name + " retain displayed results and support retry", async () => {
        let fail = true;
        const f = fixture((...args) =>
            fail ? request(...args) : Promise.resolve(response("recovered")),
        );
        f.model.change("search");
        await settle();
        assert.equal(f.updates.length, 0);
        assert.deepEqual(f.states.slice(-2), ["failed", "idle"]);
        fail = false;
        f.model.change(f.model.url);
        await settle();
        assert.equal(f.updates[0].html, "recovered");
    });

test("IME composition cancels previous work and waits for the completed text", async () => {
    const f = fixture();
    f.type("queued");
    f.model.change("composing", { composing: true });
    f.tick(500);
    assert.equal(f.calls.length, 0);
    f.type("complete");
    f.tick(250);
    await settle();
    assert.equal(f.calls[0].url, "complete");
});

test("destroy aborts requests and prevents queued or late updates after navigation", async () => {
    const late = deferred(),
        f = fixture(() => late.promise);
    f.model.change("old");
    f.model.destroy();
    assert.equal(f.calls[0].options.signal.aborted, true);
    late.resolve(response("late"));
    await settle();
    assert.equal(f.updates.length, 0);
    const queued = fixture();
    queued.type("queued");
    queued.model.destroy();
    queued.tick(250);
    assert.equal(queued.calls.length, 0);
});
