// App phase (docs/14) and accessibility audit. Drives a real Chromium against a running server built with
// `npm run build` (the service worker only registers in production builds). See e2e/README.md.
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { chromium } from 'playwright-core';

const require = createRequire(import.meta.url);
const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const CHROMIUM = process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium';
const OUT = process.env.E2E_OUT ?? 'e2e/out';
mkdirSync(OUT, { recursive: true });
const php = (...a) => execFileSync('php', ['artisan', ...a], { encoding: 'utf8' }).trim();
const step = (m) => console.log(`✔ ${m}`);
const assert = (c, m) => { if (!c) throw new Error(`ASSERTION FAILED: ${m}`); };

php('cache:clear');
const email = `app+${Date.now()}@example.com`;
const password = 'a-long-enough-passphrase';
const invite = php('invite:create', email).split('\n')[0];

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-GB', timezoneId: 'Europe/London', serviceWorkers: 'allow' };
const violations = [];

async function audit(page, name) {
    for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') }).catch(() => undefined);
        const res = await page.evaluate(async () => await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] } }));
        for (const v of res.violations.filter((x) => ['serious', 'critical'].includes(x.impact))) {
            violations.push(`${name} [${scheme}] ${v.id} (${v.impact}): ${v.help} → ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
        }
    }
    await page.emulateMedia({ colorScheme: 'light' });
}

try {
    const ctx = await browser.newContext(phone);
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    // ---- App phase A: installable web app -------------------------------------------------------------
    const manifest = await (await ctx.request.get(`${BASE}/manifest.webmanifest`)).json();
    assert(manifest.display === 'standalone' && manifest.start_url && manifest.name === 'DevStep', 'manifest basics');
    assert(manifest.icons.some((i) => i.sizes === '192x192') && manifest.icons.some((i) => i.sizes === '512x512'), '192 and 512 icons');
    assert(manifest.icons.some((i) => i.purpose === 'maskable'), 'a maskable icon');
    for (const i of manifest.icons) assert((await ctx.request.get(`${BASE}${i.src}`)).ok(), `icon ${i.src} is served`);
    step('manifest is valid: standalone, 192/512 and maskable icons all served');

    await page.goto(BASE);
    await page.evaluate(() => navigator.serviceWorker.ready);
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null || true);
    await page.reload();
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null);
    assert(await page.locator('link[rel=manifest]').count() === 1, 'page links the manifest');
    assert(await page.locator('meta[name=theme-color]').count() === 1, 'theme-color is set');
    step('service worker active and controlling the page');

    // ---- Guest sample is readable offline, but answers are only checked online -----------------------
    await page.getByRole('link', { name: 'Try a sample scenario' }).click();
    await page.getByRole('button', { name: 'Continue to the sample' }).click();
    await page.getByText('Saved only in this browser on this device.').waitFor();
    await audit(page, 'sample: scenario step');
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('heading', { name: 'First move' }).waitFor();
    await audit(page, 'sample: question step');
    await page.getByRole('button', { name: 'Need a hint?' }).click();
    await page.getByText('Hint 1 of 2').waitFor();
    await page.getByLabel(/Seq Scan on work_orders/).check();
    await page.getByRole('button', { name: 'Check answer' }).click();
    await page.getByRole('heading', { name: /A good answer/ }).waitFor();
    await audit(page, 'sample: feedback');

    await page.getByRole('button', { name: 'Save and exit' }).click();
    await page.goto(`${BASE}/sample`);
    await page.getByText('Saved only in this browser').waitFor();
    await ctx.setOffline(true);
    await page.reload();
    await page.getByText('You are offline.').waitFor();
    await page.getByRole('heading', { level: 1 }).waitFor();
    step('offline reload: the app shell and the cached sample open, with an offline notice');
    await page.screenshot({ path: `${OUT}/08-offline-sample.png` });
    await ctx.setOffline(false);

    // ---- Sign up, onboard, audit every signed-in screen --------------------------------------------------
    await page.goto(`${BASE}/sign-up`);
    await audit(page, 'sign-up');
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByLabel(/^Password/).fill(password);
    await page.getByLabel(/Pilot invite code/).fill(invite);
    await page.getByRole('button', { name: 'Create account' }).click();
    // The guest bundle (one answered question) is claimed, which also fills onboarding.
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    await audit(page, 'today');
    for (const [name, path, heading] of [['roadmap', '/roadmap', 'Roadmap'], ['evidence', '/evidence', 'Evidence'], ['settings', '/settings', 'Settings']]) {
        await page.goto(`${BASE}${path}`);
        await page.getByRole('heading', { name: heading, exact: true }).waitFor();
        await page.waitForTimeout(300);
        await audit(page, name);
    }
    await page.goto(`${BASE}/settings`);
    await page.getByRole('heading', { name: 'Use DevStep as an app' }).waitFor();
    step('Settings offers installing the app (no nagging elsewhere)');

    // ---- Offline while signed in: honest, not signed out, and privacy-safe caches ------------------------
    await page.goto(`${BASE}/today`);
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    await ctx.setOffline(true);
    await page.reload();
    await page.getByRole('heading', { name: 'You are offline' }).waitFor();
    assert(!(await page.locator('body').innerText()).includes('Try a sample scenario'), 'a signed-in learner is not shown the guest landing page offline');
    await page.screenshot({ path: `${OUT}/09-offline-signed-in.png` });
    await ctx.setOffline(false);
    await page.getByRole('button', { name: 'Try again' }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    step('offline while signed in: honest offline screen, no sign-out, recovers on retry');

    const cached = await page.evaluate(async () => {
        const out = [];
        for (const k of await caches.keys()) for (const r of await (await caches.open(k)).keys()) out.push(new URL(r.url).pathname);
        return out;
    });
    const personal = cached.filter((p) => p.startsWith('/v1/') && !['/v1/guest/sample'].includes(p) && !p.startsWith('/v1/roadmaps'));
    assert(personal.length === 0, `no personal API response is cached, found: ${personal.join(', ')}`);
    assert(cached.some((p) => p.startsWith('/build/assets/')), 'build assets are cached');
    step(`caches hold ${cached.length} entries and no authenticated API responses`);

    assert(errors.length === 0, `no uncaught browser errors: ${errors.join('; ')}`);

    if (violations.length) {
        console.error(`\nAccessibility violations (serious or critical):\n - ${[...new Set(violations)].join('\n - ')}`);
        process.exitCode = 1;
    } else {
        step('axe-core: no serious or critical WCAG 2.2 AA violations on any screen, light or dark');
    }
} catch (e) {
    for (const [i, c] of browser.contexts().entries()) {
        for (const [j, p] of c.pages().entries()) {
            console.error(`\n--- page ${i}.${j}: ${p.url()}`);
            console.error((await p.locator('body').innerText().catch(() => '')).slice(0, 500));
            await p.screenshot({ path: `${OUT}/failure-app-${i}-${j}.png`, fullPage: true }).catch(() => undefined);
        }
    }
    throw e;
} finally {
    await browser.close();
}
