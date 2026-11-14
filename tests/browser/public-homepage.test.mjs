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
        const state = await evaluate("({ path: location.pathname, ready: document.readyState, initialized: document.body.dataset.publicInitialized, active: document.activeElement?.id || document.activeElement?.tagName, dialogs: [...document.querySelectorAll('dialog[open]')].map(dialog => dialog.id), inert: document.querySelectorAll('[inert]').length })");
        throw new Error(`Browser state not reached: ${expression}; ${JSON.stringify(state)}; errors: ${errors.join(', ')}`);
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
    async function navigate(hash = "", pathname = "/") {
        await command("Page.navigate", { url: origin + pathname + hash });
        await until("document.readyState === 'complete' && !!document.querySelector('[data-public-home]')");
    }
    async function loadAllImages() {
        await evaluate("document.querySelectorAll('img').forEach(image => image.loading = 'eager')");
        await until("[...document.images].every(image => image.complete && image.naturalWidth > 0)");
        await evaluate("Promise.all([...document.images].map(image => image.decode())).then(() => true)");
        await evaluate("document.fonts.ready.then(() => true)");
    }
    async function screenshot(name) {
        await loadAllImages();
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
        const bootstrapAsset = url.origin === "http://localhost" && pathname.startsWith("/build/");
        if ((url.origin !== origin && !bootstrapAsset) || params.request.method !== "GET") {
            throw new Error("A page attempted a request outside the isolated public fixture.");
        }
        if (pathname === "/") body = html;
        else if (/^\/livewire-[a-f0-9]+\/livewire\.min\.js$/.test(pathname)) {
            body = await readFile(path.join(workspace, "vendor/livewire/livewire/dist/livewire.min.js"));
            type = "text/javascript";
        }
        else if (pathname === "/about") body = await readFile(path.join(fixtures, "about.html"));
        else if (pathname === "/programs") body = await readFile(path.join(fixtures, "programs.html"));
        else if (pathname === "/community/activities") {
            const filter = url.searchParams.get("filter") || "upcoming";
            assert.ok(["upcoming", "past", "all"].includes(filter));
            body = await readFile(path.join(fixtures, `activities-${filter}.html`));
        }
        else if (pathname === "/application") body = await readFile(path.join(fixtures, "application.html"));
        else if (pathname === "/favicon.ico") body = Buffer.alloc(0);
        else if (pathname === "/scope-probe") {
            body = Buffer.from('<!doctype html><html><head><title>Scope probe</title></head><body><main><h1>Staff scope probe</h1><button id="probe">Staff action</button><nav id="public-navigation">Unrelated navigation</nav></main></body></html>');
        } else if (pathname.startsWith("/build/") || pathname.startsWith("/images/homepage/") || pathname === "/images/branding/rlc-logo.png") {
            const target = path.resolve(publicRoot, "." + pathname);
            assert.ok(target.startsWith(publicRoot + path.sep));
            const realTarget = await realpath(target);
            assert.ok(realTarget.startsWith(publicRoot + path.sep));
            body = await readFile(realTarget);
            type = ({ ".css": "text/css", ".js": "text/javascript", ".svg": "image/svg+xml", ".png": "image/png", ".jpg": "image/jpeg", ".woff": "font/woff", ".woff2": "font/woff2" })[path.extname(target)] || "application/octet-stream";
        } else throw new Error(`Unexpected request: ${pathname}`);
        await command("Fetch.fulfillRequest", { requestId: params.requestId, responseCode: 200, responseHeaders: [{ name: "Content-Type", value: type }, { name: "Access-Control-Allow-Origin", value: origin }], body: body.toString("base64") });
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
            await key("Tab");
            await key("Tab");
            await key("Enter");
            await until("location.pathname === '/community/activities' && document.body.dataset.publicInitialized === 'true'");
            await navigate("#contact");
            await until("document.body.dataset.publicInitialized === 'true'");
            await evaluate("document.querySelector('[data-menu-toggle]').click(); document.querySelector('[data-public-navigation] a[href=\"#contact\"]').click()");
            assert.equal(await evaluate("document.activeElement.id"), "contact");
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
            const invalid = await evaluate(`Array.from(document.querySelectorAll('a')).filter(link => link.hash === '#staff-login' && link.matches('[data-staff-login-open]') ? !document.getElementById('staff-login-dialog') : link.hash ? !document.getElementById(link.hash.slice(1)) : !['/login', '/register', '/forgot-password', '/application', '/about', '/programs', '/community/activities'].includes(new URL(link.href).pathname)).map(link => link.getAttribute('href'))`);
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

        await t.test("public theme works with keyboard and leaves staff appearance unchanged", async () => {
            await viewport(1440); await navigate();
            await evaluate("localStorage.setItem('flux.appearance', 'light'); document.querySelector('[data-public-theme-toggle]').focus()");
            const previous = await evaluate("document.body.dataset.publicTheme");
            await key(" ");
            assert.notEqual(await evaluate("document.body.dataset.publicTheme"), previous);
            assert.equal(await evaluate("document.querySelector('[data-public-theme-toggle]').getAttribute('aria-pressed')"), previous === "light" ? "true" : "false");
            assert.equal(await evaluate("localStorage.getItem('flux.appearance')"), "light");
            const next = await evaluate("document.body.dataset.publicTheme");
            await navigate();
            assert.equal(await evaluate("document.body.dataset.publicTheme"), next);
            await screenshot("desktop-dark");
            await evaluate("document.querySelector('[data-public-theme-toggle]').click()");
        });

        await t.test("Log In retains the existing dialog, named-route fallback and focus behavior", async () => {
            await evaluate("document.querySelector('.public-nav-login').focus()");
            assert.equal(await evaluate("new URL(document.querySelector('.public-nav-login').href).pathname"), "/login");
            await key("Enter");
            await until("document.querySelector('#staff-login-dialog').open");
            assert.equal(await evaluate("document.activeElement.id"), "staff-login-email");
            await evaluate("document.querySelector('#staff-login-dialog').addEventListener('close', event => event.target.dataset.testClosed = 'true', { once: true })");
            await key("Escape");
            await until("!document.querySelector('#staff-login-dialog').open && document.querySelector('#staff-login-dialog').dataset.testClosed === 'true'");
            assert.equal(await evaluate("document.activeElement.matches('.public-nav-login')"), true);
        });

        await t.test("every public page opens its login popup from header and footer on desktop and mobile", async () => {
            for (const width of [1440, 390]) {
                await viewport(width);
                for (const pathname of ["/", "/about", "/programs", "/community/activities", "/application"]) {
                    await navigate("", pathname);
                    await until("document.body.dataset.publicInitialized === 'true'");
                    assert.equal(await evaluate("document.querySelectorAll('#staff-login-dialog').length"), 1, `${pathname} includes one login popup`);
                    const links = await evaluate("[...document.querySelectorAll('a')].filter(link => new URL(link.href).pathname === '/login' && !link.closest('dialog')).length");
                    assert.ok(links >= 2, `${pathname} exposes header and footer login links`);
                    for (let index = 0; index < links; index++) {
                        if (width === 390 && index === 0) {
                            await evaluate("document.querySelector('[data-menu-toggle]').click()");
                        }
                        await evaluate(`[...document.querySelectorAll('a')].filter(link => new URL(link.href).pathname === '/login' && !link.closest('dialog'))[${index}].focus()`);
                        await key("Enter");
                        await until("document.querySelector('#staff-login-dialog')?.open");
                        assert.equal(await evaluate("location.pathname"), pathname);
                        assert.equal(await evaluate("document.activeElement.id"), "staff-login-email");
                        await key("Escape");
                        await until("!document.querySelector('#staff-login-dialog').open");
                        const focusTarget = width === 390 && index === 0
                            ? "document.querySelector('[data-menu-toggle]')"
                            : `[...document.querySelectorAll('a')].filter(link => new URL(link.href).pathname === '/login' && !link.closest('dialog'))[${index}]`;
                        await until(`document.activeElement === ${focusTarget}`);
                    }
                }
            }
            await viewport(1440);
            await navigate();
        });

        await t.test("Caseworker links open registration overlays on every public page and switch from login", async () => {
            for (const width of [1440, 390]) {
                await viewport(width);
                for (const pathname of ["/", "/about", "/programs", "/community/activities", "/application"]) {
                    await navigate("", pathname);
                    await until("document.body.dataset.publicInitialized === 'true'");
                    assert.equal(await evaluate("document.querySelectorAll('[data-registration-dialog]').length"), 1, `${pathname} includes one registration popup`);
                    if (await evaluate("!!document.querySelector('footer a[data-registration-open]')")) {
                        await evaluate("document.querySelector('footer a[data-registration-open]').focus()");
                        await key("Enter");
                        await until("document.querySelector('[data-registration-dialog]').open");
                        assert.equal(await evaluate("document.activeElement.matches('[data-registration-dialog] input[name=email]')"), true);
                        assert.equal(await evaluate("location.pathname"), pathname);
                        await key("Escape");
                        await until("!document.querySelector('[data-registration-dialog]').open && !document.body.classList.contains('registration-scroll-locked')");
                        assert.equal(await evaluate("document.activeElement.matches('footer a[data-registration-open]')"), true);
                    }
                    if (width === 390) await evaluate("document.querySelector('[data-menu-toggle]').click()");
                    await evaluate("document.querySelector('.public-nav-login').focus()");
                    await key("Enter");
                    await until("document.querySelector('#staff-login-dialog').open");
                    await evaluate("document.querySelector('.staff-login-apply a').focus()");
                    await key("Enter");
                    await until("document.querySelector('[data-registration-dialog]').open && !document.querySelector('#staff-login-dialog').open");
                    assert.equal(await evaluate("document.querySelectorAll('dialog[open]').length"), 1);
                    assert.equal(await evaluate("document.activeElement.matches('[data-registration-dialog] input[name=email]')"), true);
                    assert.equal(await evaluate("location.pathname"), pathname);
                    await key("Escape");
                    await until("!document.querySelector('[data-registration-dialog]').open && !document.body.classList.contains('registration-scroll-locked')");
                    await evaluate("document.querySelector('.public-nav-login').click()");
                    await until("document.querySelector('#staff-login-dialog').open");
                    await evaluate("document.querySelector('.staff-login-apply a').click()");
                    await until("document.querySelector('[data-registration-dialog]').open && !document.querySelector('#staff-login-dialog').open");
                    await evaluate("document.querySelector('[data-registration-dialog] [data-staff-login-open]').focus()");
                    await key("Enter");
                    await until("document.querySelector('#staff-login-dialog').open && !document.querySelector('[data-registration-dialog]').open");
                    assert.equal(await evaluate("document.querySelectorAll('dialog[open]').length"), 1);
                    assert.equal(await evaluate("document.activeElement.id"), "staff-login-email");
                    await key("Escape");
                    await until("!document.querySelector('#staff-login-dialog').open");
                    const focusTarget = width === 390 ? "[data-menu-toggle]" : ".public-nav-login";
                    await until(`document.activeElement.matches('${focusTarget}')`);
                    assert.equal(await evaluate("document.querySelectorAll('[inert]').length"), 0);
                }
            }
            await viewport(1440);
            await navigate();
        });

        await t.test("About opens from Home, refreshes directly and has honest responsive placeholders", async () => {
            await evaluate("[...document.querySelectorAll('[data-public-navigation] a')].find(link => link.textContent.trim() === 'About').focus()");
            await key("Enter");
            await until("location.pathname === '/about' && document.body.dataset.publicInitialized === 'true'");
            assert.equal(await evaluate("document.querySelector('h1').textContent"), "About RLCP");
            await command("Page.reload", { ignoreCache: true });
            await until("document.readyState === 'complete' && document.body.dataset.publicInitialized === 'true'");
            assert.equal(await evaluate("document.querySelector('[data-public-navigation] [aria-current]').textContent.trim()"), "About");
            assert.equal(await evaluate("document.querySelectorAll('[data-public-navigation] [aria-current]').length"), 1);
            assert.equal(await evaluate("document.querySelectorAll('main button, main a, video, iframe, main form').length"), 0, "Unapproved content must not render pretend controls");
            assert.equal(await evaluate("[...document.querySelectorAll('[role=img]')].every(image => image.getAttribute('aria-label'))"), true);
            for (const href of await evaluate("[...document.querySelectorAll('a')].map(link => link.href)")) {
                const url = new URL(href);
                assert.equal(url.origin, origin);
                assert.ok(["/", "/about", "/programs", "/community/activities", "/application", "/login", "/register", "/forgot-password"].includes(url.pathname));
                if (url.hash && url.pathname === "/") assert.ok(html.includes(`id="${url.hash.slice(1)}"`), href);
                if (url.hash && url.pathname === "/about") assert.equal(await evaluate(`!!document.getElementById(${JSON.stringify(url.hash.slice(1))})`), true);
            }
            for (const [width, name] of [[1440, "desktop"], [1024, null], [768, "tablet"], [390, "mobile"], [320, null]]) {
                await viewport(width, width < 640 ? 844 : 950);
                const overflow = await evaluate("[...document.body.querySelectorAll('*')].filter(element => { const rect = element.getBoundingClientRect(); return rect.width && (rect.right > document.documentElement.clientWidth + 1 || rect.left < -1); }).map(element => ({ tag: element.tagName, class: element.className, width: element.getBoundingClientRect().width, left: element.getBoundingClientRect().left })).slice(0, 12)");
                assert.equal(await evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth"), false, `About overflow at ${width}px: ${JSON.stringify(overflow)}`);
                if (width === 1440) {
                    assert.ok(await evaluate("Math.abs(document.querySelector('.about-hero__frame').offsetWidth / document.querySelector('.about-hero__frame').offsetHeight - 3) < .02"));
                }
                assert.ok(await evaluate("Math.abs(document.querySelector('.about-community-photo').offsetWidth / document.querySelector('.about-community-photo').offsetHeight - 1.5) < .02"));
                if (name) await screenshot(`about-${name}`);
            }
            await evaluate("document.querySelector('[data-menu-toggle]').focus()");
            await key("Enter");
            assert.equal(await evaluate("document.querySelector('[data-menu-toggle]').getAttribute('aria-expanded')"), "true");
            await key("Tab");
            assert.equal(await evaluate("getComputedStyle(document.activeElement).outlineStyle"), "solid");
            await key("Escape");
            assert.equal(await evaluate("document.activeElement.matches('[data-menu-toggle]')"), true);
            await viewport(1440);
            await evaluate("document.querySelector('[data-public-theme-toggle]').focus()");
            await key(" ");
            assert.equal(await evaluate("document.body.dataset.publicTheme"), "dark");
            await screenshot("about-dark");
            await evaluate("document.querySelector('[data-public-theme-toggle]').click()");
            await command("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "reduce" }] });
            assert.equal(await evaluate("getComputedStyle(document.querySelector('[data-public-reveal]')).animationName"), "none");
            await command("Emulation.setEmulatedMedia", { features: [] });
            await command("Emulation.setScriptExecutionDisabled", { value: true });
            await command("Page.reload", { ignoreCache: true });
            await until("document.readyState === 'complete' && !document.body.dataset.publicInitialized");
            assert.equal(await evaluate("document.querySelector('[data-public-navigation]').hidden"), false);
            assert.equal(await evaluate("document.querySelector('[data-public-navigation] [aria-current]').textContent.trim()"), "About");
            await viewport(320, 844);
            assert.equal(await evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth"), false);
            await command("Emulation.setScriptExecutionDisabled", { value: false });
            await viewport(1440);
            await navigate();
        });

        await t.test("Programs navigation, article previews and responsive layouts work without submissions", async () => {
            await evaluate("[...document.querySelectorAll('[data-public-navigation] a')].find(link => link.textContent.trim() === 'Programs').focus()");
            await key("Enter");
            await until("location.pathname === '/programs' && document.body.dataset.publicInitialized === 'true'");
            await command("Page.reload", { ignoreCache: true });
            await until("document.readyState === 'complete' && document.body.dataset.publicInitialized === 'true'");
            assert.equal(await evaluate("document.querySelector('[data-public-navigation] [aria-current]').textContent.trim()"), "Programs");
            assert.equal(await evaluate("document.querySelectorAll('[data-public-navigation] [aria-current]').length"), 1);
            assert.equal(await evaluate("document.querySelectorAll('details.programs-article-preview').length"), 3);
            assert.equal(await evaluate("document.querySelectorAll('main form, iframe, video').length"), 0);
            assert.equal(await evaluate("[...document.querySelectorAll('[role=img]')].every(image => image.getAttribute('aria-label'))"), true);
            for (const href of await evaluate("[...document.querySelectorAll('a')].map(link => link.href)")) {
                const url = new URL(href);
                assert.equal(url.origin, origin);
                assert.ok(["/", "/about", "/programs", "/community/activities", "/application", "/login", "/register", "/forgot-password"].includes(url.pathname));
                if (url.hash && url.pathname === "/") assert.ok(html.includes(`id="${url.hash.slice(1)}"`), href);
                if (url.hash && url.pathname === "/programs") assert.equal(await evaluate(`!!document.getElementById(${JSON.stringify(url.hash.slice(1))})`), true);
            }
            await evaluate("document.querySelector('.programs-hero a').focus()");
            await key("Enter");
            await until("location.hash === '#program-overview'");
            for (const [width, name] of [[1440, "desktop"], [1024, null], [768, "tablet"], [390, "mobile"], [320, null]]) {
                await viewport(width, width < 640 ? 844 : 950);
                const overflow = await evaluate("[...document.body.querySelectorAll('*')].filter(element => { const rect = element.getBoundingClientRect(); return rect.width && (rect.right > document.documentElement.clientWidth + 1 || rect.left < -1); }).map(element => ({ tag: element.tagName, class: element.className, width: element.getBoundingClientRect().width })).slice(0, 12)");
                assert.equal(await evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth"), false, `Programs overflow at ${width}px: ${JSON.stringify(overflow)}`);
                if (name) {
                    await evaluate("document.activeElement.blur()");
                    await screenshot(`programs-${name}`);
                }
                const requestsBefore = requests.length;
                for (let index = 0; index < 3; index++) {
                    await evaluate(`document.querySelectorAll('.programs-article-preview summary')[${index}].focus()`);
                    assert.equal(await evaluate("getComputedStyle(document.activeElement).outlineStyle"), "solid");
                    await key("Enter");
                    assert.equal(await evaluate(`document.querySelectorAll('.programs-article-preview')[${index}].open`), true);
                    assert.notEqual(await evaluate(`getComputedStyle(document.querySelectorAll('.programs-read-less')[${index}]).display`), "none");
                    assert.equal(await evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth"), false, `Open article overflow at ${width}px`);
                    if (width === 390 && index === 0) await screenshot("programs-article-mobile");
                    await key(" ");
                    assert.equal(await evaluate(`document.querySelectorAll('.programs-article-preview')[${index}].open`), false);
                }
                assert.equal(requests.length, requestsBefore, "Articles must open locally without network requests");
            }
            await evaluate("document.querySelector('[data-menu-toggle]').focus()");
            await key("Enter");
            assert.equal(await evaluate("document.querySelector('[data-menu-toggle]').getAttribute('aria-expanded')"), "true");
            await key("Tab");
            await key("Escape");
            assert.equal(await evaluate("document.activeElement.matches('[data-menu-toggle]')"), true);
            await viewport(1440);
            await evaluate("document.querySelector('[data-public-theme-toggle]').focus()");
            await key(" ");
            assert.equal(await evaluate("document.body.dataset.publicTheme"), "dark");
            await screenshot("programs-dark");
            await evaluate("document.querySelector('[data-public-theme-toggle]').click()");
            await command("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "reduce" }] });
            assert.equal(await evaluate("getComputedStyle(document.querySelector('[data-public-reveal]')).animationName"), "none");
            await command("Emulation.setEmulatedMedia", { features: [] });
            await command("Emulation.setScriptExecutionDisabled", { value: true });
            await command("Page.reload", { ignoreCache: true });
            await until("document.readyState === 'complete' && !document.body.dataset.publicInitialized");
            await viewport(320, 844);
            assert.equal(await evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth"), false);
            await evaluate("document.querySelector('.programs-article-preview summary').focus()");
            await key("Enter");
            assert.equal(await evaluate("document.querySelector('.programs-article-preview').open"), true);
            await key(" ");
            assert.equal(await evaluate("document.querySelector('.programs-article-preview').open"), false);
            await command("Emulation.setScriptExecutionDisabled", { value: false });
            await evaluate("document.querySelector('.programs-application a').click()");
            await until("location.pathname === '/application' && !!document.querySelector('[data-application-draft]')");
            await viewport(1440);
            await navigate();
        });

        await t.test("public Activities filters, navigation and empty states work with and without JavaScript", async () => {
            await evaluate("[...document.querySelectorAll('[data-public-navigation] a')].find(link => link.textContent.trim() === 'Activities').focus()");
            await key("Enter");
            await until("location.pathname === '/community/activities' && document.body.dataset.publicInitialized === 'true'");
            await command("Page.reload", { ignoreCache: true });
            await until("document.readyState === 'complete' && document.body.dataset.publicInitialized === 'true'");
            assert.equal(await evaluate("document.querySelector('h1').textContent"), "Community in Action");
            assert.equal(await evaluate("document.querySelector('[data-public-navigation] [aria-current]').textContent.trim()"), "Activities");
            assert.equal(await evaluate("document.querySelectorAll('main article, main button, main form, [aria-roledescription=carousel], nav[aria-label=Pagination]').length"), 0);
            assert.equal(await evaluate("[...document.images].filter(image => !image.closest('dialog')).every(image => image.getAttribute('alt') !== null && image.src.endsWith('/images/branding/rlc-logo.png'))"), true);
            for (const href of await evaluate("[...document.querySelectorAll('a')].map(link => link.href)")) {
                const url = new URL(href);
                assert.equal(url.origin, origin);
                assert.ok(["/", "/about", "/programs", "/community/activities", "/application", "/login", "/register", "/forgot-password"].includes(url.pathname));
                if (url.hash) assert.equal(await evaluate(`!!document.getElementById(${JSON.stringify(url.hash.slice(1))})`), true);
            }
            for (const [width, name] of [[1440, "desktop"], [1024, null], [768, "tablet"], [390, "mobile"], [320, null]]) {
                await viewport(width, width < 640 ? 844 : 950);
                assert.equal(await evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth"), false, `Activities overflow at ${width}px`);
                assert.equal(await evaluate("[...document.querySelectorAll('.activities-filters a')].every(link => link.getBoundingClientRect().height >= 44)"), true);
                if (name) {
                    await evaluate("document.activeElement.blur()");
                    await screenshot(`activities-${name}`);
                }
            }
            await evaluate("document.querySelector('[data-menu-toggle]').focus()");
            await key("Enter");
            assert.equal(await evaluate("document.querySelector('[data-menu-toggle]').getAttribute('aria-expanded')"), "true");
            await key("Tab");
            assert.equal(await evaluate("getComputedStyle(document.activeElement).outlineStyle"), "solid");
            await key("Escape");
            assert.equal(await evaluate("document.activeElement.matches('[data-menu-toggle]')"), true);

            for (const [filter, message] of [["past", "No past public activities."], ["all", "No public activities yet."], ["upcoming", "No upcoming public activities."]]) {
                await evaluate(`document.querySelector('.activities-filters a[href*="filter=${filter}"]').focus()`);
                // Exercise real keyboard focus after each full-page filter navigation.
                await key("Tab");
                await key("Tab", { modifiers: 8 });
                assert.equal(await evaluate("document.activeElement.closest('.activities-filters') !== null"), true);
                assert.equal(await evaluate("getComputedStyle(document.activeElement).outlineStyle"), "solid");
                await key("Enter");
                await until(`document.readyState === 'complete' && document.getElementById('activity-list-title')?.textContent === ${JSON.stringify(message)}`);
                assert.equal(await evaluate("new URL(location.href).searchParams.get('filter')"), filter);
                assert.equal(await evaluate("document.querySelector('.activities-filters [aria-current]').href"), `${origin}/community/activities?filter=${filter}#activity-list`);
            }
            await evaluate("history.back()");
            await until("document.getElementById('activity-list-title')?.textContent === 'No public activities yet.'");
            await command("Page.reload", { ignoreCache: true });
            await until("document.readyState === 'complete' && document.getElementById('activity-list-title')?.textContent === 'No public activities yet.'");

            await viewport(1440);
            for (const theme of ["light", "dark"]) {
                if (theme === "dark") {
                    await evaluate("document.querySelector('[data-public-theme-toggle]').focus()");
                    await key(" ");
                    assert.equal(await evaluate("document.body.dataset.publicTheme"), "dark");
                    await screenshot("activities-dark");
                }
                const contrast = await evaluate(`(() => {
                    const luminance = color => {
                        const channels = color.match(/[\\d.]+/g).slice(0, 3).map(Number).map(value => value / 255).map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4);
                        return channels[0] * .2126 + channels[1] * .7152 + channels[2] * .0722;
                    };
                    return ['.activities-filters [aria-current]', '.activities-publication-note', '.activities-feature-empty'].map(selector => {
                        const style = getComputedStyle(document.querySelector(selector));
                        const foreground = luminance(style.color), background = luminance(style.backgroundColor);
                        return (Math.max(foreground, background) + .05) / (Math.min(foreground, background) + .05);
                    });
                })()`);
                assert.ok(contrast.every(ratio => ratio >= 4.5), `${theme} Activities text contrast: ${contrast}`);
            }
            await evaluate("document.querySelector('[data-public-theme-toggle]').click()");
            await command("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "reduce" }] });
            assert.equal(await evaluate("[...document.querySelectorAll('main *, .activities-hero')].every(element => getComputedStyle(element).animationName === 'none')"), true);
            await command("Emulation.setEmulatedMedia", { features: [] });

            await command("Emulation.setScriptExecutionDisabled", { value: true });
            await command("Page.reload", { ignoreCache: true });
            await until("document.readyState === 'complete' && !document.body.dataset.publicInitialized");
            await viewport(320, 844);
            assert.equal(await evaluate("document.querySelector('[data-public-navigation]').hidden"), false);
            for (const filter of ["upcoming", "past", "all"]) {
                await evaluate(`document.querySelector('.activities-filters a[href*="filter=${filter}"]').focus()`);
                await key("Enter");
                await until(`document.readyState === 'complete' && document.querySelector('.activities-filters [aria-current]')?.href.includes('filter=${filter}')`);
                assert.equal(await evaluate("new URL(location.href).searchParams.get('filter')"), filter);
                assert.equal(await evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth"), false);
            }
            await command("Emulation.setScriptExecutionDisabled", { value: false });
            await viewport(1440);
            await navigate();
        });

        await t.test("Start an Application reaches the separate draft with responsive accessible fields", async () => {
            await evaluate("document.querySelector('.public-nav-apply').click()");
            await until("location.pathname === '/application' && !!document.querySelector('[data-application-draft][data-initialized]')");
            assert.equal(await evaluate("document.querySelectorAll('h1').length"), 1);
            assert.equal(await evaluate("[...document.querySelectorAll('[data-draft-field]')].every(input => document.querySelector('label[for=\"' + input.id + '\"]'))"), true);
            assert.equal(await evaluate("document.querySelector('[data-application-draft]').getAttribute('method')"), "dialog");
            assert.equal(await evaluate("document.querySelectorAll('[data-application-draft] [name]').length"), 0);
            for (const [width, name] of [[1440, "desktop"], [768, "tablet"], [390, "mobile"], [320, "narrow"]]) {
                await viewport(width, width < 640 ? 844 : 950);
                assert.equal(await evaluate("document.documentElement.scrollWidth > innerWidth"), false);
                await loadAllImages();
                if (name !== "narrow") await screenshot(`application-${name}`);
            }
            await viewport(1440);
            await evaluate("document.querySelector('[data-public-theme-toggle]').click()");
            await screenshot("application-dark");
            await evaluate("document.querySelector('[data-public-theme-toggle]').click()");
        });

        await t.test("draft validation, consent acknowledgement, clearing and success never transmit entries", async () => {
            const requestsBefore = requests.length;
            await evaluate("document.querySelector('[data-application-draft]').requestSubmit()");
            assert.equal(await evaluate("document.querySelectorAll('[data-draft-field][aria-invalid=true]').length"), 3);
            assert.equal(await evaluate("document.activeElement.id"), "application-guardian");
            await evaluate("document.getElementById('application-guardian').value='Synthetic Guardian'; document.getElementById('application-contact').value='abc'; document.getElementById('application-consent').checked=true; document.querySelector('[data-application-draft]').requestSubmit()");
            assert.equal(await evaluate("document.activeElement.id"), "application-contact");
            assert.match(await evaluate("document.getElementById('application-contact-error').textContent"), /7–15 digits/);
            await evaluate("document.getElementById('application-contact').value='+63 900 000 0000'; document.querySelector('[data-application-draft] button[type=submit]').focus()");
            await key("Enter");
            assert.match(await evaluate("document.querySelector('[data-draft-status]').textContent"), /Nothing has been submitted, saved, or emailed/);
            assert.equal(await evaluate("document.querySelector('[data-draft-status]') === document.activeElement"), true);
            assert.equal(await evaluate("document.getElementById('application-guardian').value + document.getElementById('application-contact').value"), "");
            assert.equal(await evaluate("document.getElementById('application-consent').checked"), false);
            await evaluate("document.getElementById('application-guardian').value='Synthetic Guardian'; document.querySelector('[data-application-draft] button[type=reset]').click()");
            assert.equal(await evaluate("document.getElementById('application-guardian').value"), "");
            assert.equal(await evaluate("document.querySelector('[data-draft-status]').textContent"), "");
            assert.equal(await evaluate("location.search"), "");
            assert.equal(await evaluate("[...Object.values(localStorage), ...Object.values(sessionStorage)].some(value => value.includes('Synthetic') || value.includes('900'))"), false);
            assert.equal(requests.length, requestsBefore, "Draft interactions must not issue any network requests");
            await command("Emulation.setScriptExecutionDisabled", { value: true });
            await command("Page.reload", { ignoreCache: true });
            await until("document.readyState === 'complete' && !document.body.dataset.publicInitialized");
            assert.equal(await evaluate("document.querySelector('[data-application-draft] fieldset').disabled"), true);
            assert.equal(await evaluate("document.querySelector('[data-application-draft] button[type=submit]').matches(':disabled')"), true);
            await command("Emulation.setScriptExecutionDisabled", { value: false });
            await navigate();
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
        await writeFile(path.join(screenshots, "browser-results.json"), JSON.stringify({ viewportWidths: [1440, 1024, 768, 390, 320], consoleErrors: errors, requestCount: requests.length, source: "Isolated PHP public render; all browser requests intercepted; no live application access", screenshots: ["desktop.png", "tablet.png", "mobile.png", "desktop-dark.png", "application-desktop.png", "application-tablet.png", "application-mobile.png", "application-dark.png", "about-desktop.png", "about-tablet.png", "about-mobile.png", "about-dark.png", "programs-desktop.png", "programs-tablet.png", "programs-mobile.png", "programs-dark.png", "programs-article-mobile.png", "activities-desktop.png", "activities-tablet.png", "activities-mobile.png", "activities-dark.png"] }, null, 2) + "\n");
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
