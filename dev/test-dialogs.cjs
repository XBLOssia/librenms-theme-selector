#!/usr/bin/env node
// Test the real Alpine dependency dialog and a nested-panel fixture against the
// working tree's CSS. Needs the 26.10.2+ local dev instance, PHP, Playwright and Chrome.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const args = process.argv.slice(2);
function option(name, fallback) {
    const index = args.indexOf(name);
    return index === -1 ? fallback : args[index + 1];
}
const target = new URL(option('--url', 'http://127.0.0.1:8000'));
assert(['127.0.0.1', 'localhost', '[::1]'].includes(target.hostname), 'Use a localhost dev instance');
const selectedSkin = option('--skin', null);
const selectedMode = option('--mode', null);
const selectedViewport = option('--viewport', null);
assert(selectedMode === null || ['light', 'dark'].includes(selectedMode), 'Mode must be light or dark');
assert(selectedViewport === null || ['desktop', 'narrow'].includes(selectedViewport), 'Viewport must be desktop or narrow');
const baseFile = path.resolve(option('--base-css', path.join(root, 'base/base.css')));

// Use the publisher's actual mirror generation, including the mode-specific
// ornament gate. Examples stand in for uploaded token files; no upload is made.
const css = JSON.parse(execFileSync('php', ['-r', String.raw`
require $argv[1] . '/src/Modes.php';
use Xblossia\ThemeSelector\Modes;
$base = file_get_contents($argv[2]);
$out = ['base' => ['dark' => $base, 'light' => Modes::lightBase($base) . "\n" . file_get_contents($argv[1] . '/base/light.css')], 'skins' => []];
foreach (['skins', 'examples'] as $kind) {
    foreach (glob($argv[1] . '/' . $kind . '/*/skin.css') as $file) {
        $id = basename(dirname($file));
        $text = file_get_contents($file);
        $native = Modes::nativeOf($text);
        $features = json_decode(@file_get_contents(dirname($file) . '/features.json') ?: '{}', true);
        $out['skins'][$kind . '/' . $id] = [
            'ornaments' => $kind === 'examples' || ($features['ornaments'] ?? false),
            $native => $text, Modes::other($native) => Modes::mirror($text),
        ];
    }
}
echo json_encode($out);
`, root, baseFile], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 }));
const skins = Object.keys(css.skins).filter(id => selectedSkin === null || id === selectedSkin || id.endsWith('/' + selectedSkin));
assert(skins.length > 0, 'No matching skins');
const viewports = {
    desktop: { width: 1366, height: 768 },
    narrow: { width: 390, height: 844 },
};

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    let passed = 0;
    try {
        const page = await browser.newPage();
        const prefix = '/__theme_selector_dialog_test/';
        await page.route(target.origin + prefix + '**', async route => {
            const url = new URL(route.request().url());
            const relative = decodeURIComponent(url.pathname.slice(prefix.length));
            const mode = url.searchParams.get('mode');
            if (relative === 'base.css' && css.base[mode]) return route.fulfill({ contentType: 'text/css', body: css.base[mode] });
            const id = relative.replace(/\/skin\.css$/, '');
            if (css.skins[id]?.[mode]) return route.fulfill({ contentType: 'text/css', body: css.skins[id][mode] });
            const file = path.resolve(root, relative);
            assert(file.startsWith(root + path.sep), 'Asset must stay inside the repository');
            const types = { '.woff2': 'font/woff2', '.png': 'image/png' };
            if (!types[path.extname(file)] || !fs.existsSync(file)) return route.abort();
            return route.fulfill({ contentType: types[path.extname(file)], body: fs.readFileSync(file) });
        });

        for (const viewport of selectedViewport ? [selectedViewport] : Object.keys(viewports)) {
            await page.setViewportSize(viewports[viewport]);
            // Initialize the dev session before loading the page under test.
            await page.goto(new URL('/settings/poller', target).href, { waitUntil: 'networkidle' });
            await page.goto(new URL('/device-dependencies', target).href, { waitUntil: 'networkidle' });
            const trigger = page.locator('button[x-on\\:click="openManage()"]');
            const modal = page.locator('[x-show="manageOpen"]');
            assert(await trigger.count() && await modal.count(), 'This LibreNMS build does not have the Alpine dependency dialog; use 26.10.2 or newer');
            // A local empty instance has no rows. Put a synthetic row into the
            // rendered table so the panel overlaps the same part of the dialog.
            await page.evaluate(() => {
                const body = document.querySelector('#hostdeps tbody');
                if (body) body.insertAdjacentHTML('beforeend', '<tr><td>1</td><td>synthetic device</td><td>None</td><td></td></tr>');
            });

            for (const id of skins) {
                for (const mode of selectedMode ? [selectedMode] : ['dark', 'light']) {
                    await page.mouse.move(0, 0);
                    await page.evaluate(async ({ id, mode, ornaments, prefix }) => {
                        document.querySelectorAll('link[data-theme-selector], link[data-dialog-test]').forEach(el => el.remove());
                        document.documentElement.classList.toggle('dark', mode === 'dark');
                        for (const file of ['base.css', id + '/skin.css']) {
                            await new Promise((resolve, reject) => {
                                const link = document.createElement('link');
                                link.rel = 'stylesheet';
                                link.href = prefix + file + '?mode=' + mode;
                                link.setAttribute('data-dialog-test', '');
                                if (ornaments) link.setAttribute(mode === 'dark' ? 'data-ts-orn' : 'data-ts-orn-light', '');
                                link.onload = resolve;
                                link.onerror = reject;
                                document.head.append(link);
                            });
                        }
                        await document.fonts.ready;
                    }, { id, mode, ornaments: css.skins[id].ornaments, prefix });

                    const nav = page.locator('nav.navbar-sticky-top');
                    const navBefore = await nav.evaluate(el => getComputedStyle(el).zIndex);
                    const stockNav = await nav.evaluate(el => {
                        const links = [...document.querySelectorAll('link[data-dialog-test]')];
                        links.forEach(link => { link.disabled = true; });
                        const z = getComputedStyle(el).zIndex;
                        links.forEach(link => { link.disabled = false; });
                        return z;
                    });
                    assert.equal(navBefore, stockNav, 'Hidden dialogs do not change the navbar stacking');
                    const unrelatedZ = await page.evaluate(() => {
                        const el = document.createElement('div');
                        el.setAttribute('x-show', 'unused');
                        el.className = 'tw:fixed tw:inset-0 tw:z-50 tw:bg-black/50';
                        document.body.append(el);
                        const z = getComputedStyle(el).zIndex;
                        el.remove();
                        return z;
                    });
                    assert.equal(unrelatedZ, '50', 'An unrelated full-screen layer keeps its stacking');
                    await trigger.click();
                    await modal.waitFor({ state: 'visible' });
                    await page.waitForTimeout(150);
                    const front = await modal.evaluate(el => {
                        const close = el.querySelector('button[aria-label="Close"]');
                        const rect = close.getBoundingClientRect();
                        const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
                        const nav = document.querySelector('nav.navbar').getBoundingClientRect();
                        const navHit = document.elementFromPoint(nav.x + Math.min(100, nav.width / 2), nav.y + nav.height / 2);
                        return { close: !!hit && el.contains(hit), navbar: !!navHit && el.contains(navHit), z: getComputedStyle(el).zIndex };
                    });
                    assert(front.close && front.navbar, `${id} ${mode} ${viewport}: dialog covered or navbar above backdrop: ${JSON.stringify(front)}`);
                    // Bootstrap tooltips are appended to body, outside the modal.
                    // They must still paint above it while the navbar is lowered.
                    await modal.evaluate(el => {
                        const tip = document.createElement('button');
                        tip.id = 'ts-dialog-tooltip';
                        tip.type = 'button';
                        tip.textContent = 'Synthetic help';
                        el.firstElementChild.append(tip);
                        $(tip).tooltip({ title: 'Synthetic tooltip', container: 'body', trigger: 'hover' });
                    });
                    await page.locator('#ts-dialog-tooltip').hover();
                    const tip = page.locator('body > .tooltip.in').first();
                    await tip.waitFor({ state: 'visible' });
                    const tipFront = await tip.evaluate(el => {
                        const r = el.getBoundingClientRect();
                        const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
                        return !!hit && el.contains(hit);
                    });
                    assert(tipFront, 'Body-mounted tooltip stays above the dialog');
                    await page.evaluate(() => { $('#ts-dialog-tooltip').tooltip('destroy').remove(); });
                    await modal.locator('button[aria-label="Close"]').click({ timeout: 3000 });
                    await modal.waitFor({ state: 'hidden', timeout: 3000 });
                    assert.equal(await nav.evaluate(el => getComputedStyle(el).zIndex), navBefore, 'Navbar stacking returns after close');

                    await trigger.click();
                    await modal.waitFor({ state: 'visible' });
                    const selection = modal.locator('.select2-selection').first();
                    await selection.click();
                    const dropdown = page.locator('.select2-dropdown:visible').first();
                    await dropdown.waitFor({ state: 'visible' });
                    assert(await modal.isVisible(), 'Opening the Select2 menu keeps the dialog open');
                    await page.evaluate(() => $('.select2-hidden-accessible').select2('close'));
                    await modal.locator('button[aria-label="Close"]').focus();
                    await page.keyboard.press('Escape');
                    await modal.waitFor({ state: 'hidden', timeout: 3000 });

                    // A dialog may also be nested inside a clipped panel. The
                    // hidden dialog must not disable the panel's ornaments.
                    await page.evaluate(() => {
                        const fixture = document.createElement('div');
                        fixture.id = 'ts-nested-dialog-fixture';
                        fixture.innerHTML = `<div class="panel panel-default" x-data="{ shown: false }"><div class="panel-heading">
                            <button type="button" id="ts-nested-trigger" x-on:click="shown = true">Open synthetic dialog</button>
                            <div x-show="shown" x-cloak class="tw:fixed tw:inset-0 tw:z-50 tw:flex tw:items-center tw:justify-center tw:bg-black/50">
                                <div x-on:click.outside="shown = false" class="tw:bg-white tw:p-5">
                                    <button type="button" id="ts-nested-close" x-on:click="shown = false">Close synthetic dialog</button>
                                </div>
                            </div>
                        </div></div>`;
                        document.body.append(fixture);
                    });
                    const nestedPanel = page.locator('#ts-nested-dialog-fixture .panel');
                    const nestedHeading = page.locator('#ts-nested-dialog-fixture .panel-heading');
                    const nestedTrigger = page.locator('#ts-nested-trigger');
                    const nestedModal = page.locator('#ts-nested-dialog-fixture [x-show="shown"]');
                    await nestedTrigger.waitFor({ state: 'visible' });
                    await page.waitForFunction(() => !document.querySelector('#ts-nested-dialog-fixture [x-show]').hasAttribute('x-cloak'));
                    await nestedTrigger.hover();
                    if (css.skins[id].ornaments) {
                        assert.equal(await nestedPanel.evaluate(el => getComputedStyle(el).isolation), 'isolate', 'Closed nested dialog keeps panel isolation');
                        assert.equal(await nestedHeading.evaluate(el => getComputedStyle(el).isolation), 'isolate', 'Closed nested dialog keeps heading isolation');
                    }
                    await nestedTrigger.click();
                    await nestedModal.waitFor({ state: 'visible' });
                    if (css.skins[id].ornaments) {
                        const state = await nestedPanel.evaluate(el => ({ isolation: getComputedStyle(el).isolation, clip: getComputedStyle(el).clipPath, z: getComputedStyle(el).zIndex }));
                        assert.deepEqual(state, { isolation: 'auto', clip: 'none', z: 'auto' }, 'Shown nested dialog releases the panel');
                        assert.equal(await nestedHeading.evaluate(el => getComputedStyle(el).isolation), 'auto', 'Shown nested dialog releases its heading');
                    }
                    const nestedFront = await nestedModal.evaluate(el => {
                        const button = el.querySelector('#ts-nested-close').getBoundingClientRect();
                        const hit = document.elementFromPoint(button.x + button.width / 2, button.y + button.height / 2);
                        const nav = document.querySelector('nav.navbar').getBoundingClientRect();
                        const navHit = document.elementFromPoint(nav.x + Math.min(100, nav.width / 2), nav.y + nav.height / 2);
                        return !!hit && el.contains(hit) && !!navHit && el.contains(navHit);
                    });
                    assert(nestedFront, 'Nested dialog stays above the navbar and panels');
                    await page.locator('#ts-nested-close').click();
                    await nestedModal.waitFor({ state: 'hidden', timeout: 3000 });
                    await nestedTrigger.hover();
                    if (css.skins[id].ornaments) {
                        assert.equal(await nestedPanel.evaluate(el => getComputedStyle(el).isolation), 'isolate', 'Panel isolation returns when the dialog closes');
                        assert.equal(await nestedHeading.evaluate(el => getComputedStyle(el).isolation), 'isolate', 'Heading isolation returns when the dialog closes');
                    }
                    await page.locator('#ts-nested-dialog-fixture').evaluate(el => el.remove());

                    // Existing Bootstrap modals use a separate panel-release
                    // rule and body backdrop. The Alpine fix must not break it.
                    await page.evaluate(() => {
                        const fixture = document.createElement('div');
                        fixture.id = 'ts-bootstrap-dialog-fixture';
                        fixture.innerHTML = `<div class="panel panel-default"><button type="button" id="ts-bootstrap-trigger">Open Bootstrap dialog</button>
                            <div id="ts-bootstrap-modal" class="modal fade" tabindex="-1" role="dialog">
                                <div class="modal-dialog"><div class="modal-content"><div class="modal-body">
                                    <button type="button" id="ts-bootstrap-close" data-dismiss="modal">Close Bootstrap dialog</button>
                                </div></div></div>
                            </div></div>`;
                        document.body.append(fixture);
                        $('#ts-bootstrap-trigger').on('click', () => $('#ts-bootstrap-modal').modal('show'));
                    });
                    const bootstrapPanel = page.locator('#ts-bootstrap-dialog-fixture .panel');
                    const bootstrapModal = page.locator('#ts-bootstrap-modal');
                    await page.locator('#ts-bootstrap-trigger').click();
                    await bootstrapModal.waitFor({ state: 'visible' });
                    if (css.skins[id].ornaments) assert.equal(await bootstrapPanel.evaluate(el => getComputedStyle(el).isolation), 'auto', 'Bootstrap modal still releases its panel');
                    const bootstrapNavCovered = await page.evaluate(() => {
                        const nav = document.querySelector('nav.navbar-sticky-top');
                        const r = nav.getBoundingClientRect();
                        const hit = document.elementFromPoint(r.x + Math.min(100, r.width / 2), r.y + r.height / 2);
                        return !!hit && !nav.contains(hit);
                    });
                    assert(bootstrapNavCovered, 'Bootstrap modal stays above the navbar');
                    await page.locator('#ts-bootstrap-close').click();
                    await bootstrapModal.waitFor({ state: 'hidden' });
                    assert.equal(await nav.evaluate(el => getComputedStyle(el).zIndex), navBefore, 'Navbar stacking returns after Bootstrap close');
                    await page.locator('#ts-bootstrap-dialog-fixture').evaluate(el => el.remove());
                    console.log(`PASS ${id} ${mode} ${viewport}: dialog, tooltip, Select2, Escape, nested panel, Bootstrap`);
                    passed++;
                }
            }
        }
        console.log(`${passed} skin/mode/viewport combinations passed`);
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
