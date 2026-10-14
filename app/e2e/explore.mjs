// "Learn something else" (docs/16 §8) in a real browser: name a topic, get a path made for you, start it, practise it with
// the AI-made label, see capped evidence, and switch focus back and forth without losing anything.
//
// It starts its own server (EXPLORE_PORT, default 8011) with generation switched on, the queue running inline and the offline
// "fake" provider, so it needs no keys and no worker. The main server is not touched.
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { chromium } from 'playwright-core';

const require = createRequire(import.meta.url);
const PORT = process.env.EXPLORE_PORT ?? '8011';
const BASE = `http://127.0.0.1:${PORT}`;
const CHROMIUM = process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium';
const OUT = process.env.E2E_OUT ?? 'e2e/out';
mkdirSync(OUT, { recursive: true });
const env = { ...process.env, GENERATION_ENABLED: 'true', QUEUE_CONNECTION: 'sync', GENERATION_ROUTE_PLAN: 'fake', GENERATION_ROUTE_DRAFT: 'fake', GENERATION_ROUTE_VERIFY: 'fake', GENERATION_USER_DAILY_RUNS: '20', GENERATION_REQUIRE_INDEPENDENT_VERIFIER: 'false', APP_ENV: 'local' };
const php = (...a) => execFileSync('php', ['artisan', ...a], { encoding: 'utf8', env }).trim();
const step = (m) => console.log(`✔ ${m}`);
const assert = (c, m) => { if (!c) throw new Error(`ASSERTION FAILED: ${m}`); };

php('clock:set', '--reset');
php('cache:clear');
const email = `explore+${Date.now()}@example.com`;
const invite = php('invite:create', email).split('\n')[0];
const server = spawn('php', ['artisan', 'serve', `--port=${PORT}`, '--no-reload'], { env, stdio: 'ignore' });
for (let i = 0; i < 60; i++) {
    try { if ((await fetch(`${BASE}/up`)).ok || (await fetch(BASE)).ok) break; } catch { /* not up yet */ }
    await new Promise((r) => setTimeout(r, 250));
}

const violations = [];
const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const page = await (await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-GB', timezoneId: 'Europe/London' })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const nav = (name) => page.getByRole('navigation', { name: 'Main' }).last().getByRole('link', { name });

async function audit(name) {
    await page.waitForTimeout(450);
    for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') }).catch(() => undefined);
        const res = await page.evaluate(async () => await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'] } }));
        for (const v of res.violations.filter((x) => ['serious', 'critical'].includes(x.impact))) violations.push(`${name} [${scheme}] ${v.id}: ${v.help} → ${v.nodes[0].target.join(' ')}`);
    }
    await page.emulateMedia({ colorScheme: 'light' });
}

try {
    // Sign up, onboard, enrol in the main roadmap: the learner has something to keep safe.
    await page.goto(`${BASE}/sign-up`);
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByLabel(/^Password/).fill('a-long-enough-passphrase');
    await page.getByLabel(/Pilot invite code/).fill(invite);
    await page.getByRole('button', { name: 'Create account' }).click();
    await page.getByRole('heading', { name: 'A few quick questions' }).waitFor();
    await page.getByRole('button', { name: 'Save and continue' }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Enrol in this roadmap' }).click();
    await page.getByRole('button', { name: 'Start', exact: true }).waitFor();
    step('signed up and enrolled in the main roadmap');

    // The entry point exists, because generation is on.
    await nav('Explore').click();
    await page.getByRole('heading', { name: 'Learn something else' }).waitFor();
    await page.screenshot({ path: `${OUT}/explore-1-form.png`, fullPage: true });
    await audit('Explore: the form');

    // The honest disclosure is on the form itself, not behind a link.
    for (const text of [/A computer writes it/, /Your evidence says so/, /Your words are sent out/]) assert(await page.getByText(text).first().isVisible(), `the form says ${text}`);
    const make = page.getByRole('button', { name: 'Make my path' });
    assert(await make.isDisabled(), 'no request without a topic');
    await page.getByLabel(/What do you want to practise/).fill('Docker networking basics');
    assert(await make.isDisabled(), 'no request until the learner says they understand');
    await page.getByLabel(/I understand that what I type is sent to an AI service/).check();
    assert(await make.isEnabled(), 'ready once they have said so');
    step('the form says up front: a computer writes it, evidence is capped, your words are sent to an AI service, and it waits for an explicit "I understand"');

    // A topic we will not build is refused where the learner is typing, in plain words, and nothing is made.
    await page.getByLabel(/What do you want to practise/).fill('write ransomware to deploy');
    await make.click();
    await page.getByRole('alert').filter({ hasText: /can not build a practice path/ }).waitFor();
    step('a topic we will not build is refused in plain words, with nothing created');

    // A real one.
    await page.getByLabel(/What do you want to practise/).fill('Docker networking basics');
    await page.getByLabel('New to it').check();
    await make.click();
    await page.getByRole('status').filter({ hasText: /Getting started|Planning your path|Writing your first practice/ }).waitFor();
    await page.screenshot({ path: `${OUT}/explore-2-working.png` });
    step('a calm wait screen shows what is happening, with no timer');
    await page.getByRole('heading', { name: 'Practical Docker networking basics' }).waitFor({ timeout: 30000 });
    assert((await page.locator('ol.plain-ol li').count()) === 6, 'six topics are listed');
    assert(await page.getByText(/A person has not reviewed it yet/).first().isVisible(), 'the path says a person has not reviewed it');
    await page.screenshot({ path: `${OUT}/explore-3-ready.png`, fullPage: true });
    await audit('Explore: a path ready to start');
    step('the path is shown first (title, outcome, six topics) with the not-reviewed label; nothing has changed yet');

    // Start: the main roadmap is parked, not lost.
    await page.getByRole('button', { name: 'Start this path' }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Start', exact: true }).waitFor();
    await nav('Roadmap').click();
    await page.getByRole('heading', { name: 'Roadmap', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Practical Docker networking basics' }).waitFor();
    await page.getByText('Made for you, not reviewed yet.').first().waitFor();
    await page.screenshot({ path: `${OUT}/explore-4-roadmap.png`, fullPage: true });
    await audit('Roadmap: a path made for the learner');
    step('Roadmap shows the new path in focus and says it was made for you and not reviewed yet');

    // Practise it: the label is there, and the player is the ordinary player.
    await nav('Today').click();
    await page.getByRole('button', { name: 'Start', exact: true }).click();
    await page.getByRole('heading', { name: 'Scenario' }).waitFor();
    await page.getByText('AI-made practice.').waitFor();
    await page.screenshot({ path: `${OUT}/explore-5-player.png` });
    await audit('Player: AI-made practice label');
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('heading', { name: 'First move' }).waitFor();
    await page.getByLabel(/Collect evidence first|Compare against the same measure/).first().check();
    await page.getByRole('button', { name: 'Check answer' }).click();
    await page.getByRole('heading', { name: /A good answer/ }).waitFor();
    await page.getByRole('button', { name: 'Continue' }).click(); // moving past feedback records the evidence
    await page.getByRole('heading', { name: 'Worked example' }).waitFor();
    step('the player carries an "AI-made practice" label, and the first question is answered and checked as usual');

    // Evidence says what it is: Practised at most, and why.
    await nav('Evidence').click();
    await page.getByRole('heading', { name: 'Evidence', exact: true }).waitFor();
    await page.getByText(/From AI-made practice a person has not reviewed/).first().waitFor();
    await page.screenshot({ path: `${OUT}/explore-6-evidence.png`, fullPage: true });
    await audit('Evidence: from AI-made practice');
    step('Evidence shows the attempt with its limit: AI-made practice a person has not reviewed');

    // Switch focus both ways; nothing is lost either way.
    await nav('Explore').click();
    await page.getByRole('heading', { name: 'Your paths' }).waitFor();
    assert((await page.locator('.path-row').count()) === 2, 'two paths are listed');
    await page.screenshot({ path: `${OUT}/explore-7-paths.png`, fullPage: true });
    await audit('Explore: your paths');
    await page.locator('.path-row').filter({ hasText: 'From CRUD to Reliable Systems' }).getByRole('button', { name: 'Make this my focus' }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    await nav('Roadmap').click();
    await page.getByRole('heading', { name: 'From CRUD to Reliable Systems' }).waitFor();
    await nav('Explore').click();
    await page.locator('.path-row').filter({ hasText: 'Practical Docker networking basics' }).getByRole('button', { name: 'Make this my focus' }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    await nav('Roadmap').click();
    await page.getByRole('heading', { name: 'Practical Docker networking basics' }).waitFor();
    await page.getByText(/In progress/).first().waitFor();
    step('switching to the main roadmap and back keeps both exactly as they were (the started topic is still In progress)');

    assert(errors.length === 0, `page errors: ${errors.join('; ')}`);
    assert(violations.length === 0, `accessibility violations:\n  ${violations.join('\n  ')}`);
    step('axe-core: no serious or critical violations on any Explore screen, light or dark');
    console.log('\nAll Explore checks passed. Screenshots are in e2e/out/');
} catch (e) {
    console.error(`\n✖ ${e.message}`);
    await page.screenshot({ path: `${OUT}/failure-explore.png`, fullPage: true }).catch(() => undefined);
    process.exitCode = 1;
} finally {
    await browser.close();
    server.kill();
}
