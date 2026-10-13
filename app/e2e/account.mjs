// Account lifecycle in a real browser (F09): email verification, password reset, data export, and deletion with a
// cancellable grace period followed by a purge. Emailed links are read from the dev mail log (MAIL_MAILER=log).
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { chromium } from 'playwright-core';

const require = createRequire(import.meta.url);
const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const CHROMIUM = process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium';
const LOG = process.env.MAIL_LOG ?? 'storage/logs/laravel.log';
const php = (...a) => execFileSync('php', ['artisan', ...a], { encoding: 'utf8' }).trim();
const step = (m) => console.log(`✔ ${m}`);
const assert = (c, m) => { if (!c) throw new Error(`ASSERTION FAILED: ${m}`); };

/** The newest emailed link of a kind, read from the log the way a person reads their inbox. */
function emailedToken(kind) {
    const text = readFileSync(LOG, 'utf8').replace(/=\r?\n/g, '').replace(/=3D/g, '=');
    const all = [...text.matchAll(new RegExp(`/${kind}\\?token=([A-Za-z0-9_-]+)`, 'g'))];
    assert(all.length > 0, `no ${kind} link was emailed`);

    return all.at(-1)[1];
}

php('clock:set', '--reset');
php('cache:clear');
const email = `acct+${Date.now()}@example.com`;
const password = 'a-long-enough-passphrase';
const newPassword = 'a-different-long-passphrase';
const invite = php('invite:create', email).split('\n')[0];
const violations = [];

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, locale: 'en-GB', timezoneId: 'Europe/London' });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));

async function audit(name) {
    await page.waitForTimeout(400);
    for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') }).catch(() => undefined);
        const res = await page.evaluate(async () => await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'] } }));
        for (const v of res.violations.filter((x) => ['serious', 'critical'].includes(x.impact))) violations.push(`${name} [${scheme}] ${v.id}: ${v.help} → ${v.nodes[0].target.join(' ')}`);
    }
    await page.emulateMedia({ colorScheme: 'light' });
}

try {
    // Sign up and onboard.
    await page.goto(`${BASE}/sign-up`);
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByLabel(/^Password/).fill(password);
    await page.getByLabel(/Pilot invite code/).fill(invite);
    await page.getByRole('button', { name: 'Create account' }).click();
    await page.getByRole('heading', { name: 'A few quick questions' }).waitFor();
    await page.getByRole('button', { name: 'Save and continue' }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();

    // Verification: a calm, non-blocking banner; the emailed link works once.
    await page.getByText('Confirm your email address.').waitFor();
    await page.getByRole('button', { name: 'Enrol in this roadmap' }).waitFor(); // learning is not blocked
    step('unverified: a banner asks for confirmation, and Today is still fully usable');
    const verify = emailedToken('verify');
    await page.goto(`${BASE}/verify?token=${verify}`);
    await page.getByText('Email confirmed.').waitFor();
    await audit('verify: success');
    await page.goto(`${BASE}/verify?token=${verify}`);
    await page.getByText('That link did not work.').waitFor();
    await page.goto(`${BASE}/today`);
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    assert(await page.getByText('Confirm your email address.').count() === 0, 'the banner is gone once verified');
    step('verification link works once; a reused link is refused; the banner disappears');

    // Forgotten password: same message for any address; the reset link signs out and changes the password.
    await page.goto(`${BASE}/settings`);
    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.getByRole('link', { name: 'Try a sample scenario' }).waitFor();
    await page.goto(`${BASE}/sign-in`);
    await page.getByRole('link', { name: 'Forgot your password?' }).click();
    await page.getByLabel('Email address').fill(email);
    await audit('forgot password');
    await page.getByRole('button', { name: 'Send reset link' }).click();
    await page.getByText(/If that address has a DevStep account/).waitFor();
    const reset = emailedToken('reset');
    await page.goto(`${BASE}/reset?token=${reset}`);
    await audit('reset password');
    await page.getByLabel(/New password/).fill(newPassword);
    await page.getByRole('button', { name: 'Save new password' }).click();
    await page.getByText('Password changed.').waitFor();
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(newPassword);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    step('password reset via emailed link; sign-in works with the new password');

    // Export: a ZIP the signed-in learner can download.
    await page.goto(`${BASE}/settings`);
    await page.getByRole('button', { name: 'Prepare my data' }).click();
    const link = page.getByRole('link', { name: 'Download your data' });
    await link.waitFor();
    await audit('settings: export ready');
    const href = await link.getAttribute('href');
    const res = await ctx.request.get(`${BASE}${href}`);
    const body = await res.body();
    assert(res.ok() && body.subarray(0, 2).toString() === 'PK', 'the export downloads as a ZIP');
    assert(!body.toString('latin1').includes('password_hash'), 'no password hash in the export');
    step(`export downloaded (${body.length} bytes, a ZIP)`);

    // Deletion: needs the password, has a cancellable grace period.
    await page.getByRole('button', { name: 'Delete my account' }).click();
    await page.getByRole('heading', { name: 'Delete your account?' }).waitFor();
    await audit('settings: delete dialog');
    await page.getByLabel('Your password').fill('wrong password');
    await page.getByRole('button', { name: 'Delete in 7 days' }).click();
    await page.getByText(/That password is not right/).waitFor();
    await page.getByLabel('Your password').fill(newPassword);
    await page.getByRole('button', { name: 'Delete in 7 days' }).click();
    await page.getByRole('heading', { name: 'Your account is waiting to be deleted' }).waitFor();
    await audit('pending deletion');
    await page.screenshot({ path: 'e2e/out/15-pending-deletion.png' });
    await page.getByRole('button', { name: 'Cancel the deletion' }).click();
    await page.getByRole('heading', { name: 'Today', exact: true }).waitFor();
    step('deletion needs the password, shows a limited screen, and cancelling restores everything');

    // Delete for real: after the 7-day grace the purge removes the account.
    await page.goto(`${BASE}/settings`);
    await page.getByRole('button', { name: 'Delete my account' }).click();
    await page.getByLabel('Your password').fill(newPassword);
    await page.getByRole('button', { name: 'Delete in 7 days' }).click();
    await page.getByRole('heading', { name: 'Your account is waiting to be deleted' }).waitFor();
    php('clock:set', new Date(Date.now() + 8 * 86400 * 1000).toISOString());
    const out = php('accounts:purge');
    assert(/Purged 1 account/.test(out), `the purge removed the account: ${out}`);
    await page.reload();
    await page.getByText('You are a guest.').waitFor(); // the session is gone with the account
    await page.goto(`${BASE}/sign-in`);
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(newPassword);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByText('That email and password do not match.').waitFor();
    step('after 7 days the account is purged: the session ends and the credentials no longer work');

    assert(errors.length === 0, `no uncaught browser errors: ${errors.join('; ')}`);
    if (violations.length) {
        console.error(`\nAccessibility violations:\n - ${[...new Set(violations)].join('\n - ')}`);
        process.exitCode = 1;
    } else step('axe-core: no serious or critical violations on any account screen, light or dark');
} catch (e) {
    console.error(`\n--- ${page.url()}`);
    console.error((await page.locator('body').innerText().catch(() => '')).slice(0, 700));
    await page.screenshot({ path: 'e2e/out/failure-account.png', fullPage: true }).catch(() => undefined);
    throw e;
} finally {
    php('clock:set', '--reset');
    await browser.close();
}
