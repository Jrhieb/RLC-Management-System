import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { mkdir, mkdtemp, readFile, realpath, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

// Same installed-Chromium/CDP approach as the existing offline homepage harness.
// Every page request is intercepted; the live Laravel server is never contacted.
const workspace = fileURLToPath(new URL("../../../", import.meta.url));
const origin = "http://rlcp-staff.test";
export const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function staffBrowser(binary, fixtureDirectory) {
    const temporaryRoot = await realpath(os.tmpdir());
    const fixtures = await realpath(fixtureDirectory);
    assert.ok(fixtures.toLowerCase().startsWith(temporaryRoot.toLowerCase() + path.sep));
    assert.ok(path.basename(fixtures).startsWith("rlcp-staff-ui-"));
    const publicRoot = await realpath(path.join(workspace, "public"));
    const profile = await mkdtemp(path.join(temporaryRoot, "rlcp-staff-browser-"));
    const screenshots = path.join(workspace, "docs/qa/staff-branding");
    await mkdir(screenshots, { recursive: true });
    let browser,
        socket,
        nextId = 0,
        startupError = "";
    let stage = "current",
        role = "project_director";
    const pending = new Map(),
        errors = [],
        requests = [];
    function command(method, params = {}) {
        return new Promise((resolve, reject) => {
            const id = ++nextId;
            const timer = setTimeout(() => {
                pending.delete(id);
                reject(new Error(`CDP timeout: ${method}`));
            }, 10000);
            pending.set(id, { resolve, reject, timer });
            socket.send(JSON.stringify({ id, method, params }));
        });
    }
    async function evaluate(expression) {
        const result = await command("Runtime.evaluate", {
            expression,
            returnByValue: true,
            awaitPromise: true,
        });
        if (result.exceptionDetails)
            throw new Error(
                result.exceptionDetails.exception?.description || result.exceptionDetails.text,
            );
        return result.result.value;
    }
    async function until(expression) {
        for (let i = 0; i < 100; i++) {
            if (await evaluate(expression)) return;
            await delay(50);
        }
        throw new Error(`Expected browser state: ${expression}`);
    }
    async function intercept(params) {
        const url = new URL(params.request.url);
        assert.equal(url.origin, origin, "External requests are blocked");
        assert.equal(params.request.method, "GET", "No write requests are permitted");
        const pathname = decodeURIComponent(url.pathname);
        requests.push(pathname);
        let body,
            type = "text/html; charset=utf-8";
        const routes = {
            "/dashboard": role,
            "/settings/appearance": "appearance",
            "/scope-probe": "module-probe",
            "/beneficiaries": "module-probe",
            "/beneficiaries/9100": "module-probe",
        };
        if (routes[pathname])
            body = await readFile(path.join(fixtures, stage + "-" + routes[pathname] + ".html"));
        else if (pathname === "/account/notifications/unread-count") {
            body = { unread_count: 2 };
        } else if (pathname === "/account/notifications") {
            body = {
                unread_count: 2,
                items: [
                    {
                        id: "qa-notification",
                        title: "Synthetic notification",
                        message: "Fixture content only",
                        time: "Test timestamp",
                        url: "/dashboard",
                        read_at: null,
                    },
                ],
            };
        } else if (pathname === "/dashboard/notifications") {
            body = { items: [] };
        } else if (pathname === "/dashboard/caseload") {
            body = {
                counts: { total: 2, active: 1, inactive: 1 },
                preview: await readFile(
                    path.join(fixtures, stage + "-caseload-preview.html"),
                    "utf8",
                ),
            };
        } else if (pathname === "/dashboard/beneficiary-suggestions") {
            body = {
                results: [
                    {
                        id: 9100,
                        name: "Synthetic Person 1",
                        beneficiary_id: "QA-001",
                        photo_url: null,
                        url: origin + "/beneficiaries/9100",
                    },
                ],
            };
        } else if (pathname.startsWith("/build/")) {
            const buildRoot =
                stage === "baseline"
                    ? path.join(fixtures, "baseline-build")
                    : path.join(publicRoot, "build");
            const target = await realpath(path.resolve(buildRoot, "." + pathname.slice(6)));
            assert.ok(target.startsWith(buildRoot + path.sep));
            body = await readFile(target);
            type =
                {
                    ".css": "text/css",
                    ".js": "text/javascript",
                    ".woff": "font/woff",
                    ".woff2": "font/woff2",
                }[path.extname(target)] || "application/octet-stream";
        } else if (pathname === "/images/branding/rlc-logo.png") {
            body = await readFile(path.join(publicRoot, "images/branding/rlc-logo.png"));
            type = "image/png";
        } else if (pathname === "/flux/flux.min.js") {
            // AssetManager serves the installed Flux Lite bundle at this URL.
            body = await readFile(path.join(workspace, "vendor/livewire/flux/dist/flux-lite.min.js"));
            type = "text/javascript";
        } else if (/^\/livewire-[a-f0-9]+\/livewire\.min\.js$/.test(pathname)) {
            body = await readFile(
                path.join(workspace, "vendor/livewire/livewire/dist/livewire.min.js"),
            );
            type = "text/javascript";
        } else if (pathname === "/favicon.ico") {
            body = Buffer.alloc(0);
        } else if (pathname === "/favicon.svg") {
            body = await readFile(path.join(publicRoot, "favicon.svg"));
            type = "image/svg+xml";
        } else throw new Error(`Unexpected fixture request: ${pathname}`);
        if (!Buffer.isBuffer(body)) {
            type = "application/json";
            body = Buffer.from(JSON.stringify(body));
        }
        await command("Fetch.fulfillRequest", {
            requestId: params.requestId,
            responseCode: 200,
            responseHeaders: [
                { name: "Content-Type", value: type },
                { name: "Cache-Control", value: "no-store" },
            ],
            body: body.toString("base64"),
        });
    }
    async function close() {
        if (socket?.readyState === WebSocket.OPEN) {
            await command("Browser.close").catch(() => {});
            socket.close();
        }
        for (const item of pending.values()) clearTimeout(item.timer);
        pending.clear();
        if (browser && browser.exitCode === null) {
            await delay(250);
            if (browser.exitCode === null) browser.kill();
        }
        const target = await realpath(profile);
        assert.equal(path.dirname(target), temporaryRoot);
        assert.ok(path.basename(target).startsWith("rlcp-staff-browser-"));
        await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
    try {
        browser = spawn(
            binary,
            [
                "--headless=new",
                "--disable-gpu",
                "--disable-extensions",
                "--disable-background-networking",
                "--disable-component-update",
                "--no-first-run",
                "--no-default-browser-check",
                "--remote-debugging-port=0",
                `--user-data-dir=${profile}`,
                "about:blank",
            ],
            { windowsHide: true, stdio: ["ignore", "ignore", "pipe"] },
        );
        browser.on("error", (error) => {
            startupError = error.message;
        });
        browser.stderr.on("data", () => {});
        let port;
        for (let i = 0; i < 150 && !port; i++) {
            if (startupError || browser.exitCode !== null) break;
            try {
                port = Number(
                    (await readFile(path.join(profile, "DevToolsActivePort"), "utf8")).split(
                        "\n",
                    )[0],
                );
            } catch {
                await delay(100);
            }
        }
        assert.ok(port, `Chromium startup: ${startupError}`);
        const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        socket = new WebSocket(pages.find((page) => page.type === "page").webSocketDebuggerUrl);
        await new Promise((resolve, reject) => {
            socket.addEventListener("open", resolve, { once: true });
            socket.addEventListener("error", reject, { once: true });
        });
        socket.addEventListener("message", (event) => {
            const message = JSON.parse(event.data);
            if (message.method === "Fetch.requestPaused")
                intercept(message.params).catch(async (error) => {
                    errors.push(error.message);
                    await command("Fetch.failRequest", {
                        requestId: message.params.requestId,
                        errorReason: "BlockedByClient",
                    }).catch(() => {});
                });
            if (message.method === "Runtime.exceptionThrown")
                errors.push(
                    message.params.exceptionDetails.exception?.description ||
                        message.params.exceptionDetails.text,
                );
            if (message.method === "Runtime.consoleAPICalled" && message.params.type === "error")
                errors.push("Console error");
            const item = pending.get(message.id);
            if (!item) return;
            clearTimeout(item.timer);
            pending.delete(message.id);
            if (message.error) item.reject(new Error(message.error.message));
            else item.resolve(message.result);
        });
        await command("Page.enable");
        await command("Runtime.enable");
        await command("Network.enable");
        await command("Network.setCacheDisabled", { cacheDisabled: true });
        await command("Fetch.enable", { patterns: [{ urlPattern: "*" }] });
    } catch (error) {
        await close();
        throw error;
    }
    return {
        command,
        evaluate,
        until,
        close,
        errors,
        requests,
        async navigate(nextRole = "project_director", nextStage = "current", route = "/dashboard") {
            role = nextRole;
            stage = nextStage;
            await command("Page.navigate", { url: origin + route });
            await until(
                "document.readyState === 'complete' && !!window.Alpine && !!document.querySelector('.rlc-notification-button')",
            );
            await evaluate("document.fonts.ready.then(() => true)");
            await until("document.querySelector('.rlc-notification-count').textContent === '2'");
            await delay(800);
        },
        async viewport(width, height = 1000) {
            await command("Emulation.setDeviceMetricsOverride", {
                width,
                height,
                deviceScaleFactor: 1,
                mobile: false,
            });
            await delay(80);
        },
        async theme(value) {
            await evaluate(`window.Flux.appearance = ${JSON.stringify(value)}`);
            await delay(150);
        },
        async key(key, modifiers = 0) {
            const codes = { Tab: 9, Enter: 13, Escape: 27, " ": 32, ArrowDown: 40, ArrowUp: 38 };
            const text = key === "Enter" ? "\r" : key === " " ? " " : undefined;
            await command("Input.dispatchKeyEvent", {
                type: text ? "keyDown" : "rawKeyDown",
                key,
                code: key === " " ? "Space" : key,
                windowsVirtualKeyCode: codes[key],
                text,
                unmodifiedText: text,
                modifiers,
            });
            await command("Input.dispatchKeyEvent", {
                type: "keyUp",
                key,
                windowsVirtualKeyCode: codes[key],
                modifiers,
            });
        },
        async screenshot(name) {
            const size = (await command("Page.getLayoutMetrics")).cssContentSize;
            const result = await command("Page.captureScreenshot", {
                format: "png",
                captureBeyondViewport: true,
                clip: {
                    x: 0,
                    y: 0,
                    width: await evaluate("innerWidth"),
                    height: size.height,
                    scale: 1,
                },
            });
            await writeFile(
                path.join(screenshots, name + ".png"),
                Buffer.from(result.data, "base64"),
            );
        },
        async report(data) {
            await writeFile(
                path.join(screenshots, "browser-results.json"),
                JSON.stringify(
                    {
                        ...data,
                        errors,
                        requests: [...new Set(requests)],
                        source: "Synthetic isolated fixtures; all requests intercepted; no real data, writes or emails",
                    },
                    null,
                    2,
                ) + "\n",
            );
        },
    };
}
