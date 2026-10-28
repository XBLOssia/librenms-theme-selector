#!/usr/bin/env node
// Browser regression against a localhost dev instance. Uses its real Bootstrap and
// Font Awesome assets, with synthetic markup and the working tree's skin CSS.
// Requires PHP, Playwright and Chrome. See dev/README.md.
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
assert(selectedMode === null || ['light', 'dark'].includes(selectedMode), 'Mode must be light or dark');
const baseFile = path.resolve(option('--base-css', path.join(root, 'base/base.css')));

// Generate mirrors with the same code the publisher uses, including the light
// ornament gate. Relative fonts/textures are served below from their source paths.
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

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    let passed = 0;
    try {
        const page = await browser.newPage({ viewport: { width: 1500, height: 900 } });
        await page.goto(new URL('/settings/poller', target).href, { waitUntil: 'networkidle' });
        assert(await page.evaluate(() => !!window.jQuery?.fn.tooltip), 'Bootstrap tooltip plugin must be loaded');
        console.log('Bootstrap ' + await page.evaluate(() => $.fn.tooltip.Constructor.VERSION));

        const prefix = '/__theme_selector_tooltip_test/';
        await page.route(target.origin + prefix + '**', async route => {
            const url = new URL(route.request().url());
            const relative = decodeURIComponent(url.pathname.slice(prefix.length));
            const mode = url.searchParams.get('mode');
            if (relative === 'base.css' && css.base[mode]) {
                return route.fulfill({ contentType: 'text/css', body: css.base[mode] });
            }
            const id = relative.replace(/\/skin\.css$/, '');
            if (css.skins[id]?.[mode]) {
                return route.fulfill({ contentType: 'text/css', body: css.skins[id][mode] });
            }
            const file = path.resolve(root, relative);
            assert(file.startsWith(root + path.sep), 'Asset must stay inside the repository');
            const types = { '.woff2': 'font/woff2', '.png': 'image/png' };
            if (!types[path.extname(file)] || !fs.existsSync(file)) return route.abort();
            return route.fulfill({ contentType: types[path.extname(file)], body: fs.readFileSync(file) });
        });

        await page.evaluate(() => {
            document.querySelectorAll('link[data-theme-selector]').forEach(el => el.remove());
            // The narrow .fa-lg box is the regression: Bootstrap places its
            // tooltip over the upper part of the glyph, then receives mouseleave.
            document.body.innerHTML = `<main style="padding:100px 24px">
                <p>Synthetic tooltip regression fixture</p>
                <div class="panel with-nav-tabs panel-default">
                    <div class="panel-heading">Settings</div><div class="panel-body">
                        <div class="panel panel-default"><div class="panel-heading">Ports Poller Module</div>
                            <div class="panel-body"><div class="form-group row">
                                <label class="col-sm-5">Selected Port Polling</label>
                                <div class="col-sm-5"><input type="checkbox" disabled></div>
                                <div><div id="tooltip-help" class="fa fa-fw fa-lg fa-question-circle" aria-label="Help"></div></div>
                            </div></div>
                        </div>
                        <div class="panel panel-default"><div class="panel-heading">Next section</div></div>
                    </div>
                </div></main>`;
            $('#tooltip-help').tooltip({
                title: '<p>Poll only selected ports for this synthetic device.</p>',
                html: true, container: 'body', trigger: 'hover click',
            }).on('hidden.bs.tooltip', () => window.tooltipHides++);
        });

        for (const id of skins) {
            for (const mode of selectedMode ? [selectedMode] : ['dark', 'light']) {
                await page.mouse.move(0, 0);
                await page.evaluate(async ({ id, mode, ornaments, prefix }) => {
                    document.querySelectorAll('[data-test-skin]').forEach(el => el.remove());
                    document.documentElement.classList.toggle('dark', mode === 'dark');
                    await Promise.all(['base.css', id + '/skin.css'].map(file => new Promise((resolve, reject) => {
                        const link = document.createElement('link');
                        link.rel = 'stylesheet';
                        link.href = prefix + file + '?mode=' + mode;
                        link.setAttribute('data-test-skin', '');
                        if (ornaments) link.setAttribute(mode === 'dark' ? 'data-ts-orn' : 'data-ts-orn-light', '');
                        link.onload = resolve;
                        link.onerror = reject;
                        document.head.append(link);
                    })));
                    await document.fonts.ready;
                }, { id, mode, ornaments: css.skins[id].ornaments, prefix });

                const help = page.locator('#tooltip-help');
                for (const offset of [-0.28, 0, 0.28]) {
                    const point = await help.evaluate((el, offset) => {
                        const box = el.getBoundingClientRect();
                        return { x: box.x + box.width / 2, y: box.y + box.height / 2 + parseFloat(getComputedStyle(el).fontSize) * offset };
                    }, offset);
                    await page.evaluate(() => { window.tooltipHides = 0; });
                    await page.mouse.move(point.x, point.y);
                    await page.waitForTimeout(600);
                    const result = await page.evaluate(() => {
                        const tip = document.querySelector('.tooltip.in');
                        if (!tip) return { visible: false, hides: window.tooltipHides };
                        const box = tip.getBoundingClientRect();
                        const top = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);
                        return { visible: getComputedStyle(tip).opacity !== '0' && tip.contains(top), hides: window.tooltipHides };
                    });
                    assert(result.visible && result.hides === 0, `${id} ${mode}: hover at ${offset} flickered or was covered: ${JSON.stringify(result)}`);
                }
                await page.mouse.move(0, 0);
                await page.waitForTimeout(200);
                assert.equal(await page.locator('.tooltip.in').count(), 0, 'Tooltip hides on mouseleave');
                await help.click();
                await page.mouse.move(0, 0);
                await page.waitForTimeout(200);
                assert.equal(await page.locator('.tooltip.in').count(), 1, 'Click keeps help open after mouseleave');
                await help.click();
                await page.mouse.move(0, 0);
                await page.waitForTimeout(200);
                assert.equal(await page.locator('.tooltip.in').count(), 0, 'Second click closes help');
                console.log(`PASS ${id} ${mode}: stable hover, above panels, click toggle, mouseleave`);
                passed++;
            }
        }
        console.log(`${passed} skin/mode combinations passed`);
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
