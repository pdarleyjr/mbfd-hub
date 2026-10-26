import { expect, test, type Page } from '@playwright/test';

const html = `<!doctype html><html><body><div id="root"></div>
<script type="module">
import RefreshRuntime from '/@react-refresh';
RefreshRuntime.injectIntoGlobalHook(window);
window.$RefreshReg$ = () => {};
window.$RefreshSig$ = () => (type) => type;
window.__vite_plugin_react_preamble_installed__ = true;
</script><script type="module" src="/fixture.tsx"></script></body></html>`;

async function lobby(page: Page, initialState = 'not_started', full = false, query = '') {
    const current = { state: initialState, full };
    await page.route('**/member-lineup-fixture**', route => route.fulfill({ contentType: 'text/html', body: html }));
    await page.route('**/member-lineup/status', route => route.fulfill({ json: {
        state: current.state,
        session_id: current.state === 'active' ? 'lineup-one' : null,
        started_at: current.state === 'active' ? '2026-08-12T07:00:00-04:00' : null,
        ends_at: current.state === 'active' ? '2026-08-12T07:15:00-04:00' : null,
        ended_at: null,
        scheduled_for: '2026-08-12T07:00:00-04:00',
        server_time: '2026-08-12T06:55:00-04:00',
        full: current.full,
    } }));
    await page.goto(`/member-lineup-fixture${query}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-lineup-state]')).toHaveAttribute('data-lineup-state', initialState);
    return current;
}

async function refresh(page: Page) {
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
}

test('a member explicitly enters the lobby and is admitted when 300 starts, without refreshing', async ({ page }) => {
    await page.addInitScript(() => { Date.now = () => 0; });
    const current = await lobby(page);
    await expect(page.getByRole('status')).toContainText('7:00 AM');
    await expect.poll(() => page.evaluate(() => window.lineupJoinAttempts)).toBe(0);
    await page.getByRole('button', { name: 'Join Morning Lineup', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Cancel waiting' })).toBeVisible();
    current.state = 'starting';
    await refresh(page);
    await expect(page.locator('[data-lineup-state]')).toHaveAttribute('data-lineup-state', 'starting');
    expect(await page.evaluate(() => window.lineupJoinAttempts)).toBe(0);
    current.state = 'active';
    await refresh(page);
    await expect.poll(() => page.evaluate(() => window.lineupJoinAttempts)).toBe(1);
});

test('a full room admits a waiting member after a seat opens', async ({ page }) => {
    const current = await lobby(page, 'active', true);
    await page.getByRole('button', { name: 'Join Morning Lineup', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('waiting for a seat');
    expect(await page.evaluate(() => window.lineupJoinAttempts)).toBe(0);
    current.full = false;
    await refresh(page);
    await expect.poll(() => page.evaluate(() => window.lineupJoinAttempts)).toBe(1);
});

test('opening a live lobby or cancelling a wait never joins', async ({ page }) => {
    const current = await lobby(page, 'active');
    await refresh(page);
    expect(await page.evaluate(() => window.lineupJoinAttempts)).toBe(0);
    current.state = 'not_started';
    await refresh(page);
    await expect(page.locator('[data-lineup-state]')).toHaveAttribute('data-lineup-state', 'not_started');
    await page.getByRole('button', { name: 'Join Morning Lineup', exact: true }).click();
    await page.getByRole('button', { name: 'Cancel waiting' }).click();
    current.state = 'active';
    await refresh(page);
    await expect(page.locator('[data-lineup-state]')).toHaveAttribute('data-lineup-state', 'active');
    expect(await page.evaluate(() => window.lineupJoinAttempts)).toBe(0);
});

test('missing microphone permission clearly prevents admission', async ({ page }) => {
    await lobby(page, 'active', false, '?microphone=denied');
    await expect(page.getByText('Microphone access is needed to join.', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Join Morning Lineup', exact: true })).toHaveAttribute('aria-disabled', 'true');
    expect(await page.evaluate(() => window.lineupJoinAttempts)).toBe(0);
});

test('an ended lineup clears waiting consent; restarting cannot admit the member', async ({ page }) => {
    const current = await lobby(page);
    await page.getByRole('button', { name: 'Join Morning Lineup', exact: true }).click();
    current.state = 'ended';
    await refresh(page);
    await expect(page.getByText('Morning Lineup has ended', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Cancel waiting' })).toHaveCount(0);
    current.state = 'active';
    await refresh(page);
    await expect(page.locator('[data-lineup-state]')).toHaveAttribute('data-lineup-state', 'active');
    expect(await page.evaluate(() => window.lineupJoinAttempts)).toBe(0);
});

test('a token race keeps waiting and retries at a bounded rate', async ({ page }) => {
    await page.clock.install();
    await lobby(page, 'active');
    await page.evaluate(() => { window.lineupKeepWaiting = true; });
    await page.getByRole('button', { name: 'Join Morning Lineup', exact: true }).click();
    await expect.poll(() => page.evaluate(() => window.lineupJoinAttempts)).toBe(1);
    await refresh(page);
    await expect(page.getByRole('button', { name: 'Cancel waiting' })).toBeVisible();
    expect(await page.evaluate(() => window.lineupJoinAttempts)).toBe(1);
    await page.evaluate(() => { window.lineupKeepWaiting = false; });
    await page.clock.fastForward(11_000);
    await refresh(page);
    await expect.poll(() => page.evaluate(() => window.lineupJoinAttempts)).toBe(2);
});

test('an unavailable conference displays its state and does not attempt admission', async ({ page }) => {
    await lobby(page, 'unavailable');
    await expect(page.getByRole('status')).toContainText('Morning Lineup is not available right now');
    await expect(page.getByRole('button', { name: 'Join Morning Lineup', exact: true })).toHaveAttribute('aria-disabled', 'true');
    expect(await page.evaluate(() => window.lineupJoinAttempts)).toBe(0);
});

test('waiting for a full lineup does not carry consent into the next service day', async ({ page }) => {
    const current = await lobby(page, 'active', true);
    await page.getByRole('button', { name: 'Join Morning Lineup', exact: true }).click();
    current.state = 'not_started';
    await refresh(page);
    await expect(page.getByRole('button', { name: 'Cancel waiting' })).toHaveCount(0);
    current.state = 'active';
    current.full = false;
    await refresh(page);
    await expect(page.locator('[data-lineup-state]')).toHaveAttribute('data-lineup-state', 'active');
    expect(await page.evaluate(() => window.lineupJoinAttempts)).toBe(0);
});
