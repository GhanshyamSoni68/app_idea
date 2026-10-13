// Step, the mascot (docs/15): the learner controls it, the OS reduced-motion setting is honoured, it adds almost no
// weight, and the page stays accessible with Step on, still, or off.
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { chromium } from 'playwright-core';

const require = createRequire(import.meta.url);
const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const CHROMIUM = process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium';
const step = (m) => console.log(`✔ ${m}`);
const assert = (c, m) => { if (!c) throw new Error(`ASSERTION FAILED: ${m}`); };
execFileSync('php', ['artisan', 'cache:clear']);

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const violations = [];
async function audit(page, name) {
    await page.waitForTimeout(500);
    for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') }).catch(() => undefined);
        const res = await page.evaluate(async () => await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'] } }));
        for (const v of res.violations.filter((x) => ['serious', 'critical'].includes(x.impact))) violations.push(`${name} [${scheme}] ${v.id}: ${v.help} → ${v.nodes[0].target.join(' ')}`);
    }
    await page.emulateMedia({ colorScheme: 'light' });
}
const count = (page, sel) => page.locator(sel).count();
const animationOf = (page, sel) => page.locator(sel).first().evaluate((e) => getComputedStyle(e).animationName);

try {
    // ---- Step on: present, animated, light on the wire ---------------------------------------------------
    const ctx = await browser.newContext({ viewport: { width: 1000, height: 800 }, locale: 'en-GB' });
    const page = await ctx.newPage();
    const imageBytes = [];
    page.on('response', async (r) => {
        if (!/step.*\.webp/.test(r.url())) return;
        try { imageBytes.push((await r.body()).length); } catch { /* the page closed before the body was read */ }
    });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(BASE);
    await page.getByRole('heading', { level: 1 }).waitFor();
    await page.waitForTimeout(800);
    assert(await count(page, '.hero .mascot img') === 1, 'Step greets visitors on the landing page');
    assert(await count(page, '.brand .avatar') === 1, 'Step is part of the brand mark');
    assert((await animationOf(page, '.hero .mascot')) !== 'none', 'Step moves by default');
    const alt = await page.locator('.hero .mascot img').getAttribute('alt');
    assert(alt === '', 'Step is decorative: it never carries information only an image can show');
    const total = imageBytes.reduce((a, b) => a + b, 0);
    assert(total > 0 && total < 110_000, `mascot images stay light: ${total} bytes`);
    step(`Step greets, moves, is decorative, and weighs ${(total / 1024).toFixed(0)} KB on the landing page`);
    await audit(page, 'landing (Step on)');

    // ---- Quiet: still image, no animation, persisted -----------------------------------------------------
    await page.goto(`${BASE}/settings`);
    await page.getByLabel('Step, perfectly still').check();
    await page.waitForTimeout(300);
    assert(await animationOf(page, '.say .mascot') === 'none', 'quiet mode stops all motion');
    await page.reload();
    assert(await page.getByLabel('Step, perfectly still').isChecked(), 'the choice persists');
    await page.goto(BASE);
    assert(await count(page, '.mascot.still') >= 1 && await animationOf(page, '.hero .mascot') === 'none', 'still on every screen, not only Settings');
    step('quiet: Step stays, perfectly still, and the choice persists');

    // ---- Off: every figure goes, the words stay -----------------------------------------------------------
    await page.goto(`${BASE}/settings`);
    await page.getByLabel('Hide Step').check();
    await page.waitForTimeout(300);
    assert(await count(page, '.mascot') === 0 && await count(page, '.avatar') === 0, 'no figure anywhere when hidden');
    assert(await count(page, '.bubble') >= 1, 'what Step says remains as plain text, so no information depends on the figure');
    assert((await page.locator('.brand').innerText()).includes('DevStep'), 'the wordmark remains');
    await audit(page, 'settings (Step off)');
    await page.goto(BASE);
    await page.getByRole('heading', { level: 1 }).waitFor();
    assert(await count(page, '.mascot') === 0 && await count(page, '.avatar') === 0, 'hidden on the landing page too');
    await audit(page, 'landing (Step off)');
    step('off: no figure on any screen; the words remain; wordmark intact');

    // ---- Back on, and the OS reduced-motion setting wins over "on" ---------------------------------------
    await page.goto(`${BASE}/settings`);
    await page.getByLabel('Step with a little movement').check();
    await page.close();
    const calm = await browser.newContext({ viewport: { width: 1000, height: 800 }, reducedMotion: 'reduce', locale: 'en-GB' });
    const calmPage = await calm.newPage();
    await calmPage.goto(`${BASE}/sample/done`);
    await calmPage.getByRole('heading', { name: 'Sample complete' }).waitFor();
    await calmPage.waitForTimeout(500);
    assert(await count(calmPage, '.mascot') >= 1, 'Step is present under reduced motion');
    assert(await count(calmPage, '.mascot.still') >= 1 && await animationOf(calmPage, '.celebrate .mascot') === 'none', 'reduced motion: still, even though Step is set to "on"');
    assert(await count(calmPage, '.confetti') === 0, 'no confetti under reduced motion');
    await audit(calmPage, 'sample complete (reduced motion)');
    step('reduced motion: Step is still and there is no confetti, even when set to "on"');

    assert(errors.length === 0, `no uncaught browser errors: ${errors.join('; ')}`);
    if (violations.length) {
        console.error(`\nAccessibility violations:\n - ${[...new Set(violations)].join('\n - ')}`);
        process.exitCode = 1;
    } else step('axe-core: no serious or critical violations with Step on, still or off, in either theme');
} finally {
    await browser.close();
}
