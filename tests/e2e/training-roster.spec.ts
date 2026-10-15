import { test, expect, Page, Route } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const id = '01SYNTHETICTRAININGROSTER01';
const members = [
    { id: 1, first_name: 'Synthetic', last_name: 'Alpha', name: 'Synthetic Alpha', rank: 'Firefighter', shift: 'A Shift', term_label: '2026–2027', identifier_hint: '0001' },
    { id: 2, first_name: 'Synthetic', last_name: 'Bravo', name: 'Synthetic Bravo', rank: 'Lieutenant', shift: 'B Shift', term_label: '2026–2027', identifier_hint: '0002' },
];
async function fixture(page: Page, options: { count?: number; lostResponse?: boolean; rejectOnce?: boolean; authorizeFailsOnce?: boolean; publicOpen?: boolean } = {}) {
    let count = options.count ?? 0;
    let writes = 0;
    let saved = false;
    let firstBody: unknown;
    let authorizations = 0;
    let opened = false;
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(`**/r/${id}/*`, async route => {
        const path = new URL(route.request().url()).pathname.split('/').pop();
        const json = (data: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
        if (path === 'csrf') return json({ csrf_token: 'synthetic-bootstrap-csrf' });
        if (path === 'open') { opened = true; return json({ authorized: true, csrf_token: 'synthetic-refreshed-csrf' }); }
        if (path === 'authorize') {
            authorizations++;
            expect(route.request().postDataJSON().token).toBe('a'.repeat(64));
            expect(route.request().headers()['x-csrf-token']).toBe('synthetic-bootstrap-csrf');
            if (options.authorizeFailsOnce && authorizations === 1) return route.abort('failed');
            return json({ authorized: true, csrf_token: 'synthetic-refreshed-csrf' });
        }
        if (path === 'state') {
            if (options.publicOpen && !opened) return json({ message: 'Open this roster first.' }, 403);
            return json({ course_name: 'Synthetic classroom · Ladder operations', held_on: '2026-10-15', location: 'Training classroom', status: 'open', revision: count + 1, csrf_token: 'synthetic-refreshed-csrf', attendance: Array.from({ length: count }, (_, i) => ({ name: `Synthetic attendee ${i + 1}`, rank: 'Firefighter', shift: 'A Shift', signed_at: '2026-10-15T14:30:00Z' })) });
        }
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
    await page.goto(options.publicOpen ? `/r/${id}` : `/r/${id}#key=${'a'.repeat(64)}`);
    if (options.authorizeFailsOnce) {
        await expect(page.getByRole('alert')).toBeVisible();
        expect(page.url()).not.toContain('#');
        await page.getByRole('button', { name: 'Check connection' }).click();
    }
    await expect(page.getByRole('button', { name: /SIGN ROSTER/ })).toBeVisible();
    return { writes: () => writes, authorizations: () => authorizations, opened: () => opened, body: () => firstBody, errors };
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

test('empty public catalog does not invent a course', async ({ page }) => {
    await page.route('**/catalog', route => route.fulfill({ json: { courses: [], today: '2026-10-15', csrf_token: 'test' } }));
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'No courses are available' })).toBeVisible();
    await expect(page.getByRole('button', { name: /SIGN ROSTER/ })).toHaveCount(0);
});

test('public catalog searches courses and starts today without exposing attendance', async ({ page }, info) => {
    let starts = 0;
    await page.route('**/catalog', route => route.fulfill({ json: { courses: [{ id: 1, name: 'Primary Screener', classifications: ['STC', 'Instructor-defined topic'], default_location: 'Training classroom', sessions: [{ public_id: id, held_on: '2026-10-15', location: 'Training classroom' }] }, { id: 2, name: 'Marine operations', classifications: ['Marine Training'], default_location: null, sessions: [] }], today: '2026-10-15', csrf_token: 'test' } }));
    await page.route('**/csrf', route => route.fulfill({ json: { csrf_token: 'fresh' } }));
    await page.route('**/courses/1/start', route => { starts++; expect(route.request().method()).toBe('POST'); expect(route.request().headers()['x-csrf-token']).toBe('fresh'); return route.fulfill({ json: { url: `/r/${id}`, csrf_token: 'opened' } }); });
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Choose your class' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Marine operations' })).toBeVisible();
    await page.getByLabel('Find a course').fill('primary');
    await expect(page.getByRole('heading', { name: 'Marine operations' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Primary Screener' })).toBeVisible();
    await page.getByLabel('Find a course').fill('instructor-defined');
    await expect(page.getByRole('heading', { name: 'Primary Screener' })).toBeVisible();
    await page.getByLabel('Find a course').fill('stc');
    await expect(page.getByRole('heading', { name: 'Primary Screener' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Marine operations' })).toHaveCount(0);
    await expect(page.getByRole('list', { name: 'Classifications' }).getByText('STC', { exact: true })).toBeVisible();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({ path: info.outputPath('public-classes.png'), fullPage: true });
    await page.getByRole('button', { name: /Start today’s roster/ }).click();
    await expect(page).toHaveURL(new RegExp(`/r/${id}$`));
    expect(starts).toBe(1);
});

test('public direct roster opens an isolated device without a launch key', async ({ page }) => {
    const proof = await fixture(page, { publicOpen: true, count: 1 });
    expect(proof.opened()).toBe(true);
    expect(proof.authorizations()).toBe(0);
    await expect(page.locator('time')).toContainText('Oct 15, 2026, 10:30 AM EDT');
});

test('ambiguous class start explains how to choose the dated roster', async ({ page }) => {
    await page.route('**/catalog', route => route.fulfill({ json: { courses: [{ id: 1, name: 'Ladder operations', classifications: [], default_location: null, sessions: [{ public_id: id, held_on: '2026-10-15', location: 'Station 1' }, { public_id: `${id}2`, held_on: '2026-10-15', location: 'Station 2' }] }], today: '2026-10-15', csrf_token: 'test' } }));
    await page.route('**/csrf', route => route.fulfill({ json: { csrf_token: 'fresh' } }));
    await page.route('**/courses/1/start', route => route.fulfill({ status: 422, json: { errors: { session: ['More than one roster is open for this class today. Choose the dated roster and location from the class list.'] } } }));
    await page.goto('/');
    await page.getByRole('button', { name: /Start today’s roster/ }).click();
    await expect(page.getByRole('alert')).toContainText('Choose the dated roster and location');
    await expect(page.getByRole('button', { name: /Open roster for Ladder operations/ })).toHaveCount(2);
});

test('catalog failure can recover and an existing dated roster opens by POST', async ({ page }) => {
    let reads = 0;
    let opens = 0;
    await page.route('**/catalog', route => {
        reads++;
        return reads === 1 ? route.abort('failed') : route.fulfill({ json: { courses: [{ id: 1, name: 'Ladder operations', classifications: [], default_location: null, sessions: [{ public_id: id, held_on: '2026-10-15', location: null }] }], today: '2026-10-15', csrf_token: 'test' } });
    });
    await page.route('**/csrf', route => route.fulfill({ json: { csrf_token: 'fresh' } }));
    await page.route(`**/r/${id}/open`, route => { opens++; expect(route.request().method()).toBe('POST'); return route.fulfill({ json: { authorized: true } }); });
    await page.goto('/');
    await expect(page.getByRole('alert')).toContainText('Classes could not be refreshed');
    await page.getByRole('button', { name: 'Refresh classes' }).click();
    await page.getByRole('button', { name: /Open roster for Ladder operations/ }).click();
    await expect(page).toHaveURL(new RegExp(`/r/${id}$`));
    expect(opens).toBe(1);
});

test('roster approval clears credentials and confirms before returning to classes', async ({ page }, info) => {
    await fixture(page, { count: 3 });
    await page.route(`**/r/${id}/submit`, route => {
        expect(route.request().postDataJSON()).toMatchObject({ email: '12345', password: 'synthetic-password', expected_revision: 4, client_submission_id: expect.any(String) });
        return route.fulfill({ json: { submitted: true, held_on: '2026-10-15', course_name: 'Ladder operations', submitted_at: '2026-10-15T14:35:00Z' } });
    });
    await page.getByRole('button', { name: 'Submit roster', exact: true }).click();
    await expect(page.getByText('3 members signed', { exact: true })).toBeVisible();
    await page.getByLabel('Email or employee ID').fill('12345');
    await page.getByLabel('Hub password', { exact: true }).fill('synthetic-password');
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({ path: info.outputPath('approve-roster.png'), fullPage: true });
    await page.getByRole('button', { name: 'Approve and submit' }).click();
    await expect(page.getByRole('heading', { name: 'Roster submitted', exact: true })).toBeVisible();
    await expect(page.getByLabel('Hub password', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => JSON.stringify({ ...sessionStorage, ...localStorage }))).not.toContain('synthetic-password');
    await expect(page).toHaveURL(/\/$/, { timeout: 9000 });
});

test('unconfirmed approval keeps request identity and requires fresh credentials to retry', async ({ page }) => {
    await fixture(page, { count: 1 });
    const attempts: Record<string, unknown>[] = [];
    await page.route(`**/r/${id}/submit`, route => {
        attempts.push(route.request().postDataJSON());
        return attempts.length === 1 ? route.abort('failed') : route.fulfill({ json: { submitted: true } });
    });
    await page.getByRole('button', { name: 'Submit roster', exact: true }).click();
    await page.getByLabel('Email or employee ID').fill('synthetic@example.test');
    await page.getByLabel('Hub password', { exact: true }).fill('synthetic-password');
    await page.getByRole('button', { name: 'Approve and submit' }).click();
    await expect(page.getByRole('alert')).toContainText('Submission could not be confirmed');
    await expect(page.getByRole('heading', { name: 'Roster submitted', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Hub password', { exact: true })).toHaveValue('');
    await expect(page.getByRole('button', { name: 'Return to roster' })).toBeDisabled();
    await page.getByLabel('Hub password', { exact: true }).fill('synthetic-password');
    await page.getByRole('button', { name: 'Retry submission' }).click();
    await expect(page.getByRole('heading', { name: 'Roster submitted', exact: true })).toBeVisible();
    expect(attempts).toHaveLength(2);
    expect(attempts[1]).toEqual(attempts[0]);
});

test('denied approval stays private and allows credential correction', async ({ page }) => {
    await fixture(page, { count: 1 });
    await page.route(`**/r/${id}/submit`, route => route.fulfill({ status: 403, json: { message: 'Sensitive internal detail that must not be displayed' } }));
    await page.getByRole('button', { name: 'Submit roster', exact: true }).click();
    await page.getByLabel('Email or employee ID').fill('synthetic@example.test');
    await page.getByLabel('Hub password', { exact: true }).fill('synthetic-password');
    await page.getByRole('button', { name: 'Approve and submit' }).click();
    await expect(page.getByRole('alert')).toContainText('Check your authorized Hub credentials');
    await expect(page.getByText('Sensitive internal detail that must not be displayed')).toHaveCount(0);
    await expect(page.getByLabel('Hub password', { exact: true })).toHaveValue('');
    await expect(page.getByRole('button', { name: 'Return to roster' })).toBeEnabled();
});

test('uncertain approval recovers for review only after an authoritative stale revision rejection', async ({ page }) => {
    await fixture(page, { count: 1 });
    let attempts = 0;
    await page.route(`**/r/${id}/submit`, route => {
        attempts++;
        return attempts === 1 ? route.abort('failed') : route.fulfill({ status: 422, json: { errors: { expected_revision: ['Attendance changed.'] } } });
    });
    await page.getByRole('button', { name: 'Submit roster', exact: true }).click();
    await page.getByLabel('Email or employee ID').fill('synthetic@example.test');
    await page.getByLabel('Hub password', { exact: true }).fill('synthetic-password');
    await page.getByRole('button', { name: 'Approve and submit' }).click();
    await expect(page.getByRole('button', { name: 'Return to roster' })).toBeDisabled();
    await page.getByLabel('Hub password', { exact: true }).fill('synthetic-password');
    await page.getByRole('button', { name: 'Retry submission' }).click();
    await expect(page.getByRole('alert')).toContainText('Attendance changed before approval');
    await page.getByRole('button', { name: 'Return to roster' }).click();
    await expect(page.getByRole('button', { name: /SIGN ROSTER/ })).toBeVisible();
});

test('reload after a lost approval response recovers the same request without storing people or credentials', async ({ page }) => {
    await fixture(page, { count: 2 });
    const attempts: Record<string, unknown>[] = [];
    await page.route(`**/r/${id}/submit`, route => {
        attempts.push(route.request().postDataJSON());
        return attempts.length === 1 ? route.abort('failed') : route.fulfill({ json: { submitted: true } });
    });
    await page.getByRole('button', { name: 'Submit roster', exact: true }).click();
    await page.getByLabel('Email or employee ID').fill('synthetic@example.test');
    await page.getByLabel('Hub password', { exact: true }).fill('synthetic-password');
    await page.getByRole('button', { name: 'Approve and submit' }).click();
    await expect(page.getByRole('alert')).toContainText('Submission could not be confirmed');
    const stored = await page.evaluate(() => JSON.stringify({ ...sessionStorage, ...localStorage }));
    expect(stored).not.toContain('synthetic-password');
    expect(stored).not.toContain('synthetic@example.test');
    expect(stored).not.toContain('Synthetic attendee');
    expect(stored).not.toContain('signature');
    await page.route(`**/r/${id}/state`, route => route.fulfill({ status: 403, json: { message: 'Roster is no longer open.' } }));
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Confirm roster submission' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Roster submitted', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Email or employee ID')).toHaveValue('');
    await expect(page.getByLabel('Hub password', { exact: true })).toHaveValue('');
    await page.getByLabel('Email or employee ID').fill('synthetic@example.test');
    await page.getByLabel('Hub password', { exact: true }).fill('synthetic-password');
    await page.getByRole('button', { name: 'Retry submission' }).click();
    await expect(page.getByRole('heading', { name: 'Roster submitted', exact: true })).toBeVisible();
    expect(attempts).toHaveLength(2);
    expect(attempts[1]).toEqual(attempts[0]);
    expect(await page.evaluate(() => Object.keys(sessionStorage).some(key => key.endsWith('.approval')))).toBe(false);
});

test('a previous roster poll cannot dismiss an in-flight approval confirmation', async ({ page }) => {
    await page.clock.install();
    await fixture(page, { count: 1 });
    let poll: Route | undefined;
    await page.route(`**/r/${id}/state`, route => {
        if (!poll) { poll = route; return; }
        return route.fulfill({ json: { course_name: 'Synthetic classroom', held_on: '2026-10-15', location: null, status: 'open', revision: 2, csrf_token: 'test', attendance: [{ name: 'Synthetic attendee', rank: 'Firefighter', shift: 'A Shift', signed_at: '2026-10-15T14:30:00Z' }] } });
    });
    await page.clock.fastForward(15000);
    await expect.poll(() => Boolean(poll)).toBe(true);
    await page.getByRole('button', { name: 'Submit roster', exact: true }).click();
    await page.route(`**/r/${id}/submit`, async route => {
        await poll!.fulfill({ status: 403, json: { message: 'Roster is no longer open.' } });
        await new Promise(resolve => setTimeout(resolve, 100));
        await route.fulfill({ json: { submitted: true } });
    });
    await page.getByLabel('Email or employee ID').fill('synthetic@example.test');
    await page.getByLabel('Hub password', { exact: true }).fill('synthetic-password');
    await page.getByRole('button', { name: 'Approve and submit' }).click();
    await expect(page.getByRole('heading', { name: 'Roster submitted', exact: true })).toBeVisible();
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

test('member receipt still confirms saved attendance after the instructor closes the roster', async ({ page }) => {
    await fixture(page);
    await page.evaluate(({ key, id }) => sessionStorage.setItem(key, JSON.stringify({ id, at: Date.now() })), { key: `mbfd.training.pending./r/${id}`, id: '10000000-0000-4000-8000-000000000001' });
    await page.route(`**/r/${id}/state`, route => route.fulfill({ status: 403, json: { message: 'Roster is no longer open.' } }));
    await page.route(`**/r/${id}/open`, route => route.fulfill({ status: 403, json: {} }));
    await page.route(`**/r/${id}/receipt`, route => route.fulfill({ json: { recorded: true, status: 'recorded' } }));
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Roster signed successfully' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'No roster is open on this screen' })).toHaveCount(0);
    await expect(page.locator('.attendance-list')).toHaveCount(0);
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
