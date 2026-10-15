import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const id = '01SYNTHETICTRAININGROSTER01';
const members = [
    { id: 1, first_name: 'Synthetic', last_name: 'Alpha', name: 'Synthetic Alpha', rank: 'Firefighter', shift: 'A Shift', term_label: '2026–2027', identifier_hint: '0001' },
    { id: 2, first_name: 'Synthetic', last_name: 'Bravo', name: 'Synthetic Bravo', rank: 'Lieutenant', shift: 'B Shift', term_label: '2026–2027', identifier_hint: '0002' },
];
async function fixture(page: Page, options: { count?: number; lostResponse?: boolean; rejectOnce?: boolean; authorizeFailsOnce?: boolean } = {}) {
    let count = options.count ?? 0;
    let writes = 0;
    let saved = false;
    let firstBody: unknown;
    let authorizations = 0;
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(`**/r/${id}/*`, async route => {
        const path = new URL(route.request().url()).pathname.split('/').pop();
        const json = (data: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
        if (path === 'csrf') return json({ csrf_token: 'synthetic-bootstrap-csrf' });
        if (path === 'authorize') {
            authorizations++;
            expect(route.request().postDataJSON().token).toBe('a'.repeat(64));
            expect(route.request().headers()['x-csrf-token']).toBe('synthetic-bootstrap-csrf');
            if (options.authorizeFailsOnce && authorizations === 1) return route.abort('failed');
            return json({ authorized: true, csrf_token: 'synthetic-refreshed-csrf' });
        }
        if (path === 'state') return json({ course_name: 'Synthetic classroom · Ladder operations', held_on: '2026-10-15', location: 'Training classroom', status: 'open', revision: count + 1, csrf_token: 'synthetic-refreshed-csrf', attendance: Array.from({ length: count }, (_, i) => ({ name: `Synthetic attendee ${i + 1}`, rank: 'Firefighter', shift: 'A Shift', signed_at: '2026-10-15T14:30:00Z' })) });
        if (path === 'candidates') return json({ members });
        if (path === 'receipt') return json({ recorded: saved, status: saved ? 'recorded' : 'unknown' });
        if (path === 'attendance') {
            writes++;
            firstBody ??= route.request().postDataJSON();
            if (options.rejectOnce && writes === 1) return json({ message: 'Signature is required. Please sign before submitting.' }, 422);
            saved = true; count++;
            if (options.lostResponse && writes === 1) return route.abort('failed');
            return json({ recorded: true });
        }
        return json({}, 404);
    });
    await page.goto(`/r/${id}#key=${'a'.repeat(64)}`);
    if (options.authorizeFailsOnce) {
        await expect(page.getByRole('alert')).toBeVisible();
        expect(page.url()).not.toContain('#');
        await page.getByRole('button', { name: 'Check connection' }).click();
    }
    await expect(page.getByRole('button', { name: /SIGN ROSTER/ })).toBeVisible();
    return { writes: () => writes, authorizations: () => authorizations, body: () => firstBody, errors };
}
async function selectAndSign(page: Page) {
    await page.getByRole('button', { name: /SIGN ROSTER/ }).click();
    await expect(page.getByText('Use the shift you recently selected for the 2026–2027 bid, not your previous bid shift.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'A Shift', exact: true }).click();
    await expect(page.getByText(/not your previous bid shift/)).toHaveCount(0);
    await page.getByRole('button', { name: 'Firefighter', exact: true }).click();
    await expect(page.getByRole('button', { name: /Bravo/ })).toHaveCount(0);
    await page.getByRole('button', { name: /Alpha, Synthetic/ }).click();
    await expect(page.getByRole('button', { name: 'Complete sign-in' })).toBeDisabled();
    await draw(page);
}
async function draw(page: Page) {
    const pad = page.locator('canvas');
    await pad.scrollIntoViewIfNeeded();
    const box = (await pad.boundingBox())!;
    if (page.context().browser()?.browserType().name() === 'chromium' && await page.evaluate(() => navigator.maxTouchPoints > 0)) {
        const touch = await page.context().newCDPSession(page);
        await touch.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: box.x + box.width * .1, y: box.y + box.height * .7 }] });
        for (let i = 1; i <= 20; i++) await touch.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: box.x + box.width * (.1 + i * .035), y: box.y + box.height * (i % 2 ? .3 : .7) }] });
        await touch.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await touch.detach();
        return;
    }
    await page.mouse.move(box.x + box.width * .1, box.y + box.height * .7);
    await page.mouse.down();
    for (let i = 1; i <= 20; i++) await page.mouse.move(box.x + box.width * (.1 + i * .035), box.y + box.height * (i % 2 ? .3 : .7));
    await page.mouse.up();
}

test('blank home does not invent a course', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'No roster is open on this screen' })).toBeVisible();
    await expect(page.getByRole('button', { name: /SIGN ROSTER/ })).toHaveCount(0);
});

test('failed launch can reconnect without losing the private launch key', async ({ page }) => {
    const proof = await fixture(page, { authorizeFailsOnce: true });
    expect(proof.authorizations()).toBe(2);
    expect(page.url()).not.toContain('#');
});

test('responsive signature workflow clears prior member and keeps action visible for large classes', async ({ page }, info) => {
    const proof = await fixture(page, { count: 150 });
    const button = page.getByRole('button', { name: /SIGN ROSTER/ });
    await page.locator('.attendance-list').evaluate(el => { el.scrollTop = el.scrollHeight; });
    await expect(button).toBeInViewport();
    const accessibility = await new AxeBuilder({ page }).analyze();
    expect(accessibility.violations).toEqual([]);
    await page.screenshot({ path: info.outputPath('active-roster.png'), fullPage: true });
    await selectAndSign(page);
    await page.getByRole('button', { name: 'Clear signature' }).click();
    await expect(page.getByRole('button', { name: 'Complete sign-in' })).toBeDisabled();
    await draw(page);
    await page.screenshot({ path: info.outputPath('signature.png'), fullPage: true });
    await page.getByRole('button', { name: 'Complete sign-in' }).click();
    await expect(page.getByRole('heading', { name: 'Roster signed successfully' })).toBeVisible();
    await expect(page.getByText('Synthetic Alpha', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /SIGN ROSTER/ })).toBeVisible({ timeout: 6000 });
    expect(proof.writes()).toBe(1);
    expect(proof.body()).toMatchObject({ employee_profile_id: 1, signature: expect.stringMatching(/^data:image\/png;base64,/), client_submission_id: expect.any(String) });
    expect(proof.errors).toEqual([]);
    expect(page.url()).not.toContain('#');
});

test('lost submission response recovers receipt without duplicate write', async ({ page }) => {
    const proof = await fixture(page, { lostResponse: true });
    await selectAndSign(page);
    await page.getByRole('button', { name: 'Complete sign-in' }).click();
    await expect(page.getByRole('heading', { name: 'Roster signed successfully' })).toBeVisible();
    expect(proof.writes()).toBe(1);
});

test('definitive validation rejection allows clearing and resubmitting signature', async ({ page }) => {
    const proof = await fixture(page, { rejectOnce: true });
    await selectAndSign(page);
    await page.getByRole('button', { name: 'Complete sign-in' }).click();
    await expect(page.getByRole('alert')).toContainText('Signature is required');
    await page.getByRole('button', { name: 'Clear signature' }).click();
    await draw(page);
    await page.getByRole('button', { name: 'Complete sign-in' }).click();
    await expect(page.getByRole('heading', { name: 'Roster signed successfully' })).toBeVisible();
    expect(proof.writes()).toBe(2);
});
