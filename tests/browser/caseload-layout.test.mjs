import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { readFile, writeFile, mkdtemp, rm, realpath } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

// Optional browser check using an already installed Chromium browser. Fixtures
// come only from CaseworkerCaseloadTest's guarded memory database. All scripts
// and external resources are removed: this never requests the real application.
const browserBinary = process.env.CASELOAD_UI_BROWSER;
const workspace = fileURLToPath(new URL("../../", import.meta.url));
const directory = path.join(workspace, "storage/framework/testing/caseload-ui");
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let browser,
    profile,
    socket,
    frameId,
    css,
    nextId = 0;
const waiting = new Map();

function command(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++nextId;
        const timer = setTimeout(() => {
            waiting.delete(id);
            reject(new Error(`Browser command timed out: ${method}`));
        }, 5000);
        waiting.set(id, { resolve, reject, timer });
        socket.send(JSON.stringify({ id, method, params }));
    });
}

before(
    async () => {
        if (!browserBinary) return;
        const parent = await realpath(directory);
        const root = await realpath(workspace);
        assert.ok(parent.startsWith(root + path.sep), "Fixtures must remain inside the workspace");
        profile = await mkdtemp(path.join(parent, "browser-profile-"));
        browser = spawn(
            browserBinary,
            [
                "--headless",
                "--disable-gpu",
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
        let startupError = "",
            startupOutput = "";
        browser.on("error", (error) => {
            startupError = error.message;
        });
        browser.stderr.on("data", (chunk) => {
            startupOutput = (startupOutput + chunk.toString()).slice(-1000);
        });
        let port;
        for (let attempt = 0; attempt < 150 && !port; attempt++) {
            if (startupError || browser.exitCode !== null || browser.signalCode !== null) break;
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
        assert.ok(
            port,
            `Installed browser must start a local debugging endpoint: ${startupError || startupOutput.trim() || "startup timed out"}`,
        );
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
        await command("Page.enable");
        frameId = (await command("Page.getFrameTree")).frameTree.frame.id;
        const manifest = JSON.parse(
            await readFile(path.join(workspace, "public/build/manifest.json"), "utf8"),
        );
        css = await readFile(
            path.join(workspace, "public/build", manifest["resources/css/app.css"].file),
            "utf8",
        );
    },
    { timeout: 30000 },
);

after(
    async () => {
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
            assert.equal(
                path.dirname(target),
                parent,
                "Only this isolated browser profile may be removed",
            );
            assert.ok(path.basename(target).startsWith("browser-profile-"));
            await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
        }
    },
    { timeout: 15000 },
);

for (const view of ["dashboard", "my-caseload"]) {
    for (const dark of [false, true]) {
        for (const width of [375, 768, 1440]) {
            test(
                `${view} ${dark ? "dark" : "light"} at ${width}px keeps content readable and within the viewport`,
                { skip: !browserBinary },
                async () => {
                    let html = await readFile(path.join(directory, `${view}.html`), "utf8");
                    html = html
                        .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, "")
                        .replace(/<link\b[^>]*>/gi, "")
                        .replace(/data-csrf="[^"]*"/g, 'data-csrf="test-only"')
                        .replace(/<html\b[^>]*>/i, `<html lang="en" class="${dark ? "dark" : ""}">`)
                        .replace("</head>", `<style>${css}</style></head>`);
                    await command("Emulation.setDeviceMetricsOverride", {
                        width,
                        height: 1000,
                        deviceScaleFactor: 1,
                        mobile: width < 700,
                    });
                    await command("Page.setDocumentContent", { frameId, html });
                    const { result } = await command("Runtime.evaluate", {
                        returnByValue: true,
                        awaitPromise: true,
                        expression: `
                        new Promise(resolve => requestAnimationFrame(() => {
                            const root = document.querySelector('.rlc-dashboard');
                            const style = getComputedStyle(root);
                            const stats = root.querySelector('.rlc-caseload-stats');
                            const panels = root.querySelector('.rlc-caseload-panels');
                            const counts = [...root.querySelectorAll('[data-caseload-count]')].map(node => node.textContent);
                            resolve({ width: innerWidth, pageWidth: document.documentElement.scrollWidth,
                                ink: style.color, surface: style.getPropertyValue('--rlc-surface').trim(),
                                title: root.querySelector('h1').textContent,
                                stats: stats ? getComputedStyle(stats).gridTemplateColumns.split(' ').length : null,
                                panels: panels ? getComputedStyle(panels).gridTemplateColumns.split(' ').length : null,
                                counts, search: !!root.querySelector('input[type=search]') });
                        }))`,
                    });
                    const layout = result.value;
                    assert.equal(layout.width, width);
                    assert.ok(
                        layout.pageWidth <= width,
                        `Page overflow: ${layout.pageWidth}px > ${width}px`,
                    );
                    assert.ok(layout.search);
                    assert.match(layout.ink, dark ? /246, 245, 252/ : /17, 19, 46/);
                    assert.notEqual(layout.surface, dark ? "#fff" : "#191a23");
                    if (view === "dashboard") {
                        assert.deepEqual(layout.counts, ["13", "7", "6"]);
                        assert.equal(layout.stats, width <= 700 ? 1 : 3);
                        assert.equal(layout.panels, width <= 1100 ? 1 : 2);
                    } else {
                        assert.equal(layout.title, "My Caseload");
                        const { result: grid } = await command("Runtime.evaluate", {
                            returnByValue: true,
                            expression: `
                            document.querySelector('.rlcr-table-wrap').classList.add('rlcr-cards');
                            ({ overflow: document.documentElement.scrollWidth > innerWidth,
                                display: getComputedStyle(document.querySelector('.rlcr-table tbody')).display })`,
                        });
                        assert.equal(grid.value.display, "grid");
                        assert.equal(grid.value.overflow, false);
                    }
                    const screenshot = await command("Page.captureScreenshot", {
                        format: "png",
                        captureBeyondViewport: true,
                    });
                    await writeFile(
                        path.join(directory, `${view}-${dark ? "dark" : "light"}-${width}.png`),
                        Buffer.from(screenshot.data, "base64"),
                    );
                },
            );
        }
    }
}
