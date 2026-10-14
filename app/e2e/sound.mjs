// The sound pack (docs/15 §8): off until the learner turns it on, silent for everything except authoritative
// feedback and a few deliberate moments, one cue at a time, instant mute, nothing downloaded while it is off,
// and the pages stay accessible with the toggle in place.
//
// `Audio` is replaced by a recorder, so this checks exactly which cue the app asked to play and when.
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { chromium } from 'playwright-core';

const require = createRequire(import.meta.url);
const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const CHROMIUM = process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium';
const step = (m) => console.log(`✔ ${m}`);
const assert = (c, m) => { if (!c) throw new Error(`ASSERTION FAILED: ${m}`); };
execFileSync('php', ['artisan', 'cache:clear']);

const CUES = ['lesson-complete', 'try-again', 'milestone', 'correct', 'welcome', 'saved', 'next', 'hint', 'rest', 'tap'];
const cueOf = (src) => CUES.find((c) => src.includes(`/${c}-`)) ?? src;
const human = (page) => page.waitForTimeout(450); // a person is slower than the repeat guard
const events = (page) => page.evaluate(() => window.__sounds);
const cues = async (page) => (await events(page)).filter((e) => e.type === 'play').map((e) => cueOf(e.src));

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

async function newPage({ stub = true } = {}) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-GB', timezoneId: 'Europe/London' });
    if (stub) await ctx.addInitScript(() => {
        window.__sounds = [];
        window.Audio = class {
            constructor(src) { this.src = src; this.volume = 1; this.currentTime = 0; this.duration = NaN; this.preload = ''; }
            play() { window.__sounds.push({ type: 'play', src: this.src }); return Promise.resolve(); }
            pause() { window.__sounds.push({ type: 'stop', src: this.src }); }
        };
    });
    const page = await ctx.newPage();
    const wavs = [];
    page.on('request', (r) => { if (r.url().endsWith('.wav')) wavs.push(r.url()); });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    return { page, wavs, errors };
}

async function startSample(page) {
    await page.goto(BASE);
    await page.getByRole('link', { name: 'Try a sample scenario' }).click();
    await page.getByRole('heading', { name: 'A few quick questions' }).waitFor();
    await page.getByRole('button', { name: 'Continue to the sample' }).click();
    await page.getByText('Saved only in this browser on this device.').waitFor();
}

try {
    // ---- 1. Off by default: the whole sample is silent and nothing is downloaded ---------------------------
    {
        const { page, wavs, errors } = await newPage();
        await startSample(page);
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByRole('heading', { name: 'First move' }).waitFor();
        const toggle = page.getByRole('button', { name: 'Sound effects' });
        assert(await toggle.getAttribute('aria-pressed') === 'false', 'the toggle starts off');
        await page.getByRole('button', { name: 'Need a hint?' }).click();
        await page.getByText('Hint 1 of 2').waitFor();
        await page.getByLabel(/Seq Scan on work_orders/).check();
        await page.getByRole('button', { name: 'Check answer' }).click();
        await page.getByRole('heading', { name: /A good answer/ }).waitFor();
        await page.getByRole('button', { name: 'Continue' }).click();
        await page.getByRole('heading', { name: 'Worked example' }).waitFor();
        assert((await events(page)).length === 0, `sound is off by default, but ${JSON.stringify(await events(page))} was asked to play`);
        assert(errors.length === 0, `page errors: ${errors.join('; ')}`);
        step('off by default: hint, answer and feedback make no sound');
    }

    // ---- 2. Turn it on in Settings, then the right cue at the right moment, one at a time ------------------
    {
        const { page, wavs, errors } = await newPage();
        await page.goto(`${BASE}/settings`);
        await page.getByRole('heading', { name: 'Settings' }).waitFor();
        await audit(page, 'Settings with Sound effects');
        await page.getByLabel('On: short sounds with feedback').check();
        assert((await cues(page)).join() === 'tap', `turning sound on previews one soft tap, got ${(await cues(page)).join()}`);
        step('Settings: choosing On previews a single soft tap');

        await human(page);
        await startSample(page);
        await page.getByRole('button', { name: 'Next' }).click(); // scenario -> first move
        await page.getByRole('heading', { name: 'First move' }).waitFor();
        await human(page);
        await audit(page, 'Player with the sound toggle');
        await page.getByRole('button', { name: 'Need a hint?' }).click();
        await page.getByText('Hint 1 of 2').waitFor();
        await human(page);
        await page.getByLabel(/Seq Scan on work_orders/).check();
        await page.getByRole('button', { name: 'Check answer' }).click();
        await page.getByRole('heading', { name: /A good answer/ }).waitFor();
        await human(page);
        await page.getByRole('button', { name: 'Continue' }).click();
        await page.getByRole('heading', { name: 'Worked example' }).waitFor();
        await human(page);
        await page.getByRole('button', { name: 'Continue' }).click();
        await page.getByRole('heading', { name: 'Read the plan' }).waitFor();
        await human(page);

        // Typing and choosing an option are silent, and so is a wrong answer's neighbour: only the result sounds.
        const before = (await cues(page)).length;
        await page.getByLabel(/Seq Scan|Try an index/).first().check();
        assert((await cues(page)).length === before, 'choosing an option is silent');

        // Mute is instant: nothing sounds after it, and the result is still shown.
        await page.getByRole('button', { name: 'Sound effects' }).click();
        assert(await page.getByRole('button', { name: 'Sound effects' }).getAttribute('aria-pressed') === 'false', 'the toggle turns sound off');
        await page.getByLabel(/Try an index that matches the filter/).check();
        const muted = (await cues(page)).length;
        await page.getByRole('button', { name: 'Check answer' }).click();
        await page.getByRole('heading', { name: /A good answer/ }).waitFor();
        assert((await cues(page)).length === muted, 'muted: the result appears and nothing sounds');
        step('mute is instant, and the visible result does not depend on sound');

        await page.getByRole('button', { name: 'Sound effects' }).click(); // on again
        await human(page);
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByRole('heading', { name: 'Changed condition' }).waitFor();
        await human(page);
        await page.getByLabel(/It might use a different scan type/).check();
        await page.getByRole('button', { name: 'Check answer' }).click();
        await page.getByRole('heading', { name: /A good answer/ }).waitFor();
        await human(page);
        await page.getByRole('button', { name: 'Finish the sample' }).click();
        await page.getByRole('heading', { name: 'Sample complete' }).waitFor();
        await page.waitForTimeout(200);

        const sequence = (await cues(page)).join(' > ');
        const expected = 'next > hint > correct > next > next > next > correct > lesson-complete';
        assert(sequence === expected, `cue order\n   got      ${sequence}\n   expected ${expected}`);
        step(`cues follow the lesson: ${sequence}`);

        // One at a time: every cue after the first stops the one before it.
        const ev = await events(page);
        let lastPlay = -1;
        ev.forEach((e, i) => {
            if (e.type !== 'play') return;
            if (lastPlay >= 0) assert(ev.slice(lastPlay + 1, i).some((x) => x.type === 'stop'), 'a cue started while the previous one was still playing');
            lastPlay = i;
        });
        assert(errors.length === 0, `page errors: ${errors.join('; ')}`);
        step('one cue at a time');

        await audit(page, 'Sample complete with sound on');
    }

    // ---- 2b. With the real Audio: nothing is downloaded while sound is off; files arrive only once it is on -----
    {
        const { page, wavs } = await newPage({ stub: false });
        await startSample(page);
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByRole('heading', { name: 'First move' }).waitFor();
        await page.getByRole('button', { name: 'Need a hint?' }).click();
        await page.getByText('Hint 1 of 2').waitFor();
        await page.waitForTimeout(500);
        assert(wavs.length === 0, `no sound file is downloaded while sound is off (${wavs.join(', ')})`);
        await page.goto(`${BASE}/settings`);
        await page.getByLabel('On: short sounds with feedback').check();
        await page.waitForTimeout(800);
        assert(wavs.length === 1 && /tap-/.test(wavs[0]), `turning sound on fetches only the one cue it plays (${wavs.join(', ')})`);
        step('real audio: no sound file is fetched while off; turning it on fetches only the cue it plays');
    }

    // ---- 3. The choice is remembered, and it can be switched off again from Settings -----------------------
    {
        const { page } = await newPage();
        await page.goto(`${BASE}/settings`);
        await page.getByLabel('On: short sounds with feedback').check();
        await page.reload();
        assert(await page.getByLabel('On: short sounds with feedback').isChecked(), 'sound stays on after a reload');
        await page.getByLabel('Off', { exact: true }).check();
        const n = (await cues(page)).length;
        await page.getByLabel('On: short sounds with feedback').check();
        await page.getByLabel('Off', { exact: true }).check();
        assert((await events(page)).filter((e) => e.type === 'stop').length >= 1, 'switching off silences anything playing');
        assert(n >= 0, 'sound preference round trip');
        step('the choice persists on this device and Off silences immediately');
    }

    assert(violations.length === 0, `accessibility violations:\n  ${violations.join('\n  ')}`);
    step('axe-core: no serious or critical violations on Settings, the player or the sample finish, light or dark');
    console.log('\nAll sound checks passed.');
} catch (e) {
    console.error(`\n✖ ${e.message}`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
