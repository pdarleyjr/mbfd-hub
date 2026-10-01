import { expect, type BrowserContext } from '@playwright/test';
import { execFile } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { promisify } from 'node:util';
import { sanitizedTestEnvironment } from './test-environment';

const origin = 'http://127.0.0.1:8127';
const runLogin = promisify(execFile);
const cookieNames = new Set(['laravel_session', 'XSRF-TOKEN']);
type Identity = { userId: number; employeeProfileId: number; authenticated: true };

async function currentIdentity(context: BrowserContext): Promise<Identity> {
  const response = await context.request.get(`${origin}/api/me/context`, {
    headers: { Accept: 'application/json', Origin: origin, Referer: origin + '/' }, maxRedirects: 0,
  });
  expect(response.status(), 'Supported renewal never repairs an invalid existing canonical session').toBe(200);
  expect(response.headers()['content-type']).toMatch(/^application\/json\b/i);
  let payload;
  try { payload = await response.json(); } catch { throw new Error('Canonical context did not contain valid JSON'); }
  expect(payload.session?.authenticated === true).toBe(true);
  expect(Number.isSafeInteger(payload.identity?.user_id) && payload.identity.user_id > 0).toBe(true);
  expect(Number.isSafeInteger(payload.personnel?.employee_profile_id) && payload.personnel.employee_profile_id > 0).toBe(true);
  return { userId: payload.identity.user_id, employeeProfileId: payload.personnel.employee_profile_id, authenticated: true };
}

export async function fixtureSessionRenewal(contexts: BrowserContext[]) {
  expect(contexts.length).toBeGreaterThan(0);
  const password = process.env.PROTECTED_UI_E2E_PASSWORD ?? '';
  expect(password.length >= 24, 'Supported renewal requires the original random disposable fixture password').toBe(true);
  const identity = await currentIdentity(contexts[0]);
  for (const context of contexts.slice(1)) expect(await currentIdentity(context)).toEqual(identity);
  const events: { stage: string; renewedAt: string; method: string; sameCanonicalIdentity: true }[] = [];
  let renewedAt: number | null = null;
  return {
    events,
    async beforeBoundary(stage: string) {
      // API context exposes no deadline; normal sign-in establishes a known issue
      // time. Renew proactively at route boundaries within the unchanged hour.
      if (renewedAt !== null && performance.now() - renewedAt < 30 * 60_000) return;
      for (const context of contexts) expect(await currentIdentity(context)).toEqual(identity);
      const startedAt = performance.now();
      const statePath = resolve(`test-results/protected-ui-auth/canonical-renewal-${process.pid}.json`);
      let facts: Identity;
      try {
        const { stdout } = await runLogin(process.execPath, [resolve('tests/e2e/support/fixture-canonical-login.mjs'), statePath], {
          cwd: process.cwd(), windowsHide: true, timeout: 60_000, maxBuffer: 8_192,
          env: sanitizedTestEnvironment({ PROTECTED_UI_E2E_PASSWORD: password, DEBUG: '', PWDEBUG: '0' }),
        });
        const parsed = JSON.parse(stdout);
        facts = { userId: parsed.userId, employeeProfileId: parsed.employeeProfileId, authenticated: parsed.authenticated };
      } catch { throw new Error('Supported disposable fixture sign-in failed; no session replacement attempted'); }
      expect(facts, 'Normal sign-in retains the original canonical fixture identity').toEqual(identity);
      const state = JSON.parse(readFileSync(statePath, 'utf8')) as Awaited<ReturnType<BrowserContext['storageState']>>;
      const cookies = state.cookies.filter(cookie => cookieNames.has(cookie.name));
      expect(cookies.map(cookie => cookie.name).sort()).toEqual([...cookieNames].sort());
      expect(cookies.every(cookie => cookie.domain === '127.0.0.1' && cookie.path === '/')).toBe(true);
      for (const context of contexts) {
        // Keep all origins/localStorage intact; replace only this local sign-in's
        // two cookies before the next document supplies its matching CSRF token.
        // Dispose the previous document so native polling cannot use its old token.
        for (const page of context.pages()) await page.goto('about:blank');
        for (const cookie of cookies) await context.clearCookies({ name: cookie.name, domain: cookie.domain, path: cookie.path });
        await context.addCookies(cookies);
        expect(await currentIdentity(context), 'Renewed owned context retains its canonical identity').toEqual(identity);
      }
      renewedAt = startedAt;
      events.push({ stage, renewedAt: new Date().toISOString(), method: 'SUPPORTED_CANONICAL_LOGIN', sameCanonicalIdentity: true });
    },
  };
}
