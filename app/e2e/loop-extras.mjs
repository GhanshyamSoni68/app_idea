// Challenge-out, pause and resume, defer, and the return-after-absence refresher, in a real browser (docs/05 §7, §10).
// The journey ends with `retained` evidence earned on the delayed alternate item, 10 learner days after a demonstration.
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

php('clock:set', '--reset');
php('cache:clear');
const email = `loop+${Date.now()}@example.com`;
const invite = php('invite:create', email).split('\n')[0];
const violations = [];

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const page = await (await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-GB', timezoneId: 'Europe/London' })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));

async function audit(name) {
    await page.waitForTimeout(400); // let colour transitions settle: axe samples mid-transition otherwise
    for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') }).catch(() => undefined);
        const res = await page.evaluate(async () => await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'] } }));
        for (const v of res.violations.filter((x) => ['serious', 'critical'].includes(x.impact))) violations.push(`${name} [${scheme}] ${v.id}: ${v.help} → ${v.nodes[0].target.join(' ')}`);
    }
    await page.emulateMedia({ colorScheme: 'light' });
}

try {
    // Sign up directly (no guest work), answer onboarding, enrol.
    await page.goto(`${BASE}/sign-up`);
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByLabel(/^Password/).fill('a-long-enough-passphrase');
    await page.getByLabel(/Pilot invite code/).fill(invite);
    await page.getByRole('button', { name: 'Create account' }).click();
    await page.getByRole('heading', { name: 'A few quick questions' }).waitFor();
    await page.getByRole('button', { name: 'Save and continue' }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    await page.getByText('Completing it means'.slice(0, 4)).first().waitFor().catch(() => undefined);
    await page.getByText(/What completing it means/).waitFor();
    await page.getByRole('button', { name: 'Enrol in this roadmap' }).click();
    await page.getByRole('button', { name: 'Start', exact: true }).waitFor();
    step('enrolled after seeing the outcome, effort and the coverage-not-mastery note');

    // Roadmap: defer a topic (no credit), then bring it back.
    await page.getByRole('navigation', { name: 'Main' }).last().getByRole('link', { name: 'Roadmap' }).click();
    await page.getByRole('heading', { name: 'Roadmap', exact: true }).waitFor();
    const row = (title) => page.locator('.topic').filter({ has: page.getByText(title, { exact: true }) });
    const topic = row('Trace a request end to end');
    await topic.getByRole('button', { name: 'Defer' }).click();
    await page.getByText(/Deferred, no credit/).first().waitFor();
    assert(await page.getByText('0 of 18').first().isVisible(), 'deferring earns no credit');
    await topic.getByRole('button', { name: 'Bring back' }).click();
    await topic.getByRole('button', { name: 'I already know this' }).waitFor();
    step('defer shows "Deferred, no credit", progress stays 0 of 18, and bringing it back works');

    // Challenge-out: a calm confirmation, two questions, no hint controls.
    await topic.getByRole('button', { name: 'I already know this' }).click();
    await page.getByRole('heading', { name: 'Take the challenge?' }).waitFor();
    await audit('roadmap: challenge dialog');
    await page.screenshot({ path: `${OUT}/10-challenge-dialog.png` });
    await page.getByRole('button', { name: 'Start the challenge' }).click();
    await page.getByRole('heading', { name: /Challenge, question 1 of 2/ }).waitFor();
    assert(await page.getByRole('button', { name: /hint/i }).count() === 0, 'no hint button in a challenge');
    assert(await page.getByRole('button', { name: 'Show solution' }).count() === 0, 'no solution button in a challenge');
    await page.getByLabel(/The background thumbnail job and its log/).check();
    await page.getByRole('button', { name: 'Check answer' }).click();
    await page.getByRole('heading', { name: /A good answer/ }).waitFor();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('heading', { name: /question 2 of 2/ }).waitFor();
    await page.getByLabel(/In the database, saved by the application/).check();
    await page.getByRole('button', { name: 'Check answer' }).click();
    await page.getByRole('heading', { name: /A good answer/ }).waitFor();
    await page.getByRole('button', { name: 'Finish the challenge' }).click();
    await page.getByRole('heading', { name: 'Session complete' }).waitFor();
    await page.getByText('Challenge passed.').waitFor();
    await audit('challenge result');
    await page.screenshot({ path: `${OUT}/11-challenge-passed.png`, fullPage: true });
    step('challenge passed: topic completed by challenge, no hints offered');

    await page.getByRole('navigation', { name: 'Main' }).last().getByRole('link', { name: 'Roadmap' }).click();
    await page.getByRole('heading', { name: 'Roadmap', exact: true }).waitFor();
    await page.getByRole('progressbar', { name: 'required topics' }).waitFor();
    await page.getByText('1 of 18').first().waitFor();
    assert((await row('Trace a request end to end').innerText()).includes('by challenge'), 'roadmap says completed by challenge');
    assert(await row('Trace a request end to end').getByRole('button', { name: 'I already know this' }).count() === 0, 'one attempt per topic');

    // Pause and resume.
    await page.getByRole('button', { name: 'Pause roadmap' }).click();
    await page.getByText(/Paused\. Your progress and drafts are kept/).first().waitFor();
    await audit('roadmap: paused');
    await page.getByRole('navigation', { name: 'Main' }).last().getByRole('link', { name: 'Today' }).click();
    await page.getByRole('button', { name: 'Resume', exact: true }).waitFor();
    await page.screenshot({ path: `${OUT}/12-paused.png` });
    await page.getByRole('button', { name: 'Resume', exact: true }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    step('pause keeps progress; resume returns to Today with nothing owed');

    // Ten days away: a calm welcome back and a three-minute refresher, not a pile of reviews.
    php('clock:set', new Date(Date.now() + 10 * 86400 * 1000 + 3600 * 1000).toISOString());
    await page.goto(`${BASE}/today`);
    await page.getByRole('heading', { name: 'Welcome back' }).waitFor();
    const body = await page.locator('main').innerText();
    assert(!/missed|behind|overdue|streak|lost|days/i.test(body.replace(/Welcome back.*/s, (m) => m)) || !/(\d+) days/.test(body), 'no counting of missed days');
    assert(!/missed|behind|overdue|streak/i.test(body), 'no guilt words on the return screen');
    assert(await page.getByRole('button', { name: /Today's plan|Continue:/ }).count() === 1, 'the alternative to the refresher is offered exactly once');
    await audit('today: welcome back');
    await page.screenshot({ path: `${OUT}/13-welcome-back.png`, fullPage: true });
    await page.getByRole('button', { name: 'Try the 3-minute refresher' }).click();
    await page.getByRole('heading', { name: 'Quick recall' }).waitFor();
    // The delayed alternate item (reserved for retention): the worker's log.
    await page.getByLabel(/The worker's log for the notification job/).check();
    await page.getByRole('button', { name: 'Check answer' }).click();
    await page.getByRole('heading', { name: /A good answer/ }).waitFor();
    await page.getByRole('button', { name: 'Finish' }).click();
    await page.getByRole('heading', { name: 'Session complete' }).waitFor();
    await page.getByRole('navigation', { name: 'Main' }).last().getByRole('link', { name: 'Evidence' }).click();
    await page.getByRole('heading', { name: /Retained/ }).waitFor();
    const ev = await page.locator('main').innerText();
    assert(ev.includes('Trace a request end to end'), 'the retained skill is named');
    await page.screenshot({ path: `${OUT}/14-retained.png`, fullPage: true });
    step('refresher on the delayed alternate earned "Retained" (unassisted, new item, 7+ days after demonstrating)');

    await page.goto(`${BASE}/today`);
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    assert(!(await page.locator('main').innerText()).includes('Welcome back'), 'the welcome back is offered once');
    step('welcome back is offered once per absence');

    assert(errors.length === 0, `no uncaught browser errors: ${errors.join('; ')}`);
    if (violations.length) {
        console.error(`\nAccessibility violations:\n - ${[...new Set(violations)].join('\n - ')}`);
        process.exitCode = 1;
    } else step('axe-core: no serious or critical violations on the new screens, light or dark');
} catch (e) {
    for (const [j, p] of page.context().pages().entries()) {
        console.error(`\n--- page ${j}: ${p.url()}`);
        console.error((await p.locator('body').innerText().catch(() => '')).slice(0, 700));
        await p.screenshot({ path: `${OUT}/failure-loop-${j}.png`, fullPage: true }).catch(() => undefined);
    }
    throw e;
} finally {
    php('clock:set', '--reset');
    await browser.close();
}
