import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { test } from 'node:test';
import { chromium } from '@playwright/test';

const handler = readFileSync(new URL('../../resources/views/filament/partials/session-expiry.blade.php', import.meta.url), 'utf8');
const livewire = readFileSync(new URL('../../vendor/livewire/livewire/dist/livewire.js', import.meta.url));

// Actual installed Livewire client + actual shared handler, synthetic HTTP errors.
// PHP tests separately exercise canonical middleware, all panels and real CSRF.
for (const status of [401, 419]) {
    test(`installed Livewire ${status} response leaves once without confirm, error iframe or form replay`, async () => {
        const navigations = [];
        let updates = 0;
        const snapshot = JSON.stringify({ data: { count: 0 }, memo: {
            id: 'expiry-test', name: 'expiry-test', path: 'admin', method: 'GET',
            children: {}, scripts: [], assets: [], errors: [], locale: 'en',
        }, checksum: 'synthetic-http-only-fixture' });
        const server = createServer((req, res) => {
            res.setHeader('Cache-Control', 'no-store');
            if (req.url === '/livewire.js') {
                res.setHeader('Content-Type', 'application/javascript'); res.end(livewire); return;
            }
            res.setHeader('Content-Type', 'text/html');
            if (req.url.startsWith('/login')) {
                navigations.push(req.url);
                res.end('<h1>Sign in again</h1>'); return;
            }
            if (req.url === '/livewire/update') {
                updates++;
                req.resume();
                res.writeHead(status); res.end('<h1>Raw error must never become a modal</h1>'); return;
            }
            res.end(`<!doctype html><html><head><meta name="csrf-token" content="synthetic-token">${handler}</head><body>
                <div wire:id="expiry-test" wire:snapshot='${snapshot}' wire:effects="{}">
                    <input type="password" value="synthetic-sensitive-password">
                    <textarea>synthetic-private-form</textarea>
                    <button wire:click="$refresh">Poll now</button>
                </div>
                <script src="/livewire.js" data-csrf="synthetic-token" data-update-uri="/livewire/update"></script>
                </body></html>`);
        });
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const origin = `http://127.0.0.1:${server.address().port}`;
        const browser = await chromium.launch();
        try {
            const page = await browser.newPage();
            const dialogs = [];
            const errors = [];
            page.on('dialog', async dialog => { dialogs.push(dialog.message()); await dialog.dismiss(); });
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(`${origin}/admin`);
            await page.getByRole('button', { name: 'Poll now' }).click();
            await page.waitForURL(`${origin}/login?session_expired=1`);
            assert.deepEqual(navigations, ['/login?session_expired=1']);
            assert.equal(updates, 1);
            assert.deepEqual(dialogs, []);
            assert.deepEqual(errors, []);
            assert.equal(await page.locator('iframe').count(), 0);
            assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0);
            assert.equal(await page.getByRole('heading', { name: 'Sign in again' }).count(), 1);
        } finally {
            await browser.close();
            server.closeAllConnections();
            await new Promise(resolve => server.close(resolve));
        }
    });
}
