import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { mkdir, mkdtemp, readFile, realpath, rm } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

// All POSTs are intercepted and mocked. No registration, job, or mail can be
// created. Never print page bodies, cookies, CSRF values, or request payloads.
const binary = process.env.REGISTRATION_UI_BROWSER;
const origin = new URL(process.env.REGISTRATION_UI_ORIGIN || "http://192.168.110.41:8000").origin;
const workspace = fileURLToPath(new URL("../../", import.meta.url));
const directory = path.join(workspace, "storage/framework/testing");
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

test(
    "registration loading and error recovery in Edge with every POST mocked",
    { skip: !binary, timeout: 60000 },
    async () => {
        let browser,
            profile,
            socket,
            nextId = 0;
        const pending = new Map();
        const posts = [];
        let postCount = 0;
        function command(method, params = {}) {
            return new Promise((resolve, reject) => {
                const id = ++nextId;
                const timer = setTimeout(() => {
                    pending.delete(id);
                    reject(new Error("Browser command timed out"));
                }, 10000);
                pending.set(id, { resolve, reject, timer });
                socket.send(JSON.stringify({ id, method, params }));
            });
        }
        async function evaluate(expression) {
            const result = await command("Runtime.evaluate", { expression, returnByValue: true });
            if (result.exceptionDetails)
                throw new Error("Browser evaluation failed; details withheld");
            return result.result.value;
        }
        async function until(expression) {
            for (let i = 0; i < 100; i++) {
                try {
                    if (await evaluate(expression)) return;
                } catch {}
                await delay(50);
            }
            throw new Error("Expected browser state was not reached");
        }
        const state = `(() => {
        const form = document.querySelector('form[method="POST"]');
        const button = document.querySelector('[data-test="registration-email-button"]');
        const dots = document.querySelector('.registration-loading-dots');
        return { busy: form.getAttribute('aria-busy'), disabled: button.disabled,
            dots: dots.getClientRects().length > 0, text: button.innerText,
            motion: getComputedStyle(dots.firstElementChild).animationName,
            error: document.getElementById('registration-email-error').innerText,
            emailFocused: document.activeElement?.name === 'email' };
    })()`;
        async function submit() {
            await evaluate(`document.querySelector('form[method="POST"]').requestSubmit(); true`);
            for (let i = 0; i < 100 && !posts.length; i++) await delay(25);
            assert.ok(posts.length, "Submission must reach the mock interceptor");
        }
        async function respond(status, data) {
            await command("Fetch.fulfillRequest", {
                requestId: posts.shift(),
                responseCode: status,
                responseHeaders: [{ name: "Content-Type", value: "application/json" }],
                body: Buffer.from(JSON.stringify(data)).toString("base64"),
            });
            await until(
                `document.querySelector('form[method="POST"]').getAttribute('aria-busy') === 'false'`,
            );
        }
        try {
            await mkdir(directory, { recursive: true });
            const parent = await realpath(directory);
            assert.ok(parent.startsWith((await realpath(workspace)) + path.sep));
            profile = await mkdtemp(path.join(parent, "registration-ui-profile-"));
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
                { windowsHide: true, stdio: "ignore" },
            );
            let startupFailed = false;
            browser.on("error", () => {
                startupFailed = true;
            });
            let port;
            for (let i = 0; i < 100 && !port; i++) {
                if (startupFailed || browser.exitCode !== null) break;
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
            assert.ok(port, "Installed browser must start");
            const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
            socket = new WebSocket(pages.find((page) => page.type === "page").webSocketDebuggerUrl);
            await new Promise((resolve, reject) => {
                socket.addEventListener("open", resolve, { once: true });
                socket.addEventListener(
                    "error",
                    () => reject(new Error("Browser connection failed")),
                    { once: true },
                );
            });
            socket.addEventListener("message", (event) => {
                const data = JSON.parse(event.data);
                if (data.id) {
                    const item = pending.get(data.id);
                    if (!item) return;
                    clearTimeout(item.timer);
                    pending.delete(data.id);
                    if (data.error)
                        item.reject(new Error("Browser command failed; details withheld"));
                    else item.resolve(data.result);
                } else if (data.method === "Fetch.requestPaused") {
                    const p = data.params;
                    const url = new URL(p.request.url);
                    if (
                        p.request.method === "POST" &&
                        url.origin === origin &&
                        url.pathname === "/register"
                    ) {
                        postCount++;
                        posts.push(p.requestId);
                    } else if (
                        ["GET", "HEAD"].includes(p.request.method) &&
                        url.origin === origin &&
                        !url.pathname.startsWith("/register/verify/")
                    ) {
                        void command("Fetch.continueRequest", { requestId: p.requestId }).catch(
                            () => {},
                        );
                    } else {
                        void command("Fetch.failRequest", {
                            requestId: p.requestId,
                            errorReason: "BlockedByClient",
                        }).catch(() => {});
                    }
                }
            });
            await command("Page.enable");
            await command("Emulation.setEmulatedMedia", {
                features: [{ name: "prefers-reduced-motion", value: "no-preference" }],
            });
            await command("Fetch.enable", {
                patterns: [{ urlPattern: "*", requestStage: "Request" }],
            });
            await command("Page.navigate", { url: origin + "/register" });
            await until(
                `window.Alpine && document.querySelector('[x-data^="registrationEmailForm"]')?._x_dataStack?.[0]?.submitting === false`,
            );
            await evaluate(
                `document.querySelector('input[name="email"]').value = 'browser@example.invalid'; true`,
            );
            await submit();
            let snapshot = await evaluate(state);
            assert.equal(snapshot.busy, "true");
            assert.equal(snapshot.disabled, true);
            assert.equal(snapshot.dots, true);
            assert.match(snapshot.text, /Queuing verification email/);
            assert.equal(snapshot.motion, "registration-dot");
            await evaluate(`document.querySelector('form[method="POST"]').requestSubmit(); true`);
            assert.equal(postCount, 1);
            await command("Emulation.setEmulatedMedia", {
                features: [{ name: "prefers-reduced-motion", value: "reduce" }],
            });
            assert.equal((await evaluate(state)).motion, "none");
            const message =
                "If this email is available, a verification email has been queued. Check your inbox shortly.";
            await respond(202, { message });
            assert.equal((await evaluate(state)).disabled, false);
            assert.equal(
                await evaluate(`document.body.innerText.includes(${JSON.stringify(message)})`),
                true,
            );
            await submit();
            await respond(429, {});
            snapshot = await evaluate(state);
            assert.equal(snapshot.disabled, false);
            assert.match(snapshot.error, /Too many attempts/);
            await submit();
            await command("Fetch.failRequest", {
                requestId: posts.shift(),
                errorReason: "InternetDisconnected",
            });
            await until(
                `document.querySelector('form[method="POST"]').getAttribute('aria-busy') === 'false'`,
            );
            assert.match((await evaluate(state)).error, /could not confirm/);
            await delay(100);
            assert.equal(postCount, 3);
            await submit();
            await respond(422, { errors: { email: ["Enter a valid email address."] } });
            snapshot = await evaluate(state);
            assert.equal(snapshot.emailFocused, true);
            assert.equal(snapshot.disabled, false);
            assert.equal(postCount, 4);
        } finally {
            if (socket?.readyState === WebSocket.OPEN) {
                await command("Browser.close").catch(() => {});
                socket.close();
            }
            for (const item of pending.values()) clearTimeout(item.timer);
            pending.clear();
            if (browser && browser.exitCode === null) {
                await delay(200);
                if (browser.exitCode === null) browser.kill();
            }
            if (profile) {
                const parent = await realpath(directory);
                const target = path.resolve(profile);
                assert.equal(path.dirname(target), parent);
                assert.ok(path.basename(target).startsWith("registration-ui-profile-"));
                await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
            }
        }
    },
);
