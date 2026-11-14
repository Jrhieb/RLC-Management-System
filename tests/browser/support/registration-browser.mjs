import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { mkdir, mkdtemp, readFile, realpath, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const workspace = fileURLToPath(new URL("../../../", import.meta.url));
export const origin = "http://rlcp-homepage.test";
export const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// All navigation/assets and submissions are intercepted before network access.
// No request body, email, password, cookie, or CSRF value is written or logged.
export async function registrationBrowser(binary, fixtureDirectory) {
    const temporaryRoot = await realpath(os.tmpdir());
    const fixtures = await realpath(fixtureDirectory);
    assert.ok(fixtures.toLowerCase().startsWith(temporaryRoot.toLowerCase() + path.sep));
    assert.ok(path.basename(fixtures).startsWith("rlcp-registration-ui-"));
    const publicRoot = await realpath(path.join(workspace, "public"));
    const profile = await mkdtemp(path.join(temporaryRoot, "rlcp-registration-browser-"));
    const screenshots = path.join(workspace, "docs/qa/registration-dialog");
    await mkdir(screenshots, { recursive: true });
    let browser, socket, nextId = 0, startupError = "", completionFailure = false;
    const pending = new Map(), posts = [], errors = [];
    const stats = { emailPosts: 0, completionPosts: 0, csrfPresent: true };
    function command(method, params = {}) {
        return new Promise((resolve, reject) => {
            const id = ++nextId;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 10000);
            pending.set(id, { resolve, reject, timer });
            socket.send(JSON.stringify({ id, method, params }));
        });
    }
    async function evaluate(expression) {
        const result = await command("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error("Browser evaluation failed");
        return result.result.value;
    }
    async function until(expression) {
        for (let i = 0; i < 100; i++) { if (await evaluate(expression)) return; await delay(50); }
        throw new Error(`Expected browser state: ${expression}`);
    }
    async function intercept(params) {
        const url = new URL(params.request.url);
        const pathname = decodeURIComponent(url.pathname);
        const bootstrapAsset = url.origin === "http://localhost" && pathname.startsWith("/build/") && params.request.method === "GET";
        if (url.origin !== origin && !bootstrapAsset) throw new Error("Unexpected external browser request");
        if (params.request.method === "POST") {
            if (pathname === "/register") {
                stats.emailPosts++;
                stats.csrfPresent &&= params.request.postData?.includes('name="_token"') === true;
                posts.push(params.requestId);
                return;
            }
            if (pathname === "/register/complete") {
                stats.completionPosts++;
                completionFailure = true;
                await command("Fetch.fulfillRequest", { requestId: params.requestId, responseCode: 302, responseHeaders: [{ name: "Location", value: "/register/complete" }] });
                return;
            }
            throw new Error("Unexpected submission endpoint");
        }
        assert.equal(params.request.method, "GET");
        let body, type = "text/html; charset=utf-8";
        const routes = { "/": "homepage", "/register": "register", "/register/complete": completionFailure ? "complete-invalid" : "complete", "/register/awaiting-approval": "awaiting" };
        if (routes[pathname]) body = await readFile(path.join(fixtures, routes[pathname] + ".html"));
        else if (/^\/livewire-[a-f0-9]+\/livewire\.min\.js$/.test(pathname)) {
            body = await readFile(path.join(workspace, "vendor/livewire/livewire/dist/livewire.min.js"));
            type = "text/javascript";
        }
        else if (pathname === "/favicon.ico") body = Buffer.alloc(0);
        else if (pathname.startsWith("/build/") || pathname.startsWith("/images/homepage/") || pathname === "/images/branding/rlc-logo.png") {
            const target = await realpath(path.resolve(publicRoot, "." + pathname));
            assert.ok(target.startsWith(publicRoot + path.sep));
            body = await readFile(target);
            type = ({ ".css": "text/css", ".js": "text/javascript", ".svg": "image/svg+xml", ".png": "image/png", ".woff": "font/woff", ".woff2": "font/woff2" })[path.extname(target)] || "application/octet-stream";
        } else throw new Error(`Unexpected fixture route: ${pathname}`);
        await command("Fetch.fulfillRequest", { requestId: params.requestId, responseCode: 200, responseHeaders: [{ name: "Content-Type", value: type }, { name: "Access-Control-Allow-Origin", value: origin }], body: body.toString("base64") });
    }
    async function close() {
        if (socket?.readyState === WebSocket.OPEN) { await command("Browser.close").catch(() => {}); socket.close(); }
        for (const item of pending.values()) clearTimeout(item.timer);
        pending.clear();
        if (browser && browser.exitCode === null) { await delay(250); if (browser.exitCode === null) browser.kill(); }
        const target = await realpath(profile);
        assert.equal(path.dirname(target), temporaryRoot);
        assert.ok(path.basename(target).startsWith("rlcp-registration-browser-"));
        await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
    try {
        browser = spawn(binary, ["--headless=new", "--disable-gpu", "--disable-extensions", "--disable-background-networking", "--disable-component-update", "--no-first-run", "--no-default-browser-check", "--remote-debugging-port=0", `--user-data-dir=${profile}`, "about:blank"], { windowsHide: true, stdio: ["ignore", "ignore", "pipe"] });
        browser.on("error", (error) => { startupError = error.message; });
        browser.stderr.on("data", () => {});
        let port;
        for (let i = 0; i < 150 && !port; i++) {
            if (startupError || browser.exitCode !== null) break;
            try { port = Number((await readFile(path.join(profile, "DevToolsActivePort"), "utf8")).split("\n")[0]); }
            catch { await delay(100); }
        }
        assert.ok(port, `Chromium startup: ${startupError}`);
        const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        socket = new WebSocket(pages.find((page) => page.type === "page").webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.addEventListener("open", resolve, { once: true }); socket.addEventListener("error", reject, { once: true }); });
        socket.addEventListener("message", (event) => {
            const message = JSON.parse(event.data);
            if (message.method === "Fetch.requestPaused") intercept(message.params).catch(async (error) => {
                errors.push(error.message);
                await command("Fetch.failRequest", { requestId: message.params.requestId, errorReason: "BlockedByClient" }).catch(() => {});
            });
            if (message.method === "Runtime.exceptionThrown") errors.push("Uncaught browser exception");
            if (message.method === "Runtime.consoleAPICalled" && message.params.type === "error") errors.push("Console error");
            const item = pending.get(message.id);
            if (!item) return;
            clearTimeout(item.timer); pending.delete(message.id);
            if (message.error) item.reject(new Error(message.error.message)); else item.resolve(message.result);
        });
        await command("Page.enable"); await command("Runtime.enable");
        await command("Fetch.enable", { patterns: [{ urlPattern: "*" }] });
    } catch (error) { await close(); throw error; }
    return {
        command, evaluate, until, close, errors, stats,
        async navigate(route = "/") {
            await command("Page.navigate", { url: origin + route });
            await until("document.readyState === 'complete' && !!document.querySelector('.rlcp-registration')");
            await evaluate("document.fonts.ready.then(() => true)");
        },
        async viewport(width, height = 950) {
            await command("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: false });
            await delay(80);
        },
        async key(key, modifiers = 0) {
            const codes = { Tab: 9, Enter: 13, Escape: 27, " ": 32 };
            const text = key === "Enter" ? "\r" : key === " " ? " " : undefined;
            await command("Input.dispatchKeyEvent", { type: text ? "keyDown" : "rawKeyDown", key, code: key === " " ? "Space" : key, windowsVirtualKeyCode: codes[key], text, unmodifiedText: text, modifiers });
            await command("Input.dispatchKeyEvent", { type: "keyUp", key, windowsVirtualKeyCode: codes[key], modifiers });
        },
        async reply(status, data = {}) {
            for (let i = 0; i < 100 && !posts.length; i++) await delay(25);
            assert.ok(posts.length, "A mocked request must be pending");
            const requestId = posts.shift();
            if (status === "network") await command("Fetch.failRequest", { requestId, errorReason: "InternetDisconnected" });
            else await command("Fetch.fulfillRequest", { requestId, responseCode: status, responsePhrase: "Test response", responseHeaders: [{ name: "Content-Type", value: "application/json" }], body: Buffer.from(JSON.stringify(data)).toString("base64") });
            await until("document.querySelector('[data-registration-form]').getAttribute('aria-busy') === 'false'");
        },
        async screenshot(name) {
            await delay(250);
            const result = await command("Page.captureScreenshot", { format: "png" });
            await writeFile(path.join(screenshots, name + ".png"), Buffer.from(result.data, "base64"));
        },
        async report() {
            await writeFile(path.join(screenshots, "browser-results.json"), JSON.stringify({ ...stats, errors, source: "Guarded memory fixtures and intercepted requests only; no live server, SMTP, or real submissions" }, null, 2) + "\n");
        },
    };
}
