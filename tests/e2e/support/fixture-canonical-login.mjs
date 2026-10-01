import assert from 'node:assert/strict';
import { existsSync, mkdirSync } from 'node:fs';
import { basename, dirname, isAbsolute, relative, resolve } from 'node:path';
import { chromium } from 'playwright';

const origin = 'http://127.0.0.1:8127';

async function login() {
  assert(!existsSync('.env') && !existsSync('.env.testing'));
  const statePath = resolve(process.argv[2] ?? '');
  const withinAuth = relative(resolve('test-results/protected-ui-auth'), statePath);
  assert(withinAuth && !withinAuth.startsWith('..') && !isAbsolute(withinAuth));
  assert(/^canonical-renewal-\d+\.json$/.test(basename(statePath)));
  const password = process.env.PROTECTED_UI_E2E_PASSWORD;
  assert(typeof password === 'string' && password.length >= 24);
  const browser = await chromium.launch({ headless: true });
  try {
    // Standalone process: password input and cookie values never enter test traces.
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, serviceWorkers: 'block' });
    await context.route('**/*', route => {
      const request = route.request(), url = new URL(request.url());
      const allowed = url.origin === origin && (['GET', 'HEAD', 'OPTIONS'].includes(request.method())
        || (request.method() === 'POST' && url.pathname === '/login'));
      return allowed ? route.continue() : route.abort('blockedbyclient');
    });
    const page = await context.newPage();
    const response = await page.goto(`${origin}/login`, { waitUntil: 'domcontentloaded', timeout: 15_000 });
    assert.equal(response?.status(), 200);
    assert.equal(page.url(), `${origin}/login`);
    await page.getByLabel('Employee ID or email').fill('99871', { timeout: 15_000 });
    await page.getByLabel('Password', { exact: true }).fill(password, { timeout: 15_000 });
    await page.getByRole('button', { name: 'Sign in', exact: true }).click({ timeout: 15_000 });
    await page.waitForURL(`${origin}/`, { timeout: 15_000 });
    const canonical = await context.request.get(`${origin}/api/me/context`, {
      headers: { Accept: 'application/json', Origin: origin, Referer: `${origin}/` }, maxRedirects: 0,
    });
    assert.equal(canonical.status(), 200);
    assert.match(canonical.headers()['content-type'], /^application\/json\b/i);
    const payload = await canonical.json();
    assert.equal(payload.session?.authenticated, true);
    assert(Number.isSafeInteger(payload.identity?.user_id) && payload.identity.user_id > 0);
    assert(Number.isSafeInteger(payload.personnel?.employee_profile_id) && payload.personnel.employee_profile_id > 0);
    mkdirSync(dirname(statePath), { recursive: true });
    await context.storageState({ path: statePath });
    return { userId: payload.identity.user_id, employeeProfileId: payload.personnel.employee_profile_id, authenticated: true };
  } finally {
    await browser.close();
  }
}

login().then(facts => process.stdout.write(JSON.stringify(facts))).catch(() => {
  // Playwright failure messages can contain form values. Emit no caught payload.
  process.stderr.write('Supported disposable fixture sign-in failed.\n');
  process.exitCode = 1;
});
