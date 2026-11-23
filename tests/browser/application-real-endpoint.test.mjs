import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, rm, writeFile, realpath } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const workspace = fileURLToPath(new URL('../../', import.meta.url));
const browserPath = process.env.HOMEPAGE_UI_BROWSER;
const phpBinary = process.env.HOMEPAGE_UI_PHP;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

test('enabled application uses the real isolated Laravel endpoint', { skip: !browserPath || !phpBinary, timeout: 120000 }, async () => {
    const tempRoot = await realpath(os.tmpdir());
    const temp = await mkdtemp(path.join(tempRoot, 'rlcp-real-intake-'));
    const storage = path.join(temp, 'storage');
    for (const dir of ['framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) await mkdir(path.join(storage, dir), { recursive: true });
    const db = path.join(temp, 'synthetic.sqlite');
    await writeFile(db, '');
    const port = await new Promise((resolve, reject) => { const s = net.createServer(); s.on('error', reject); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => resolve(p)); }); });
    const origin = `http://127.0.0.1:${port}`;
    const cachePath = name => { const value = path.join(temp, name); return process.platform === 'win32' ? value.slice(2) : value; };
    const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: 'base64:cnJycnJycnJycnJycnJycnJycnJycnJycnJycnJycnI=', APP_URL: origin,
        DB_CONNECTION: 'sqlite', DB_DATABASE: db, DB_URL: '', SESSION_DRIVER: 'file', CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
        PPI_INTAKE_ENABLED: 'true', PPI_PRIVACY_CONTACT: 'Synthetic contact', PPI_PRIVACY_ACKNOWLEDGMENT: 'Synthetic acknowledgment', PPI_RETENTION_NOTICE: 'Synthetic retention',
        BREVO_API_KEY: 'isolated-test-key', BREVO_SMS_ENABLED: 'true', BREVO_SMS_SENDER: 'RLCP', BREVO_SMS_COUNTRY_CODES: '63', BREVO_SMS_DAILY_LIMIT: '5', MAIL_FROM_ADDRESS: 'test@example.test', LARAVEL_STORAGE_PATH: storage, VIEW_COMPILED_PATH: path.join(storage, 'framework/views'),
        APP_CONFIG_CACHE: cachePath('config.php'), APP_ROUTES_CACHE: cachePath('routes.php'), APP_EVENTS_CACHE: cachePath('events.php'), APP_SERVICES_CACHE: cachePath('services.php'), APP_PACKAGES_CACHE: cachePath('packages.php') };
    let server, browser, socket;
    const pending = new Map(); let nextId = 0;
    const command = (method, params = {}) => new Promise((resolve, reject) => { const id = ++nextId; const timer = setTimeout(() => { pending.delete(id); reject(new Error('Browser command timed out')); }, 10000); pending.set(id, { resolve, reject, timer }); socket.send(JSON.stringify({ id, method, params })); });
    const evaluate = async expression => { const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (result.exceptionDetails) throw new Error('Browser evaluation failed'); return result.result.value; };
    const until = async expression => {
        for (let i = 0; i < 150; i++) {
            if (await evaluate(expression)) return;
            await delay(100);
        }
        const state = await evaluate(`({
            title: document.title,
            initialized: Boolean(document.body?.dataset.publicInitialized),
            formAction: document.querySelector('[data-ppi-application]')?.getAttribute('action'),
            preview: Boolean(document.querySelector('.ppi-preview')),
            error: document.querySelector('[data-ppi-error]')?.textContent,
            scripts: [...document.querySelectorAll('script[src]')].map(script => script.getAttribute('src')),
        })`);
        throw new Error('Expected browser state not reached: ' + expression + ' ' + JSON.stringify(state));
    };
    try {
        const phpProbe = spawnSync(phpBinary, ['-v'], { cwd: workspace, env, encoding: 'utf8', windowsHide: true, timeout: 10000 });
        assert.equal(phpProbe.status, 0, 'The configured PHP executable must start');
        const migrationCode = 'require "vendor/autoload.php"; try { $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $code=Illuminate\\Support\\Facades\\Artisan::call("migrate", ["--force"=>true,"--no-interaction"=>true]); exit($code); } catch (Throwable $e) { echo get_class($e)." ".basename($e->getFile()).":".$e->getLine()." "; preg_match_all("/\\b(?:error|failed|migration|environment|database|sqlite|driver|extension|missing|cache|file|key|application|bootstrap|locked|production|testing|invalid|permission|parse|directory|table|connection|exists|found|unsafe|refused|runtime|storage|path|symlink|writable|supported|must|not|be|present|provide|specify|type|encryption)\\b/i", $e->getMessage(), $m); echo implode(" ", $m[0]); exit(1); }';
        const migrated = spawnSync(phpBinary, ['-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', migrationCode], { cwd: workspace, env, encoding: 'utf8', windowsHide: true, timeout: 60000 });
        assert.equal(migrated.status, 0, `Synthetic SQLite migrations must succeed (${migrated.stdout.trim()})`);
        const importCode = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); exit(Illuminate\\Support\\Facades\\Artisan::call("ppi:import-scorecard", ["file"=>"database/seeders/philippines-ppi-2018.json","--activate"=>true,"--no-interaction"=>true]));';
        const imported = spawnSync(phpBinary, ['-r', importCode], { cwd: workspace, env, encoding: 'utf8', windowsHide: true, timeout: 60000 });
        assert.equal(imported.status, 0, 'The verified scorecard must import');
        const router = path.join(temp, 'router.php');
        await writeFile(router, `<?php
if (PHP_SAPI === 'cli-server' && is_file(getcwd().'/public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) { return false; }
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->booted(function (): void {
    Illuminate\\Support\\Facades\\Vite::useHotFile(__DIR__.'/vite.hot');
    Illuminate\\Support\\Facades\\Mail::fake();
    Illuminate\\Support\\Facades\\Http::preventStrayRequests();
});
$app->handleRequest(Illuminate\\Http\\Request::capture());
`);
        server = spawn(phpBinary, ['-S', `127.0.0.1:${port}`, '-t', 'public', router], { cwd: workspace, env, windowsHide: true, stdio: 'ignore' });
        for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/up')).ok) break; } catch {} await delay(100); }
        assert.ok((await fetch(origin + '/up')).ok, 'Isolated server must start');
        const formResponse = await fetch(origin + '/application');
        const formHtml = await formResponse.text();
        assert.equal(formResponse.status, 200, 'PPI application page should render');
        assert.ok(formHtml.includes('action="/ppi-applications"'), 'Verified scorecard and fake delivery configuration should open test intake');
        const profile = path.join(temp, 'browser');
        browser = spawn(browserPath, ['--headless=new', '--disable-gpu', '--disable-extensions', '--disable-background-networking', '--no-first-run', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
        let debuggerPort;
        for (let i = 0; i < 150 && !debuggerPort; i++) { try { debuggerPort = Number((await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]); } catch {} await delay(100); }
        assert.ok(debuggerPort, 'Browser must start');
        const pages = await (await fetch(`http://127.0.0.1:${debuggerPort}/json/list`)).json();
        socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
        socket.addEventListener('message', event => { const message = JSON.parse(event.data); if (message.method === 'Fetch.requestPaused') { const allowed = new URL(message.params.request.url).origin === origin; command(allowed ? 'Fetch.continueRequest' : 'Fetch.failRequest', allowed ? { requestId: message.params.requestId } : { requestId: message.params.requestId, errorReason: 'BlockedByClient' }).catch(() => {}); return; } const item = pending.get(message.id); if (!item) return; clearTimeout(item.timer); pending.delete(message.id); message.error ? item.reject(new Error('Browser protocol error')) : item.resolve(message.result); });
        await command('Page.enable'); await command('Runtime.enable'); await command('Network.enable');
        await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
        async function submitApplication(address, passed) {
            await command('Page.navigate', { url: origin + '/application' });
            await until("!!document.querySelector('[data-ppi-application][action] > fieldset:enabled:not([hidden])')");
            await evaluate(`(() => {
            const form = document.querySelector('[data-ppi-application]');
            const details = { child_first_name: 'Synthetic', child_last_name: 'Child', child_date_of_birth: '2016-03-15',
                guardian_name: 'Synthetic Guardian', guardian_relationship: 'Parent', contact_number: '09171234567',
                email: 'synthetic@example.test', barangay: 'Sample Barangay', city_municipality: 'Sample City',
                complete_address: ${JSON.stringify(address)} };
            for (const [name, value] of Object.entries(details)) form.elements.namedItem(name).value = value;
            form.querySelector('[name=child_sex][value=female]').checked = true;
            form.querySelector('[data-ppi-step="0"] [data-ppi-next]').click();
            for (let index = 0; index < 10; index++) {
                const question = document.querySelector('[data-ppi-question]:not([hidden])');
                const select = question.querySelector('select');
                if (select) select.selectedIndex = 1;
                else {
                    const option = index === 1 ? 2 : ${passed} && index === 6 ? 2 : !${passed} && index === 8 ? 1 : 0;
                    question.querySelectorAll('input[type=radio]')[option].click();
                }
                form.querySelector('[data-ppi-step="1"] [data-ppi-next]').click();
            }
            form.querySelector('[data-skip-members]').click();
            form.elements.privacy_acknowledgment.checked = true;
            form.requestSubmit();
            })()`);
        }

        await submitApplication('28 Sample Street', true);
        await until("document.querySelector('[data-result-reference]')?.textContent.includes('PPI-')");
        assert.equal(await evaluate("document.querySelector('[data-result-heading]').textContent"), "Application submitted");
        assert.match(await evaluate("document.querySelector('[data-result-message]').textContent"), /wait for further notice/);

        await submitApplication('29 Sample Street', false);
        await until("document.querySelector('[data-result-heading]')?.textContent === 'Thank you for applying'");
        assert.match(await evaluate("document.querySelector('[data-result-message]').textContent"), /unable to move your application/);
        assert.equal(await evaluate("document.querySelector('[data-result-reference]').hidden"), true);
        const check = spawnSync(phpBinary, ['-r', '$p=new PDO("sqlite:".getenv("DB_DATABASE")); echo json_encode([$p->query("select ppi_score from ppi_household_applications order by id")->fetchAll(PDO::FETCH_COLUMN),$p->query("select count(*) from ppi_application_answers")->fetchColumn(),$p->query("select count(*) from ppi_household_members")->fetchColumn()]);'], { cwd: workspace, env, encoding: 'utf8', windowsHide: true });
        assert.equal(check.status, 0);
        assert.deepEqual(JSON.parse(check.stdout), [[28, 29], 20, 0]);

    } finally {
        if (socket?.readyState === WebSocket.OPEN) { await command('Browser.close').catch(() => {}); socket.close(); }
        if (browser && browser.exitCode === null) browser.kill();
        if (server && server.exitCode === null) server.kill();
        await delay(500);
        const resolved = await realpath(temp);
        assert.equal(path.dirname(resolved), tempRoot); assert.ok(path.basename(resolved).startsWith('rlcp-real-intake-'));
        await rm(resolved, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
});
