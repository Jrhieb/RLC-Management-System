import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { mkdir, mkdtemp, readFile, realpath, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

// Offline CDP checks with an existing Chromium browser; no browser dependencies.
// Every page request is intercepted. Only the isolated PHP fixture and allowlisted
// public build/image files can be read. The running Laravel app is never contacted.
const binary = process.env.HOMEPAGE_UI_BROWSER;
const fixtureDirectory = process.env.HOMEPAGE_UI_FIXTURES;
const workspace = fileURLToPath(new URL("../../", import.meta.url));
const origin = "http://rlcp-homepage.test";
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

test("public homepage in an isolated browser", { skip: !binary || !fixtureDirectory, timeout: 120000 }, async (t) => {
    const temporaryRoot = await realpath(os.tmpdir());
    const fixtures = await realpath(fixtureDirectory);
    assert.ok(fixtures.toLowerCase().startsWith(temporaryRoot.toLowerCase() + path.sep));
    assert.ok(path.basename(fixtures).startsWith("rlcp-public-ui-"));
    const html = await readFile(path.join(fixtures, "homepage.html"));
    const manifest = JSON.parse(await readFile(path.join(workspace, "public/build/manifest.json"), "utf8"));
    const publicRoot = await realpath(path.join(workspace, "public"));
    const screenshots = path.join(workspace, "docs/qa/public-homepage");
    const profile = await mkdtemp(path.join(temporaryRoot, "rlcp-homepage-browser-"));
    const pending = new Map();
    const errors = [];
    const requests = [];
    let browser, socket, nextId = 0, browserError = "";
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
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
        return result.result.value;
    }
    async function until(expression) {
        for (let attempt = 0; attempt < 100; attempt++) {
            if (await evaluate(expression)) return;
            await delay(50);
        }
        throw new Error(`Browser state not reached: ${expression}`);
    }
    async function viewport(width, height = 900) {
        await command("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: false });
        await delay(80);
    }
    async function key(keyName, options = {}) {
        const codes = { Tab: 9, Enter: 13, Escape: 27, " ": 32, Home: 36, End: 35, ArrowLeft: 37, ArrowRight: 39 };
        const text = keyName === "Enter" ? "\r" : keyName === " " ? " " : undefined;
        await command("Input.dispatchKeyEvent", { type: text ? "keyDown" : "rawKeyDown", key: keyName, code: keyName === " " ? "Space" : keyName, windowsVirtualKeyCode: codes[keyName], text, unmodifiedText: text, ...options });
        await command("Input.dispatchKeyEvent", { type: "keyUp", key: keyName, code: keyName === " " ? "Space" : keyName, windowsVirtualKeyCode: codes[keyName], ...options });
    }
    async function navigate(hash = "") {
        await command("Page.navigate", { url: origin + "/" + hash });
        await until("document.readyState === 'complete' && !!document.querySelector('[data-public-home]')");
    }
    async function loadAllImages() {
        await evaluate("document.querySelectorAll('img').forEach(image => image.loading = 'eager')");
        await until("[...document.images].every(image => image.complete && image.naturalWidth > 0)");
        await evaluate("document.fonts.ready.then(() => true)");
    }
    async function screenshot(name) {
        // Exercise lazy/reveal behavior throughout the page before the full capture.
        const height = await evaluate("document.documentElement.scrollHeight");
        for (let y = 0; y < height; y += 750) {
            await evaluate(`window.scrollTo({ top: ${y}, behavior: 'instant' })`);
            await delay(20);
        }
        await evaluate("window.scrollTo({ top: 0, behavior: 'instant' })");
        await delay(550);
        const size = (await command("Page.getLayoutMetrics")).cssContentSize;
        // Chromium removes the scrollbar while capturing; retain the full viewport
        // width so the right edge is not clipped by the former scrollbar gutter.
        const captureWidth = Math.max(size.width, await evaluate("innerWidth"));
        const result = await command("Page.captureScreenshot", { format: "png", captureBeyondViewport: true, clip: { x: 0, y: 0, width: captureWidth, height: size.height, scale: 1 } });
        await writeFile(path.join(screenshots, name + ".png"), Buffer.from(result.data, "base64"));
    }
    async function intercept(params) {
        const url = new URL(params.request.url);
        const pathname = decodeURIComponent(url.pathname);
        requests.push({ path: pathname, method: params.request.method });
        let body, type = "text/html; charset=utf-8";
        if (url.origin !== origin || params.request.method !== "GET") {
            throw new Error("A page attempted a request outside the isolated public fixture.");
        }
        if (pathname === "/") body = html;
        else if (pathname === "/favicon.ico") body = Buffer.alloc(0);
        else if (pathname === "/scope-probe") {
            body = Buffer.from('<!doctype html><html><head><title>Scope probe</title></head><body><main><h1>Staff scope probe</h1><button id="probe">Staff action</button><nav id="public-navigation">Unrelated navigation</nav></main></body></html>');
        } else if (pathname.startsWith("/build/") || pathname.startsWith("/images/homepage/") || pathname === "/images/branding/rlc-logo.png") {
            const target = path.resolve(publicRoot, "." + pathname);
            assert.ok(target.startsWith(publicRoot + path.sep));
            const realTarget = await realpath(target);
            assert.ok(realTarget.startsWith(publicRoot + path.sep));
            body = await readFile(realTarget);
            type = ({ ".css": "text/css", ".js": "text/javascript", ".svg": "image/svg+xml", ".png": "image/png", ".woff": "font/woff", ".woff2": "font/woff2" })[path.extname(target)] || "application/octet-stream";
        } else throw new Error(`Unexpected request: ${pathname}`);
        await command("Fetch.fulfillRequest", { requestId: params.requestId, responseCode: 200, responseHeaders: [{ name: "Content-Type", value: type }], body: body.toString("base64") });
    }
    try {
        await mkdir(screenshots, { recursive: true });
        browser = spawn(binary, ["--headless=new", "--disable-gpu", "--disable-extensions", "--disable-background-networking", "--disable-component-update", "--no-first-run", "--no-default-browser-check", "--remote-debugging-port=0", `--user-data-dir=${profile}`, "about:blank"], { windowsHide: true, stdio: ["ignore", "ignore", "pipe"] });
        browser.on("error", (error) => { browserError = error.message; });
        browser.stderr.on("data", () => {});
        let port;
        for (let attempt = 0; attempt < 150 && !port; attempt++) {
            if (browserError || browser.exitCode !== null) break;
            try { port = Number((await readFile(path.join(profile, "DevToolsActivePort"), "utf8")).split("\n")[0]); }
            catch { await delay(100); }
        }
        assert.ok(port, `Installed Chromium browser must start: ${browserError}`);
        const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        socket = new WebSocket(pages.find((page) => page.type === "page").webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.addEventListener("open", resolve, { once: true }); socket.addEventListener("error", reject, { once: true }); });
        socket.addEventListener("message", (event) => {
            const message = JSON.parse(event.data);
            if (message.method === "Fetch.requestPaused") {
                intercept(message.params).catch(async (error) => {
                    errors.push(error.message);
                    await command("Fetch.failRequest", { requestId: message.params.requestId, errorReason: "BlockedByClient" }).catch(() => {});
                });
            }
            if (message.method === "Runtime.exceptionThrown") errors.push(message.params.exceptionDetails.text);
            if (message.method === "Runtime.consoleAPICalled" && message.params.type === "error") errors.push("Console error");
            if (message.method === "Log.entryAdded" && message.params.entry.level === "error") errors.push(message.params.entry.text);
            const item = pending.get(message.id);
            if (!item) return;
            clearTimeout(item.timer); pending.delete(message.id);
            if (message.error) item.reject(new Error(message.error.message)); else item.resolve(message.result);
        });
        await command("Page.enable");
        await command("Runtime.enable");
        await command("Log.enable");
        await command("Fetch.enable", { patterns: [{ urlPattern: "*" }] });
        await viewport(1440);
        await navigate();
        await until("document.body.dataset.publicInitialized === 'true'");
        await loadAllImages();

        await t.test("desktop, tablet and mobile layouts load images without overflow", async () => {
            for (const width of [1440, 1024, 768, 390, 320]) {
                await viewport(width);
                const layout = await evaluate(`({ width: innerWidth, clientWidth: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth, contentWidth: document.querySelector('.public-container').getBoundingClientRect().width, heroVisible: getComputedStyle(document.querySelector('h1')).opacity, columns: getComputedStyle(document.querySelector('.public-card-grid')).gridTemplateColumns.split(' ').length })`);
                assert.ok(layout.scrollWidth <= layout.clientWidth, `No horizontal overflow at ${width}px: ${JSON.stringify(layout)}`);
                assert.ok(layout.contentWidth <= 1200);
                assert.equal(layout.heroVisible, "1");
                assert.equal(layout.columns, width <= 700 ? 1 : 3);
            }
            await viewport(1440);
            await screenshot("desktop");
            await viewport(768, 1024);
            await screenshot("tablet");
            await viewport(390, 844);
            await screenshot("mobile");
        });

        await t.test("keyboard mobile menu, Escape, focus, outside dismissal and resize", async () => {
            assert.equal(await evaluate("document.querySelector('[data-menu-toggle]').getAttribute('aria-expanded')"), "false");
            assert.equal(await evaluate("document.getElementById('public-navigation').hidden"), true);
            await evaluate("document.querySelector('[data-menu-toggle]').focus()");
            await key("Enter");
            assert.equal(await evaluate("document.querySelector('[data-menu-toggle]').getAttribute('aria-expanded')"), "true");
            await key("Tab");
            assert.equal(await evaluate("document.activeElement.getAttribute('href')"), "#home");
            assert.equal(await evaluate("getComputedStyle(document.activeElement).outlineStyle"), "solid");
            await key("Escape");
            assert.equal(await evaluate("document.activeElement.matches('[data-menu-toggle]')"), true);
            assert.equal(await evaluate("document.getElementById('public-navigation').hidden"), true);
            await key("Enter");
            await key("Tab");
            await key("Tab");
            await key("Enter");
            await until("location.hash === '#about'");
            assert.equal(await evaluate("document.activeElement.id"), "about");
            assert.equal(await evaluate("document.getElementById('public-navigation').hidden"), true);
            await evaluate("document.querySelector('[data-menu-toggle]').click(); document.querySelector('h1').click()");
            assert.equal(await evaluate("document.getElementById('public-navigation').hidden"), true);
            await viewport(1440);
            assert.equal(await evaluate("document.getElementById('public-navigation').hidden"), false);
            await evaluate("document.querySelector('#public-navigation a').focus()");
            await viewport(390, 844);
            assert.equal(await evaluate("document.activeElement.matches('[data-menu-toggle]')"), true);
            assert.equal(await evaluate("document.querySelector('[data-menu-toggle]').getAttribute('aria-expanded')"), "false");
        });

        await t.test("tabs support arrows, Home, End, Space, click and direct fragments", async () => {
            await evaluate("document.getElementById('tab-story').focus()");
            await key("ArrowRight");
            assert.equal(await evaluate("document.activeElement.id"), "tab-mission");
            assert.equal(await evaluate("document.getElementById('our-mission').hidden"), false);
            assert.equal(await evaluate("document.getElementById('tab-mission').getAttribute('aria-selected')"), "true");
            await key("End");
            assert.equal(await evaluate("document.activeElement.id"), "tab-vision");
            await key("Home");
            assert.equal(await evaluate("document.activeElement.id"), "tab-story");
            await key("ArrowLeft");
            await key(" ");
            assert.equal(await evaluate("document.getElementById('our-vision').hidden"), false);
            assert.equal(await evaluate("document.querySelectorAll('[role=tab][tabindex=\"0\"]').length"), 1);
            await key("Tab");
            assert.equal(await evaluate("document.activeElement.id"), "our-vision");
            await evaluate("document.getElementById('tab-story').click()");
            assert.equal(await evaluate("document.getElementById('our-story').hidden"), false);
            await evaluate("location.hash = '#our-mission'");
            await until("document.getElementById('tab-mission').getAttribute('aria-selected') === 'true'");
            await navigate("#our-vision");
            await until("document.getElementById('our-vision').hidden === false");
        });

        await t.test("CTAs resolve and program details work with the keyboard", async () => {
            const invalid = await evaluate(`Array.from(document.querySelectorAll('a')).filter(link => link.hash ? !document.getElementById(link.hash.slice(1)) : !['/login', '/register'].includes(new URL(link.href).pathname)).map(link => link.getAttribute('href'))`);
            assert.deepEqual(invalid, []);
            await evaluate("document.querySelector('summary').focus()");
            await key("Enter");
            assert.equal(await evaluate("document.querySelector('details').open"), true);
            await key("Enter");
            assert.equal(await evaluate("document.querySelector('details').open"), false);
            const links = await evaluate("[...document.querySelectorAll('a.public-button[href^=\"#\"]')].map(link => link.getAttribute('href'))");
            for (const href of links) {
                await evaluate(`document.querySelector('a.public-button[href="${href}"]').click()`);
                await until(`location.hash === '${href}'`);
            }
        });

        await t.test("reduced motion disables animation and smooth scrolling", async () => {
            await command("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "reduce" }] });
            const styles = await evaluate(`({ scroll: getComputedStyle(document.documentElement).scrollBehavior, animation: getComputedStyle(document.querySelector('[data-public-reveal]')).animationName, transition: getComputedStyle(document.querySelector('.public-button')).transitionDuration, opacity: getComputedStyle(document.querySelector('[data-public-reveal]')).opacity })`);
            assert.deepEqual(styles, { scroll: "auto", animation: "none", transition: "0s", opacity: "1" });
        });

        await t.test("all navigation and about copy remain usable with JavaScript disabled", async () => {
            await command("Emulation.setScriptExecutionDisabled", { value: true });
            await command("Page.reload", { ignoreCache: true });
            await until("document.readyState === 'complete' && !document.body.dataset.publicInitialized");
            assert.equal(await evaluate("document.querySelector('[data-menu-toggle]').hidden"), true);
            assert.equal(await evaluate("document.getElementById('public-navigation').hidden"), false);
            assert.equal(await evaluate("[...document.querySelectorAll('[data-about-panel]')].every(panel => !panel.hidden && panel.getBoundingClientRect().height > 0)"), true);
            assert.equal(await evaluate("[...document.querySelectorAll('[data-public-reveal]')].every(el => getComputedStyle(el).opacity === '1')"), true);
            assert.ok(await evaluate("document.documentElement.scrollWidth <= innerWidth"));
            await command("Emulation.setScriptExecutionDisabled", { value: false });
        });

        await t.test("homepage CSS and JS do not affect a page without the homepage scope", async () => {
            await command("Page.navigate", { url: origin + "/scope-probe" });
            await until("document.readyState === 'complete' && !!document.getElementById('probe')");
            const snapshot = "JSON.stringify([document.body, document.getElementById('probe'), document.getElementById('public-navigation')].map(el => ({color: getComputedStyle(el).color, display: getComputedStyle(el).display, margin: getComputedStyle(el).margin, font: getComputedStyle(el).font, width: el.getBoundingClientRect().width, hidden: el.hidden})))";
            const before = await evaluate(snapshot);
            await evaluate(`Promise.all([new Promise(resolve => { const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = '/build/${manifest['resources/css/public-home.css'].file}'; link.onload = resolve; document.head.append(link); }), new Promise(resolve => { const script = document.createElement('script'); script.type = 'module'; script.src = '/build/${manifest['resources/js/public-home.js'].file}'; script.onload = resolve; document.head.append(script); })]).then(() => true)`);
            assert.equal(await evaluate(snapshot), before);
            assert.equal(await evaluate("document.body.hasAttribute('data-public-initialized')"), false);
        });
        assert.deepEqual(errors, [], "No page errors or unexpected network requests");
        assert.ok(requests.every((request) => request.method === "GET"));
        await writeFile(path.join(screenshots, "browser-results.json"), JSON.stringify({ viewportWidths: [1440, 1024, 768, 390, 320], consoleErrors: errors, requestCount: requests.length, source: "Isolated PHP public render; all browser requests intercepted; no live application access", screenshots: ["desktop.png", "tablet.png", "mobile.png"] }, null, 2) + "\n");
    } finally {
        if (socket?.readyState === WebSocket.OPEN) {
            await command("Browser.close").catch(() => {});
            socket.close();
        }
        for (const item of pending.values()) clearTimeout(item.timer);
        pending.clear();
        if (browser && browser.exitCode === null) { await delay(250); if (browser.exitCode === null) browser.kill(); }
        const target = await realpath(profile);
        assert.equal(path.dirname(target), temporaryRoot);
        assert.ok(path.basename(target).startsWith("rlcp-homepage-browser-"));
        await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
});
