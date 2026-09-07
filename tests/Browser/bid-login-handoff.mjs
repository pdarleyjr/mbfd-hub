import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { test } from 'node:test';
import { chromium, webkit } from '@playwright/test';

const template = readFileSync(new URL('../../resources/views/auth/bid-handoff.blade.php', import.meta.url), 'utf8');
const securityHeaders = readFileSync(new URL('../../app/Http/Middleware/SecurityHeaders.php', import.meta.url), 'utf8');
assert.ok(securityHeaders.includes('"form-action \'self\'"'));
const policy = "default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'self'; object-src 'none'";
const escape = value => value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');

// Synthetic HTTP/session boundaries only. Real controller, allowlist, code
// redemption, and denial behavior are exercised by CanonicalAuthorizationTest.
async function fixture(legacy, denied = false) {
  let authenticated = false;
  let csrf = 'synthetic-initial-token';
  let bidOrigin;
  const bidServer = createServer((req, res) => {
    res.setHeader('Content-Type', 'text/html');
    const url = new URL(req.url, bidOrigin);
    assert.equal(url.searchParams.get('state'), 'synthetic-state');
    res.end(denied ? '<h1>Bid access denied</h1>' : '<h1>Bid dashboard</h1>');
  });
  const hubServer = createServer((req, res) => {
    res.setHeader('Content-Security-Policy', policy);
    res.setHeader('Cache-Control', 'no-store, private');
    res.setHeader('Content-Type', 'text/html');
    if (req.url === '/login' && req.method === 'GET') {
      res.end(`<form method="POST" action="/login"><input name="_token" type="hidden" value="${csrf}"><button>Sign in</button></form>`);
    } else if (req.url === '/login' && req.method === 'POST') {
      let body = '';
      req.on('data', chunk => { body += chunk; });
      req.on('end', () => {
        if (new URLSearchParams(body).get('_token') !== csrf) {
          res.writeHead(419); res.end('<h1>419 Page Expired</h1>'); return;
        }
        authenticated = true;
        csrf = 'synthetic-regenerated-token';
        res.writeHead(302, { Location: '/auth/bid/authorize' }); res.end();
      });
    } else if (req.url === '/auth/bid/authorize' && authenticated) {
      const destination = `${bidOrigin}/callback?${denied ? 'error=access_denied' : 'code=synthetic-code'}&state=synthetic-state`;
      if (legacy) {
        res.writeHead(302, { Location: destination }); res.end();
      } else {
        res.end(template.replaceAll('{{ $destination }}', escape(destination)));
      }
    } else { res.writeHead(401); res.end(); }
  });
  for (const server of [bidServer, hubServer]) {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  }
  bidOrigin = `http://127.0.0.1:${bidServer.address().port}`;
  return {
    hubOrigin: `http://127.0.0.1:${hubServer.address().port}`,
    bidOrigin,
    authenticated: () => authenticated,
    close: () => Promise.all([hubServer, bidServer].map(server => new Promise(resolve => server.close(resolve)))),
  };
}

test('legacy redirect reproduces successful authentication, blocked navigation, and second-click 419', async () => {
  const app = await fixture(true);
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    const violation = page.waitForEvent('console', { predicate: message => message.text().includes('form-action') });
    await page.goto(`${app.hubOrigin}/login`);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await violation;
    assert.equal(app.authenticated(), true);
    assert.equal(page.url(), `${app.hubOrigin}/login`);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByRole('heading', { name: '419 Page Expired' }).waitFor();
  } finally { await browser.close(); await app.close(); }
});

for (const [name, engine] of [['Chromium', chromium], ['WebKit', webkit]]) {
  for (const denied of [false, true]) {
    test(`${name}: one password submission completes the ${denied ? 'denied' : 'accepted'} Bid handoff under form-action self`, async () => {
      const app = await fixture(false, denied);
      const browser = await engine.launch();
      try {
        const page = await browser.newPage({ javaScriptEnabled: false });
        const violations = [];
        page.on('console', message => { if (message.text().includes('form-action')) violations.push(message.text()); });
        await page.goto(`${app.hubOrigin}/login`);
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.getByRole('heading', { name: denied ? 'Bid access denied' : 'Bid dashboard' }).waitFor();
        assert.equal(new URL(page.url()).origin, app.bidOrigin);
        assert.deepEqual(violations, []);
        const replay = await page.request.post(`${app.hubOrigin}/login`, { form: { _token: 'synthetic-initial-token' } });
        assert.equal(replay.status(), 419);
      } finally { await browser.close(); await app.close(); }
    });
  }
}
