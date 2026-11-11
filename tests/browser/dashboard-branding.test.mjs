import assert from "node:assert/strict";
import test from "node:test";
import { delay, staffBrowser } from "./support/staff-browser.mjs";

const binary = process.env.STAFF_UI_BROWSER;
const fixtures = process.env.STAFF_UI_FIXTURES;
const geometry = `(() => {
    const selectors = '[data-flux-sidebar], [data-flux-header], .rlc-brand, .rlc-brand-mark, .rlc-dashboard, .rlc-toolbar, .rlc-welcome, .rlc-stats, .rlc-stat, .rlc-panels, .rlc-caseload-panels, .rlc-panel, .rlc-panel-heading, .rlc-chart, .rlc-bar-slot, .rlc-bar, .rlc-caseload-preview li, .rlc-button, .rlc-search, .rlc-date';
    return [...document.querySelectorAll(selectors)].map(el => {
        const box = el.getBoundingClientRect(), css = getComputedStyle(el);
        return { tag: el.tagName, rect: [box.x, box.y, box.width, box.height].map(n => Math.round(n * 10) / 10), padding: css.padding, gap: css.gap, columns: css.gridTemplateColumns, order: css.order };
    });
})()`;
const content = `(() => {
    const main = document.querySelector('.rlc-dashboard');
    return { text: main.textContent.replace(/\\s+/g, ' ').trim(),
        links: [...main.querySelectorAll('a[href]')].map(el => [el.textContent.trim(), el.getAttribute('href')]),
        navigation: [...document.querySelectorAll('[data-flux-sidebar-item]')].map(el => [el.textContent.trim(), el.getAttribute('href')]),
        hooks: [...main.querySelectorAll('[id], [x-data], [data-endpoint], [data-caseload-count]')].map(el => [el.id, el.getAttribute('x-data'), el.getAttribute('data-endpoint'), el.getAttribute('data-caseload-count')]),
        artwork: document.querySelector('.rlc-landscape svg')?.innerHTML || null };
})()`;
const contrast = `(() => {
    const rgb = value => (value.match(/[\\d.]+/g) || []).map(Number);
    const luminance = color => color.slice(0,3).map(n => { n /= 255; return n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4; }).reduce((sum,n,i) => sum + n * [.2126,.7152,.0722][i], 0);
    const background = el => { for (; el; el=el.parentElement) { const color=rgb(getComputedStyle(el).backgroundColor); if (color.length===3 || color[3]===1) return color; } return [255,255,255]; };
    const selectors = '.rlcp-dashboard-brand h1, .rlcp-dashboard-brand h2, .rlcp-dashboard-brand p, .rlcp-dashboard-brand .rlc-button, .rlcp-dashboard-brand .rlc-text-link, .rlcp-dashboard-brand .rlc-tag, .rlcp-dashboard-brand .rlc-calendar-tile, .rlcp-dashboard-brand .rlc-calendar-tile strong, .rlc-brand small, [data-flux-sidebar-item], [data-flux-sidebar-group] > div:first-child';
    return [...document.querySelectorAll(selectors)].filter(el => el.getClientRects().length && el.textContent.trim()).map(el => {
        const css = getComputedStyle(el), front = luminance(rgb(css.color)), back = luminance(background(el));
        return { text: el.textContent.trim().slice(0,45), ratio: (Math.max(front,back)+.05)/(Math.min(front,back)+.05), required: parseFloat(css.fontSize)>=24 || (parseFloat(css.fontSize)>=18.66 && parseFloat(css.fontWeight)>=700) ? 3 : 4.5 };
    });
})()`;

test(
    "staff branding preserves the existing dashboards using isolated browser fixtures",
    { skip: !binary || !fixtures, timeout: 240000 },
    async (t) => {
        const ui = await staffBrowser(binary, fixtures);
        const { evaluate, until, key } = ui;
        const comparisons = [];
        async function controls(width, role) {
            await evaluate(
                "(() => { const input = document.getElementById('dashboard-beneficiary-search'); input.focus(); input.value = 'Synthetic'; input.dispatchEvent(new Event('input', {bubbles:true})); })()",
            );
            await until(
                "document.querySelectorAll('.rlc-search-result').length === 1 && document.getElementById('dashboard-beneficiary-search').getAttribute('aria-expanded') === 'true'",
            );
            await key("ArrowDown");
            assert.equal(
                await evaluate(
                    "document.getElementById('dashboard-beneficiary-search').getAttribute('aria-activedescendant')",
                ),
                "rlc-search-option-0",
            );
            await key("Escape");
            assert.equal(
                await evaluate(
                    "document.getElementById('dashboard-beneficiary-search').getAttribute('aria-expanded')",
                ),
                "false",
            );
            assert.equal(
                await evaluate("document.activeElement.id"),
                "dashboard-beneficiary-search",
            );
            await evaluate("document.querySelector('.rlc-notification-button').focus()");
            await key("Enter");
            await until(
                "document.querySelector('#rlc-notification-panel').textContent.includes('Synthetic notification')",
            );
            assert.equal(
                await evaluate(
                    "document.querySelector('.rlc-notification-button').getAttribute('aria-expanded')",
                ),
                "true",
            );
            await evaluate(
                "document.querySelector('[aria-label=\"Refresh notifications\"]').click()",
            );
            await key("Escape");
            await until(
                "document.querySelector('.rlc-notification-button').getAttribute('aria-expanded') === 'false'",
            );
            const trigger =
                width >= 1024
                    ? '[data-test="sidebar-menu-button"]'
                    : "[data-flux-header] [data-flux-profile]";
            await evaluate(`document.querySelector(${JSON.stringify(trigger)}).focus()`);
            await key("Enter");
            await until("!!document.querySelector('[data-flux-menu]:popover-open')");
            await key("Escape");
            await until("!document.querySelector('[data-flux-menu]:popover-open')");
            assert.equal(
                await evaluate(`document.activeElement.matches(${JSON.stringify(trigger)})`),
                true,
            );
            if (width < 1024) {
                await evaluate(
                    "document.querySelector('[data-flux-header] ui-sidebar-toggle button').focus()",
                );
                await key("Enter");
                await until(
                    "!document.querySelector('[data-flux-sidebar]').hasAttribute('data-flux-sidebar-collapsed-mobile')",
                );
                assert.equal(
                    await evaluate(
                        "document.querySelector('[data-flux-sidebar]').getBoundingClientRect().left >= 0",
                    ),
                    true,
                );
                await key("Escape");
                await until(
                    "document.querySelector('[data-flux-sidebar]').hasAttribute('data-flux-sidebar-collapsed-mobile')",
                );
            }
            if (role === "caseworker") {
                const before = ui.requests.filter((path) => path === "/dashboard/caseload").length;
                await evaluate(
                    "document.querySelector('[data-caseload-dashboard] button').click()",
                );
                await until(
                    "document.querySelector('[data-caseload-dashboard] button').getAttribute('aria-busy') === 'false'",
                );
                assert.ok(
                    ui.requests.filter((path) => path === "/dashboard/caseload").length > before,
                );
            }
        }
        try {
            for (const role of ["project_director", "caseworker"]) {
                for (const theme of ["light", "dark"]) {
                    for (const width of [1440, 768, 390]) {
                        await t.test(
                            `${role}, ${theme}, ${width}px: identical layout and controls`,
                            async () => {
                                await ui.viewport(width, width === 390 ? 844 : 1000);
                                await ui.navigate(role, "baseline");
                                await ui.theme(theme);
                                const before = await evaluate(geometry),
                                    beforeContent = await evaluate(content);
                                if (width !== 768)
                                    await ui.screenshot(`before-${role}-${theme}-${width}`);
                                await ui.navigate(role);
                                await ui.theme(theme);
                                const after = await evaluate(geometry);
                                assert.deepEqual(
                                    after,
                                    before,
                                    "Every measured position, size, gap, padding, grid and order must stay unchanged",
                                );
                                assert.deepEqual(
                                    await evaluate(content),
                                    beforeContent,
                                    "Labels, data, links, bindings and geometric artwork must stay unchanged",
                                );
                                assert.equal(
                                    await evaluate(
                                        "document.documentElement.scrollWidth > innerWidth",
                                    ),
                                    false,
                                );
                                assert.equal(
                                    await evaluate(
                                        "document.querySelector('.rlc-brand img').complete && document.querySelector('.rlc-brand img').naturalWidth === 300",
                                    ),
                                    true,
                                );
                                assert.equal(
                                    await evaluate(
                                        "(() => { const b = document.querySelector('.rlc-brand img').getBoundingClientRect(); return b.width === b.height; })()",
                                    ),
                                    true,
                                );
                                for (const item of await evaluate(contrast))
                                    assert.ok(
                                        item.ratio >= item.required,
                                        `${item.text}: ${item.ratio.toFixed(2)} contrast`,
                                    );
                                if (width !== 768) {
                                    await ui.screenshot(`${role}-${theme}-${width}`);
                                    await controls(width, role);
                                }
                                comparisons.push({
                                    role,
                                    theme,
                                    width,
                                    measuredElements: after.length,
                                    maximumGeometryChange: 0,
                                    contentUnchanged: true,
                                    horizontalOverflow: false,
                                });
                            },
                        );
                    }
                }
            }
            await t.test(
                "existing appearance settings still switch light, dark and system",
                async () => {
                    for (const width of [1440, 390]) {
                        await ui.viewport(width, 844);
                        await ui.navigate("project_director", "current", "/settings/appearance");
                        for (const value of ["light", "dark", "light"]) {
                            await evaluate(
                                `document.querySelector('ui-radio[value="${value}"]').click()`,
                            );
                            await until(
                                `document.documentElement.classList.contains('dark') === ${value === "dark"}`,
                            );
                            assert.equal(
                                await evaluate("localStorage.getItem('flux.appearance')"),
                                value,
                            );
                        }
                        await ui.command("Emulation.setEmulatedMedia", {
                            features: [{ name: "prefers-color-scheme", value: "dark" }],
                        });
                        await evaluate("document.querySelector('ui-radio[value=system]').click()");
                        await until("document.documentElement.classList.contains('dark')");
                        assert.equal(
                            await evaluate("localStorage.getItem('flux.appearance')"),
                            null,
                        );
                    }
                },
            );
            await t.test(
                "staff module content inherits branding while retaining geometry",
                async () => {
                    const probe = `(() => { const el=document.querySelector('[data-style-probe]'); return [el, el.querySelector('.rlc-panel'), el.querySelector('.rlc-button')].map(node => {const s=getComputedStyle(node),b=node.getBoundingClientRect(); return { color:s.color,background:s.backgroundColor,image:s.backgroundImage,border:s.borderColor,rect:[b.x,b.y,b.width,b.height] };}); })()`;
                    for (const theme of ["light", "dark"]) {
                        await ui.viewport(1440);
                        await ui.navigate("project_director", "baseline", "/scope-probe");
                        await ui.theme(theme);
                        const before = await evaluate(probe);
                        await ui.navigate("project_director", "current", "/scope-probe");
                        await ui.theme(theme);
                        const after = await evaluate(probe);
                        assert.deepEqual(after.map(item => item.rect), before.map(item => item.rect));
                        assert.notDeepEqual(after.map(item => item.background), before.map(item => item.background));
                        assert.equal(after[2].color, "rgb(255, 255, 255)");
                        assert.equal(after[2].background, "rgb(185, 28, 28)");
                    }
                },
            );
            await t.test(
                "reduced motion and narrow mobile layout retain existing behavior",
                async () => {
                    await ui.command("Emulation.setEmulatedMedia", {
                        features: [{ name: "prefers-reduced-motion", value: "reduce" }],
                    });
                    for (const role of ["project_director", "caseworker"]) {
                        await ui.viewport(320, 640);
                        await ui.navigate(role);
                        assert.equal(
                            await evaluate("document.documentElement.scrollWidth > innerWidth"),
                            false,
                        );
                        assert.equal(
                            await evaluate(
                                "[...document.querySelectorAll('.rlc-enter,.rlc-bar,.rlc-wave')].every(el=>getComputedStyle(el).animationName==='none')",
                            ),
                            true,
                        );
                    }
                },
            );
            assert.deepEqual(ui.errors, []);
            await ui.report({ comparisons });
        } finally {
            await ui.close();
        }
    },
);
