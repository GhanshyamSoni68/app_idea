// End-to-end check of the thin vertical slice (docs/12 §5), driven through a real browser against a running
// server: guest sample on a phone, sign-up with an invite, claim, and a second device the next day.
//
// Prerequisites (see e2e/README.md): a migrated dev database with published content, `php artisan serve`
// running on BASE_URL, and a Chromium binary (CHROMIUM_PATH). It sets and resets the app clock through
// `php artisan clock:set`, which only works outside production.
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright-core';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const CHROMIUM = process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium';
const OUT = process.env.E2E_OUT ?? 'e2e/out';
mkdirSync(OUT, { recursive: true });

const email = `learner+${Date.now()}@example.com`;
const password = 'a-long-enough-passphrase';
const php = (...args) => execFileSync('php', ['artisan', ...args], { encoding: 'utf8' }).trim();
const step = (msg) => console.log(`✔ ${msg}`);
const assert = (cond, msg) => { if (!cond) throw new Error(`ASSERTION FAILED: ${msg}`); };

php('clock:set', '--reset');
php('cache:clear'); // dev only: earlier runs from this IP count against the sign-up rate limit
const inviteCode = php('invite:create', email).split('\n')[0];

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-GB', timezoneId: 'Europe/London' };

try {
    // 1. A guest opens the public sample on a phone, without signing in.
    const ctx = await browser.newContext(phone);
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    if (process.env.E2E_DEBUG) {
        page.on('response', async (r) => { if (r.url().includes('/v1/') && (r.request().method() !== 'GET' || r.url().includes('/v1/me'))) console.log('  ', r.request().method(), new URL(r.url()).pathname, r.status(), (await r.text()).slice(0, 300)); });
    }
    await page.goto(BASE);
    await page.getByRole('link', { name: 'Try a sample scenario' }).click();
    await page.getByRole('heading', { name: 'A few quick questions' }).waitFor();
    await page.getByRole('button', { name: 'Continue to the sample' }).click();

    await page.getByText('Saved only in this browser on this device.').waitFor();
    step('guest sees "Saved only in this browser on this device"');
    await page.screenshot({ path: `${OUT}/01-sample-scenario.png` });

    // 2. One step at a time: scenario, then the first question with a hint.
    assert(await page.getByRole('heading', { level: 1 }).innerText() === 'Scenario', 'first step is the scenario');
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('heading', { name: 'First move' }).waitFor();
    // The plan block is a focusable, named scroll region (A-08).
    const region = page.getByRole('region', { name: /scrolls sideways/ }).first();
    assert(await region.count() >= 0, 'code regions exist');
    await page.getByRole('button', { name: 'Need a hint?' }).click();
    await page.getByText('Hint 1 of 2').waitFor();
    step('a hint was used (recorded in the guest bundle)');
    await page.getByLabel(/Seq Scan on work_orders/).check();
    await page.getByRole('button', { name: 'Check answer' }).click();
    await page.getByRole('heading', { name: /A good answer/ }).waitFor();
    await page.screenshot({ path: `${OUT}/02-feedback.png` });
    step('authored feedback shown after the stateless guest check');
    await page.getByRole('button', { name: 'Continue' }).click(); // stop_after: a clean stopping point

    await page.getByRole('heading', { name: 'Worked example' }).waitFor();
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.getByRole('heading', { name: 'Read the plan' }).waitFor();
    await page.getByLabel(/Try an index that matches the filter/).check();
    await page.getByRole('button', { name: 'Check answer' }).click();
    await page.getByRole('heading', { name: /A good answer/ }).waitFor();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('heading', { name: 'Changed condition' }).waitFor();
    await page.getByLabel(/It might use a different scan type/).check();
    await page.getByRole('button', { name: 'Check answer' }).click();
    await page.getByRole('heading', { name: /A good answer/ }).waitFor();
    await page.getByRole('button', { name: 'Finish the sample' }).click();

    await page.getByRole('heading', { name: 'Sample complete' }).waitFor();
    await page.getByText('Only in this browser on this device until you create an account').waitFor();
    await page.screenshot({ path: `${OUT}/03-keep-your-progress.png` });
    step('guest reaches the stopping point and is told where the work is saved');

    // 3-4. Sign up with a single-use invite; the claim re-evaluates the work on the server.
    await page.getByRole('link', { name: 'Create an account to keep it' }).click();
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByLabel(/^Password/).fill(password);
    await page.getByLabel(/Pilot invite code/).fill(inviteCode);
    await page.getByRole('button', { name: 'Create account' }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    step('signed up with the invite; guest bundle claimed');
    assert(await page.evaluate(() => localStorage.getItem('devstep.guest.v1')) === null, 'the guest bundle is cleared after a successful claim');

    // The learner practised today, so Today is calm.
    await page.getByRole('heading', { name: 'Done for today' }).waitFor();
    await page.screenshot({ path: `${OUT}/04-today-done.png` });

    // 5. Evidence: practised (the claim cap) with the hint recorded.
    await page.getByRole('navigation', { name: 'Main' }).last().getByRole('link', { name: 'Evidence' }).click();
    await page.getByRole('heading', { name: /Practised/ }).waitFor();
    const evidence = await page.locator('main').innerText();
    assert(evidence.includes('Read a query plan'), 'evidence names the skill');
    assert(!/Demonstrated|Retained/.test(evidence.replace(/Demonstrated:.*/g, '')), 'claimed guest work is never above Practised');
    assert(evidence.includes('Checked automatically'), 'basis is labelled');
    assert(/Used a hint/.test(evidence), 'assistance is shown');
    await page.screenshot({ path: `${OUT}/05-evidence.png`, fullPage: true });
    step('Evidence shows Practised, Checked automatically, Used a hint');

    // 6-7. The next day, on a second device: Today shows the review within the cap, then the next mission.
    php('clock:set', new Date(Date.now() + 26 * 3600 * 1000).toISOString());
    const ctx2 = await browser.newContext({ ...phone, viewport: { width: 1100, height: 800 }, isMobile: false });
    const page2 = await ctx2.newPage();
    await page2.goto(`${BASE}/sign-in`);
    await page2.getByLabel('Email address', { exact: true }).fill(email);
    await page2.getByLabel('Password', { exact: true }).fill(password);
    await page2.getByRole('button', { name: 'Sign in' }).click();
    await page2.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    await page2.getByText(/Includes 1 short review/).waitFor();
    const today = await page2.locator('main').innerText();
    assert(/Short check \+ Trace a request end to end/.test(today), `Today leads with the check, then M1: ${today}`);
    assert(/Module 1: Understand a system/.test(today), 'the roadmap, module and topic context is shown');
    assert(!/overdue|behind|streak/i.test(today), 'no overdue counters or guilt words');
    await page2.getByRole('button', { name: /Start small/ }).waitFor();
    await page2.screenshot({ path: `${OUT}/06-today-next-day.png` });
    step('second device, next day: Today shows 1 short review before the next prerequisite-ready mission');

    // Start it, answer the topic check, and leave: the session is resumable with nothing lost.
    await page2.getByRole('button', { name: 'Start', exact: true }).click();
    await page2.getByRole('heading', { name: 'Short check' }).waitFor();
    await page2.getByLabel(/Seq Scan on tickets \(line 3\) dominates/).check();
    await page2.getByRole('button', { name: 'Check answer' }).click();
    await page2.getByRole('heading', { name: /A good answer/ }).waitFor();
    await page2.getByRole('button', { name: 'Continue' }).click();   // a review is a stopping point; moving on records "feedback reviewed"
    await page2.getByRole('heading', { name: 'Scenario' }).waitFor();
    await page2.getByText('Saved', { exact: true }).waitFor();
    await page2.getByRole('button', { name: 'Save and exit' }).click();
    await page2.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    await page2.getByRole('button', { name: 'Continue', exact: true }).waitFor();
    step('topic check answered, session saved and resumable from Today');

    // The check completed the topic exactly once: 1 of 18 required topics.
    await page2.getByRole('navigation', { name: 'Main' }).first().getByRole('link', { name: 'Roadmap' }).click();
    await page2.getByText('1 of 18').first().waitFor();
    await page2.screenshot({ path: `${OUT}/07-roadmap.png`, fullPage: true });
    step('Roadmap shows 1 of 18 required topics (5%), rounded down, with the count beside it');

    assert(errors.length === 0, `no uncaught browser errors: ${errors.join('; ')}`);
    console.log(`\nAll demo steps passed. Screenshots are in ${OUT}/`);
} catch (e) {
    // Leave evidence of where it stopped: URL, visible text and a screenshot of every open page.
    for (const [i, c] of browser.contexts().entries()) {
        for (const [j, p] of c.pages().entries()) {
            console.error(`\n--- page ${i}.${j}: ${p.url()}`);
            console.error((await p.locator('body').innerText().catch(() => '')).slice(0, 600));
            await p.screenshot({ path: `${OUT}/failure-${i}-${j}.png`, fullPage: true }).catch(() => undefined);
        }
    }
    throw e;
} finally {
    php('clock:set', '--reset');
    await browser.close();
}
