import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { readFile, writeFile, mkdtemp, rm, realpath } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

// Only HTML rendered by AssignedBeneficiaryAccessTest's guarded memory database.
// Scripts and HTTP resources are blocked; no real application session is opened.
const browserBinary = process.env.ASSIGNED_ACCESS_UI_BROWSER;
const workspace = fileURLToPath(new URL("../../", import.meta.url));
const directory = path.join(workspace, "storage/framework/testing/assigned-access-ui");
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let browser, profile, socket, frameId, css;
let nextId = 0;
const waiting = new Map();

function command(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++nextId;
        const timer = setTimeout(() => {
            waiting.delete(id);
            reject(new Error(`Browser command timed out: ${method}`));
        }, 20000);
        waiting.set(id, { resolve, reject, timer });
        socket.send(JSON.stringify({ id, method, params }));
    });
}

before(async () => {
    if (!browserBinary) return;
    const parent = await realpath(directory);
    const root = await realpath(workspace);
    assert.ok(parent.startsWith(root + path.sep));
    profile = await mkdtemp(path.join(parent, "browser-profile-"));
    browser = spawn(browserBinary, [
        "--headless=new", "--disable-gpu", "--disable-extensions", "--disable-background-networking",
        "--disable-component-update", "--no-first-run", "--no-default-browser-check",
        "--remote-debugging-port=0", `--user-data-dir=${profile}`, "about:blank",
    ], { windowsHide: true, stdio: ["ignore", "ignore", "pipe"] });
    let startupError = "";
    browser.on("error", (error) => { startupError = error.message; });
    browser.stderr.on("data", () => {});
    let port;
    for (let attempt = 0; attempt < 150 && !port; attempt++) {
        if (startupError || browser.exitCode !== null || browser.signalCode !== null) break;
        try {
            port = Number((await readFile(path.join(profile, "DevToolsActivePort"), "utf8")).split("\n")[0]);
        } catch { await delay(100); }
    }
    assert.ok(port, `Installed browser must start: ${startupError || "startup timed out"}`);
    const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
    socket = new WebSocket(pages.find((page) => page.type === "page").webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.addEventListener("open", resolve, { once: true });
        socket.addEventListener("error", reject, { once: true });
    });
    socket.addEventListener("message", (event) => {
        const message = JSON.parse(event.data);
        const pending = waiting.get(message.id);
        if (!pending) return;
        clearTimeout(pending.timer);
        waiting.delete(message.id);
        if (message.error) pending.reject(new Error(message.error.message));
        else pending.resolve(message.result);
    });
    await command("Browser.getVersion");
    await command("Page.enable");
    await command("Network.enable");
    await command("Network.setBlockedURLs", { urls: ["http://*", "https://*"] });
    frameId = (await command("Page.getFrameTree")).frameTree.frame.id;
    const manifest = JSON.parse(await readFile(path.join(workspace, "public/build/manifest.json"), "utf8"));
    css = await readFile(path.join(workspace, "public/build", manifest["resources/css/app.css"].file), "utf8");
}, { timeout: 60000 });

after(async () => {
    if (socket?.readyState === WebSocket.OPEN) {
        await command("Browser.close").catch(() => {});
        socket.close();
    }
    if (browser && browser.exitCode === null) {
        await delay(200);
        if (browser.exitCode === null) browser.kill();
    }
    for (const pending of waiting.values()) clearTimeout(pending.timer);
    waiting.clear();
    if (profile) {
        const parent = await realpath(directory);
        const target = path.resolve(profile);
        assert.equal(path.dirname(target), parent, "Only this isolated browser profile may be removed");
        assert.ok(path.basename(target).startsWith("browser-profile-"));
        await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
}, { timeout: 15000 });

for (const view of ["caseworker-registry", "caseworker-caseloads", "director-caseloads", "empty-registry",
    "caseworker-dashboard", "caseworker-attendance", "caseworker-reports"]) {
    for (const dark of [false, true]) {
        for (const width of [375, 1440]) {
            test(`${view}, ${dark ? "dark" : "light"}, ${width}px`, { skip: !browserBinary }, async () => {
                let html = await readFile(path.join(directory, `${view}.html`), "utf8");
                html = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, "")
                    .replace(/<link\b[^>]*>/gi, "")
                    .replace(/data-csrf="[^"]*"/g, 'data-csrf="synthetic-only"')
                    .replace(/<html\b[^>]*>/i, `<html lang="en" class="${dark ? "dark" : ""}">`)
                    .replace("</head>", `<style>${css}</style></head>`);
                await command("Emulation.setDeviceMetricsOverride", { width, height: 1000, deviceScaleFactor: 1, mobile: width < 700 });
                await command("Page.setDocumentContent", { frameId, html });
                const evaluation = await command("Runtime.evaluate", {
                    returnByValue: true,
                    awaitPromise: true,
                    expression: `new Promise(resolve => requestAnimationFrame(() => resolve({
                        text: document.body.textContent,
                        html: document.documentElement.outerHTML,
                        pageWidth: document.documentElement.scrollWidth,
                        ownerSelect: !!document.querySelector('select[name="caseworker"]'),
                        createLinks: [...document.querySelectorAll('a[href]')].filter(node => new URL(node.href, 'http://localhost').pathname === '/beneficiaries/create').length,
                        folders: [...document.querySelectorAll('[data-caseload]')].map(node => node.dataset.caseload),
                        counts: [...document.querySelectorAll('[data-caseload-count]')].map(node => node.textContent.trim()),
                        folderArt: document.querySelectorAll('.rlcr-folder-art').length
                    })))`,
                });
                assert.equal(evaluation.exceptionDetails, undefined, JSON.stringify(evaluation.exceptionDetails));
                const page = evaluation.result.value;
                if (view === "director-caseloads") {
                    assert.equal(page.createLinks, 1);
                    assert.equal(page.folders.length, 4);
                    assert.ok(page.folders.includes("unassigned"));
                    assert.equal(page.folderArt, 4);
                } else {
                    assert.equal(page.createLinks, 0);
                    assert.ok(!page.text.includes("Add Beneficiary"));
                    assert.ok(!page.text.includes("Register Beneficiary"));
                    assert.ok(!page.html.includes("/beneficiaries/create"));
                    for (const label of ["other", "unassigned", "archived"]) {
                        assert.ok(!page.html.includes(`Synthetic Person ${label}`));
                        assert.ok(!page.html.includes(`000-SYNTHETIC-${label}`));
                        assert.ok(!page.html.includes(`synthetic-${label}.png`));
                    }
                    assert.equal(page.ownerSelect, false);
                }
                if (view === "caseworker-caseloads") {
                    assert.equal(page.folders.length, 1);
                    assert.equal(page.folderArt, 1);
                    assert.ok(!page.text.includes("Synthetic Caseworker Two"));
                }
                if (view === "caseworker-dashboard") {
                    assert.deepEqual(page.counts, ["2", "1", "1"]);
                    assert.ok(page.text.includes("Synthetic Organization Activity"));
                }
                if (view === "empty-registry") {
                    assert.ok(page.text.includes("No beneficiaries found"));
                    assert.ok(!page.html.includes("000-SYNTHETIC-"));
                }
                if (view.includes("registry") || view.includes("caseloads") || view.includes("dashboard")) {
                    assert.ok(page.pageWidth <= width, `Page overflow: ${page.pageWidth}px > ${width}px`);
                }
                const screenshot = await command("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
                await writeFile(path.join(directory, `${view}-${dark ? "dark" : "light"}-${width}.png`), Buffer.from(screenshot.data, "base64"));
            });
        }
    }
}
