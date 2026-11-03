import { expect, test, type Page, type Request, type Route } from '@playwright/test';
import { expectNoHorizontalOverflow } from './support/no-horizontal-overflow';

// Synthetic fixtures only. Contract: docs/ems/supply-room-kiosk.md.
const each = { key: 'each', label: 'each', plural: 'each', per: 1 };
const item = (id: number, name: string, location: string, extra: Record<string, unknown> = {}) => ({
    id, name, location_code: location, location_label: `Shelf ${location}`, section_label: 'Synthetic', category: 'Synthetic',
    aliases: [], learned_aliases: [], item_numbers: [], units: [each], default_unit: 'each', confirm_above_base: null, popularity: 1, ...extra,
});
const bootstrap = (csrf: string) => ({
    ok: true,
    data: {
        device: { name: 'Synthetic supply room screen' },
        csrf_token: csrf,
        generated_at: '2026-11-03T12:00:00-05:00',
        members: [{ employee_id: '1234', name: 'Lt. J. Smith' }, { employee_id: '0042', name: 'FF A. Jones' }],
        units: [{ id: 12, label: 'R22' }, { id: 2, label: 'R2' }, { id: 44, label: 'E1' }],
        locations: [{ code: 'A2', label: 'Shelf A2', sort: 1 }, { code: 'B3', label: 'Shelf B3', sort: 2 }, { code: 'C1', label: 'Shelf C1', sort: 3 }],
        items: [
            item(1, 'Non-Rebreather Mask Adult', 'A2', { popularity: 30, confirm_above_base: 20 }),
            item(2, 'Nasal Cannula Adult', 'A2', { popularity: 40 }),
            item(41, 'IV Catheter 18g', 'B3', { popularity: 14, aliases: ['angiocath 18'], item_numbers: ['381744'], confirm_above_base: 25,
                units: [each, { key: 'box', label: 'box of 50', plural: 'boxes of 50', per: 50 }] }),
            item(42, 'IV Catheter 20g', 'B3', { popularity: 20 }),
            item(9, 'Tegaderm IV Dressing', 'B3', { popularity: 11 }),
            item(7, 'Gauze Pads 4x4 Sterile', 'C1', { popularity: 28, confirm_above_base: 5 }),
            item(8, 'CAT Tourniquet', 'C1', { popularity: 10 }),
            item(10, 'Abdominal Pad 5x9', 'C1', { popularity: 3 }),
        ],
        most_taken: [2, 1, 41],
        shorthand: { nrb: ['non rebreather', 'non-rebreather'] },
    },
    meta: {},
});

/**
 * How the Hub answers one withdrawal POST: ok (recorded), lost (recorded, but the answer never arrives), abort (never
 * arrives), fail503 (gateway error), refuse (422).
 */
type Answer = 'ok' | 'lost' | 'abort' | 'fail503' | 'refuse';
interface Options {
    unpaired?: boolean;
    withdrawal?: 'ok' | 'csrf-once' | 'offline-first';
    /** Per withdrawal uuid and try (1, 2, ...). Wins over `withdrawal`. */
    script?: (uuid: string, attempt: number) => Answer;
    /** auto: 200 when the Hub recorded it, 404 when it never arrived. abort-once: the first undo gets no answer. closed: 409. */
    undo?: 'auto' | 'abort-once' | 'closed';
    pairing?: 'ok' | 'csrf-once' | 'busy';
}
interface Seen { method: string; path: string; body: any; headers: Record<string, string> }

const PAIR_CODE = 'ABC234';
const REFUSED_MESSAGE = 'That employee ID was not found. Check the number and try again.';
const CLOSED_MESSAGE = 'It is more than 60 seconds since this was taken, so it cannot be undone here. Captain 5 can correct it.';

async function mockApi(page: Page, options: Options = {}) {
    const seen: Seen[] = [];
    let bootstraps = 0;
    let withdrawals = 0;
    let undoTries = 0;
    let pairTries = 0;
    let paired = !options.unpaired;
    /** Bootstrap alone answers 401, as after an admin revokes the screen between heartbeats. */
    let bootstrapRevoked = false;
    const committed = new Set<string>();
    const undone = new Set<string>();
    const tries = new Map<string, number>();
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    // Pairing by code: the code as typed, normalised by the server (uppercase, no spaces or dash).
    await page.route('**/supply-kiosk/pair-code', async (route: Route, request: Request) => {
        pairTries++;
        const body = request.postDataJSON();
        seen.push({ method: request.method(), path: '/pair-code', body, headers: request.headers() });
        const json = (data: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
        if (options.pairing === 'csrf-once' && pairTries === 1) return json({ message: 'CSRF token mismatch.' }, 419);
        if (options.pairing === 'busy') return json({ message: 'Too Many Attempts.' }, 429);
        if (String(body.code).toUpperCase().replace(/[^A-Z0-9]/g, '') !== PAIR_CODE) {
            return json({ ok: false, error: { code: 'pairing_link_ended', message: 'That pairing code has been used or has ended. Make a new one from Admin > Medical Supply Room.' } }, 410);
        }
        paired = true;
        bootstrapRevoked = false;
        return json({ ok: true, data: { device: { name: 'Synthetic supply room screen' } }, meta: {} });
    });
    // After a 419 the screen reloads the kiosk page for a fresh token; the first load (the page itself) goes to the preview server.
    let pageLoads = 0;
    await page.route(/\/supply-kiosk\/?$/, async route => {
        pageLoads++;
        if (pageLoads === 1) return route.continue();
        return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html><head><meta name="csrf-token" content="synthetic-page-csrf"></head><body></body></html>' });
    });
    await page.route('**/supply-kiosk/api/**', async (route: Route, request: Request) => {
        const path = new URL(request.url()).pathname.replace('/supply-kiosk/api', '');
        const body = request.postData() ? request.postDataJSON() : null;
        seen.push({ method: request.method(), path, body, headers: request.headers() });
        const json = (data: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
        const notPaired = () => json({ ok: false, error: { code: 'kiosk_not_paired', message: 'This screen is not paired.' } }, 401);
        if (!paired) return notPaired();
        if (path === '/bootstrap') {
            if (bootstrapRevoked) return notPaired();
            bootstraps++;
            return json(bootstrap(bootstraps === 1 ? 'synthetic-bootstrap-csrf' : 'synthetic-refreshed-csrf'));
        }
        if (path === '/heartbeat') return json({ ok: true, data: { at: '2026-11-03T12:01:00-05:00' }, meta: {} });
        if (path === '/withdrawals') {
            withdrawals++;
            const uuid = String(body.client_uuid);
            const attempt = (tries.get(uuid) ?? 0) + 1;
            tries.set(uuid, attempt);
            if (options.withdrawal === 'csrf-once' && withdrawals === 1) return json({ message: 'CSRF token mismatch.' }, 419);
            const answer: Answer = options.script ? options.script(uuid, attempt) : options.withdrawal === 'offline-first' && withdrawals === 1 ? 'abort' : 'ok';
            if (answer === 'abort') return route.abort('internetdisconnected');
            if (answer === 'fail503') return route.fulfill({ status: 503, contentType: 'text/html', body: '<h1>Service Unavailable</h1>' });
            if (answer === 'refuse') return json({ ok: false, error: { code: 'employee_not_found', message: REFUSED_MESSAGE, fields: { employee_id: ['That employee ID was not found.'] } } }, 422);
            committed.add(uuid);
            if (answer === 'lost') return route.abort('timedout');
            const takenAt = new Date();
            return json({
                ok: true,
                data: {
                    uuid: body.client_uuid, number: 'W-000123', employee_name: 'Lt. J. Smith', unit_label: null,
                    taken_at: takenAt.toISOString(), undo_until: new Date(takenAt.getTime() + 60_000).toISOString(),
                    lines: body.lines.map((line: { supply_item_id: number; quantity: number }) => ({ name: `Item ${line.supply_item_id}`, quantity: line.quantity, unit_label: 'each' })),
                    note: body.note, copy: 'sent',
                },
                meta: { replayed: false },
            }, 201);
        }
        const undoMatch = /^\/withdrawals\/([^/]+)\/undo$/.exec(path);
        if (undoMatch) {
            undoTries++;
            const uuid = decodeURIComponent(undoMatch[1]);
            if (options.undo === 'abort-once' && undoTries === 1) return route.abort('internetdisconnected');
            if (options.undo === 'closed') return json({ ok: false, error: { code: 'undo_window_closed', message: CLOSED_MESSAGE } }, 409);
            if (!committed.has(uuid)) return json({ ok: false, error: { code: 'not_found', message: 'That withdrawal was not found.' } }, 404);
            undone.add(uuid);
            return json({ ok: true, data: { status: 'undone' }, meta: {} });
        }
        return json({ ok: false, error: { code: 'not_found', message: 'Not found.' } }, 404);
    });
    return {
        seen,
        committed,
        undone,
        revokeBootstrap: () => { bootstrapRevoked = true; },
        posts: () => seen.filter(call => call.path === '/withdrawals'),
        postsFor: (uuid: string) => seen.filter(call => call.path === '/withdrawals' && call.body?.client_uuid === uuid),
        pairs: () => seen.filter(call => call.path === '/pair-code'),
        undos: () => seen.filter(call => call.path.endsWith('/undo')),
        bootstraps: () => bootstraps,
        errors,
    };
}

const pad = (page: Page) => page.getByRole('group', { name: 'Number pad' });
async function typeId(page: Page, id: string) {
    for (const digit of id) await pad(page).getByRole('button', { name: digit, exact: true }).click();
}
async function signIn(page: Page) {
    await typeId(page, '1234');
    await expect(page.getByTestId('member-name')).toHaveText('Lt. J. Smith');
    await page.getByRole('button', { name: 'GO', exact: true }).click();
    await page.getByRole('button', { name: 'Search for an item' }).click();
    await expect(page.locator('#k-search-input')).toBeVisible();
}
async function addFromSheet(page: Page, quantity?: string) {
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();
    if (quantity) await sheet.getByRole('group', { name: 'Quick amounts' }).getByRole('button', { name: quantity, exact: true }).click();
    await sheet.getByRole('button', { name: /ADD TO CART/ }).click();
    await expect(sheet).toBeHidden();
}
async function fillCartAndTake(page: Page) {
    await signIn(page);
    await page.locator('#k-search-input').fill('nrb');
    await page.getByRole('button', { name: 'Add Non-Rebreather Mask Adult' }).click();
    await addFromSheet(page);
    await page.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('button', { name: 'TAKE THIS 1 ITEM' }).click();
}

/** Withdrawals left on the screen by an earlier visit (the localStorage copy of the queue). */
async function seedQueue(page: Page, entries: { uuid: string; ageMs: number }[]) {
    const now = Date.now();
    const queue = Object.fromEntries(entries.map(({ uuid, ageMs }) => [uuid, {
        client_uuid: uuid, queued_at: now - ageMs, member_name: 'Lt. J. Smith',
        body: { client_uuid: uuid, employee_id: '1234', unit: null, lines: [{ supply_item_id: 1, quantity: 1, unit: 'each' }], note: null, searches: [] },
    }]));
    await page.addInitScript(value => { window.localStorage.setItem('mbfd.supplyKiosk.queue', value); }, JSON.stringify(queue));
}

/** What is still saved on the screen: queued withdrawals (IndexedDB and localStorage, refused ones included) and undos waiting. */
async function saved(page: Page) {
    return page.evaluate(async () => {
        const idb = await new Promise<{ uuid: string; refused: boolean }[]>(resolve => {
            const open = indexedDB.open('mbfd-supply-kiosk', 1);
            open.onupgradeneeded = () => open.result.createObjectStore('withdrawals', { keyPath: 'client_uuid' });
            open.onsuccess = () => {
                const request = open.result.transaction('withdrawals', 'readonly').objectStore('withdrawals').getAll();
                request.onsuccess = () => resolve(request.result.map((entry: { client_uuid: string; refused?: unknown }) => ({ uuid: entry.client_uuid, refused: !!entry.refused })));
            };
        });
        const local = Object.keys(JSON.parse(window.localStorage.getItem('mbfd.supplyKiosk.queue') ?? '{}'));
        const undos = JSON.parse(window.localStorage.getItem('mbfd.supplyKiosk.undoLater') ?? '[]').map((entry: { uuid: string }) => entry.uuid);
        return { idb, local, undos };
    });
}

const goOnline = (page: Page) => page.evaluate(() => window.dispatchEvent(new Event('online')));

test('an unpaired screen says how to pair it', async ({ page }) => {
    await mockApi(page, { unpaired: true });
    await page.goto('/supply-kiosk');
    await expect(page.getByRole('heading', { name: 'This screen is not paired' })).toBeVisible();
    await expect(page.getByText('An admin makes a pairing code from Admin > Medical Supply Room > Supply room screen > Pair supply room screen.')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Pair this screen' })).toBeVisible();
    await expect(pad(page)).toHaveCount(0);
});

const codeBox = (page: Page) => page.getByLabel('Pairing code');
const pairButton = (page: Page) => page.getByRole('button', { name: 'Pair', exact: true });

test('a wrong pairing code shows the message and the screen stays unpaired', async ({ page }) => {
    const api = await mockApi(page, { unpaired: true });
    await page.goto('/supply-kiosk');
    await expect(codeBox(page)).toHaveAttribute('inputmode', 'text');
    await expect(codeBox(page)).toHaveAttribute('autocapitalize', 'characters');
    await codeBox(page).pressSequentially('abc-2');
    await expect(codeBox(page)).toHaveValue('ABC-2');
    await expect(pairButton(page)).toBeDisabled();
    await codeBox(page).pressSequentially('99');
    await pairButton(page).click();
    await expect(page.getByRole('alert')).toHaveText('That pairing code has been used or has ended. Make a new one from Admin > Medical Supply Room.');
    await expect(pad(page)).toHaveCount(0);
    expect(api.pairs()[0].body).toEqual({ code: 'ABC-299' });
    expect(api.pairs()[0].headers['x-csrf-token']).toBe('synthetic-meta-csrf');
    expect(api.pairs()[0].headers['x-requested-with']).toBe('XMLHttpRequest');
    await expectNoHorizontalOverflow(page);
});

test('the right pairing code pairs the screen and opens the ID screen', async ({ page }) => {
    const api = await mockApi(page, { unpaired: true });
    await page.goto('/supply-kiosk');
    await codeBox(page).fill('abc 234');
    await expect(codeBox(page)).toHaveValue('ABC 234');
    await pairButton(page).click();
    await expect(page.getByRole('heading', { name: 'Type your employee ID' })).toBeVisible();
    expect(api.pairs()).toHaveLength(1);
    expect(api.bootstraps()).toBeGreaterThanOrEqual(1);
    await typeId(page, '1234');
    await expect(page.getByTestId('member-name')).toHaveText('Lt. J. Smith');
});

test('pairing after a 419 takes a fresh token from the page and tries once more', async ({ page }) => {
    const api = await mockApi(page, { unpaired: true, pairing: 'csrf-once' });
    await page.goto('/supply-kiosk');
    await codeBox(page).fill('ABC-234');
    await pairButton(page).click();
    await expect(page.getByRole('heading', { name: 'Type your employee ID' })).toBeVisible();
    const pairs = api.pairs();
    expect(pairs).toHaveLength(2);
    expect(pairs[0].headers['x-csrf-token']).toBe('synthetic-meta-csrf');
    expect(pairs[1].headers['x-csrf-token']).toBe('synthetic-page-csrf');
});

test('too many pairing tries says to wait', async ({ page }) => {
    await mockApi(page, { unpaired: true, pairing: 'busy' });
    await page.goto('/supply-kiosk');
    await codeBox(page).fill('ABC-234');
    await pairButton(page).click();
    await expect(page.getByRole('alert')).toHaveText('Too many tries. Wait a minute and try again.');
});

test('an unknown ID keeps GO disabled; a known one shows the name at once', async ({ page }) => {
    const api = await mockApi(page);
    await page.goto('/supply-kiosk');
    await typeId(page, '9999');
    await expect(page.getByRole('button', { name: 'GO', exact: true })).toBeDisabled();
    await expect(page.getByTestId('member-name')).toHaveText('We do not have that ID. Check the number.');
    await pad(page).getByRole('button', { name: 'Clear' }).click();
    await typeId(page, '42');
    await expect(page.getByTestId('member-name')).toHaveText('FF A. Jones');
    await expect(page.getByRole('button', { name: 'GO', exact: true })).toBeEnabled();
    await expectNoHorizontalOverflow(page);
    expect(api.errors).toEqual([]);
});

test('ID, search, browse, cart, Take, Undo, then back to the start', async ({ page }) => {
    await page.clock.install();
    const api = await mockApi(page);
    await page.goto('/supply-kiosk');
    await expectNoHorizontalOverflow(page);
    await signIn(page);
    await expect(page.getByRole('heading', { name: 'Most taken' })).toBeVisible();

    const search = page.locator('#k-search-input');
    await search.fill('nrb');
    await expect(page.getByRole('list', { name: 'Search results' }).getByRole('listitem').first()).toContainText('Non-Rebreather Mask Adult');
    await page.getByRole('button', { name: 'Add Non-Rebreather Mask Adult' }).click();
    await expect(page.getByRole('dialog').locator('output.k-qty')).toHaveText('1');
    await expectNoHorizontalOverflow(page);
    await addFromSheet(page);
    await expect(page.locator('.k-toast')).toHaveText('Added. Next item?');
    await expect(search).toHaveValue('');
    await expect(search).toBeFocused();

    await search.fill('cathiter');
    await expect(page.getByRole('button', { name: 'Add IV Catheter 18g' })).toBeVisible();
    await page.getByRole('button', { name: 'Add IV Catheter 18g' }).click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('group', { name: 'Count by' }).getByRole('button', { name: 'each' })).toHaveAttribute('aria-pressed', 'true');
    await addFromSheet(page, '2');
    await expectNoHorizontalOverflow(page);

    await page.getByRole('button', { name: 'Entire supply room' }).click();
    await page.getByRole('group', { name: 'Location' }).getByRole('button', { name: 'Shelf C1' }).click();
    await page.getByRole('button', { name: 'Add CAT Tourniquet' }).click();
    await addFromSheet(page);
    await expect(page.getByTestId('cart-count')).toHaveText('3 items in cart');
    await expectNoHorizontalOverflow(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await expect(page.getByRole('list', { name: 'Items in your cart' }).getByRole('listitem')).toHaveCount(3);
    await expectNoHorizontalOverflow(page);
    await page.getByRole('button', { name: 'TAKE THESE 3 ITEMS' }).click();

    await expect(page.getByRole('heading', { name: 'Done', exact: true })).toBeVisible();
    await expect(page.getByText('A copy went to your city email.')).toBeVisible();
    const [post] = api.posts();
    expect(post.method).toBe('POST');
    expect(post.body.client_uuid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(post.body).toMatchObject({
        employee_id: '1234', unit: null, note: null,
        lines: [{ supply_item_id: 1, quantity: 1, unit: 'each' }, { supply_item_id: 41, quantity: 2, unit: 'each' }, { supply_item_id: 8, quantity: 1, unit: 'each' }],
        searches: [{ term: 'nrb', supply_item_id: 1 }, { term: 'cathiter', supply_item_id: 41 }],
    });
    expect(post.headers['x-csrf-token']).toBe('synthetic-bootstrap-csrf');
    expect(post.headers['accept']).toBe('application/json');
    expect(post.headers['x-requested-with']).toBe('XMLHttpRequest');
    await expectNoHorizontalOverflow(page);

    await page.getByRole('button', { name: /^Undo \(\d+ s\)$/ }).click();
    await expect(page.getByRole('heading', { name: 'Put back.', exact: true })).toBeVisible();
    expect(api.undos().map(call => call.path)).toEqual([`/withdrawals/${post.body.client_uuid}/undo`]);

    await page.clock.fastForward(11_000);
    await expect(page.getByRole('heading', { name: 'Type your employee ID' })).toBeVisible();
    await expect(page.getByTestId('cart-count')).toHaveCount(0);
    expect(api.errors).toEqual([]);
});

test('a big quantity needs a second tap', async ({ page }) => {
    await mockApi(page);
    await page.goto('/supply-kiosk');
    await signIn(page);
    await page.locator('#k-search-input').fill('4x4');
    await page.getByRole('button', { name: 'Add Gauze Pads 4x4 Sterile' }).click();
    const sheet = page.getByRole('dialog');
    await sheet.getByRole('group', { name: 'Quick amounts' }).getByRole('button', { name: '10', exact: true }).click();
    await sheet.getByRole('button', { name: /ADD TO CART/ }).click();
    await expect(sheet.getByRole('button', { name: 'Taking 10 each? Tap again to confirm' })).toBeVisible();
    await expect(page.getByTestId('cart-count')).toHaveCount(0);
    await sheet.getByRole('button', { name: 'Taking 10 each? Tap again to confirm' }).click();
    await expect(sheet).toBeHidden();
    await expect(page.getByTestId('cart-count')).toHaveText('1 item in cart');
});

test('60 seconds untouched asks "Still there?" and then clears the cart', async ({ page }) => {
    await page.clock.install();
    await mockApi(page);
    await page.goto('/supply-kiosk');
    await signIn(page);
    await page.locator('#k-search-input').fill('nrb');
    await page.getByRole('button', { name: 'Add Non-Rebreather Mask Adult' }).click();
    await addFromSheet(page);
    await expect(page.getByTestId('cart-count')).toHaveText('1 item in cart');

    await page.clock.fastForward(61_000);
    await expect(page.getByRole('alertdialog', { name: 'Still there?' })).toBeVisible();
    await page.clock.fastForward(11_000);
    await expect(page.getByRole('heading', { name: 'Type your employee ID' })).toBeVisible();
    await signIn(page);
    await expect(page.getByTestId('cart-count')).toHaveCount(0);
});

test('a 419 refreshes the token from bootstrap and the retry succeeds with the same client_uuid', async ({ page }) => {
    const api = await mockApi(page, { withdrawal: 'csrf-once' });
    await page.goto('/supply-kiosk');
    await fillCartAndTake(page);
    await expect(page.getByRole('heading', { name: 'Done', exact: true })).toBeVisible();
    const posts = api.posts();
    expect(posts).toHaveLength(2);
    expect(posts[1].body.client_uuid).toBe(posts[0].body.client_uuid);
    expect(posts[0].headers['x-csrf-token']).toBe('synthetic-bootstrap-csrf');
    expect(posts[1].headers['x-csrf-token']).toBe('synthetic-refreshed-csrf');
    expect(api.bootstraps()).toBe(2);
});

test('offline: the Take is saved on the screen and re-sent with the same client_uuid when back online', async ({ page }) => {
    const api = await mockApi(page, { withdrawal: 'offline-first' });
    await page.goto('/supply-kiosk');
    await fillCartAndTake(page);
    await expect(page.getByRole('heading', { name: 'Done. Waiting to send.' })).toBeVisible();
    await expect(page.getByText('Saved on this screen. It goes to the Hub as soon as the connection is back.')).toBeVisible();
    expect(api.posts()).toHaveLength(1);

    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect.poll(() => api.posts().length).toBe(2);
    const [first, second] = api.posts();
    expect(second.body).toEqual(first.body);
    // Nothing is left waiting once the Hub has it.
    await expect.poll(() => page.evaluate(() => new Promise<number>(resolve => {
        const open = indexedDB.open('mbfd-supply-kiosk');
        open.onsuccess = () => {
            const request = open.result.transaction('withdrawals', 'readonly').objectStore('withdrawals').count();
            request.onsuccess = () => resolve(request.result);
        };
    }))).toBe(0);
});

test('nothing scrolls sideways at 320 px', async ({ page }) => {
    await mockApi(page);
    await page.setViewportSize({ width: 320, height: 640 });
    await page.goto('/supply-kiosk');
    await expectNoHorizontalOverflow(page);
    await typeId(page, '1234');
    await page.getByRole('button', { name: 'GO', exact: true }).click();
    await expectNoHorizontalOverflow(page);
    await page.getByRole('button', { name: 'View entire supply room' }).click();
    await page.getByRole('group', { name: 'Location' }).getByRole('button', { name: 'Shelf B3' }).click();
    await page.getByRole('button', { name: 'Add IV Catheter 18g' }).click();
    await expectNoHorizontalOverflow(page);
    await addFromSheet(page);
    await page.getByRole('button', { name: 'Review' }).click();
    await expectNoHorizontalOverflow(page);
});

// ------------------------------------------------------------------ Undo on a receipt that is waiting to send

test.describe('Undo on a receipt waiting to send', () => {
    test('the Take had landed with its answer lost: Undo asks the Hub, which puts it back', async ({ page }) => {
        const api = await mockApi(page, { script: (_uuid, attempt) => (attempt === 1 ? 'lost' : 'ok') });
        await page.goto('/supply-kiosk');
        await fillCartAndTake(page);
        await expect(page.getByRole('heading', { name: 'Done. Waiting to send.' })).toBeVisible();
        const uuid = api.posts()[0].body.client_uuid;
        await page.getByRole('button', { name: /^Undo \(\d+ s\)$/ }).click();
        await expect(page.getByRole('heading', { name: 'Put back.', exact: true })).toBeVisible();
        expect(api.undos().map(call => call.path)).toEqual([`/withdrawals/${uuid}/undo`]);
        expect(api.undone.has(uuid)).toBe(true);
        expect(await saved(page)).toEqual({ idb: [], local: [], undos: [] });
        // The local copy is gone, so the withdrawal is never sent again after its undo.
        await goOnline(page);
        await page.waitForTimeout(400);
        expect(api.posts()).toHaveLength(1);
    });

    test('the Take never arrived: Undo gets a 404 and says nothing was taken', async ({ page }) => {
        const api = await mockApi(page, { script: (_uuid, attempt) => (attempt === 1 ? 'abort' : 'ok') });
        await page.goto('/supply-kiosk');
        await fillCartAndTake(page);
        await expect(page.getByRole('heading', { name: 'Done. Waiting to send.' })).toBeVisible();
        await page.getByRole('button', { name: /^Undo \(\d+ s\)$/ }).click();
        await expect(page.getByRole('heading', { name: 'Put back. Nothing was taken.' })).toBeVisible();
        expect(api.undos()).toHaveLength(1);
        expect(await saved(page)).toEqual({ idb: [], local: [], undos: [] });
        await goOnline(page);
        await page.waitForTimeout(400);
        expect(api.posts()).toHaveLength(1);
        expect(api.committed.size).toBe(0);
    });

    test('no connection for the undo either: it waits on the screen and is sent when the connection is back', async ({ page }) => {
        const api = await mockApi(page, { script: (_uuid, attempt) => (attempt === 1 ? 'abort' : 'ok'), undo: 'abort-once' });
        await page.goto('/supply-kiosk');
        await fillCartAndTake(page);
        await expect(page.getByRole('heading', { name: 'Done. Waiting to send.' })).toBeVisible();
        const uuid = api.posts()[0].body.client_uuid;
        await page.getByRole('button', { name: /^Undo \(\d+ s\)$/ }).click();
        await expect(page.getByRole('heading', { name: 'Putting it back' })).toBeVisible();
        await expect(page.getByTestId('undo-pending')).toHaveText('No connection right now. This screen puts it back as soon as the connection is back. If the screen stays offline, tell Captain 5.');
        expect(await saved(page)).toEqual({ idb: [], local: [], undos: [uuid] });

        await goOnline(page);
        await expect.poll(() => api.undos().length).toBe(2);
        await expect.poll(async () => (await saved(page)).undos).toEqual([]);
        // Only the undo was retried; the withdrawal it cancels was never sent again.
        expect(api.posts()).toHaveLength(1);
        expect(api.committed.size).toBe(0);
    });

    test('a 409 after the undo window shows the Hub\'s own words', async ({ page }) => {
        await mockApi(page, { undo: 'closed' });
        await page.goto('/supply-kiosk');
        await fillCartAndTake(page);
        await expect(page.getByRole('heading', { name: 'Done', exact: true })).toBeVisible();
        await page.getByRole('button', { name: /^Undo \(\d+ s\)$/ }).click();
        await expect(page.getByRole('alert')).toHaveText(CLOSED_MESSAGE);
        await expect(page.getByRole('heading', { name: 'Done', exact: true })).toBeVisible();
    });
});

// --------------------------------------------------------------- saved withdrawals the Hub refuses or never gets

const REFUSED = 'a1a1a1a1-0000-4000-8000-000000000001';
const STUCK = 'b2b2b2b2-0000-4000-8000-000000000002';
const NEXT = 'c3c3c3c3-0000-4000-8000-000000000003';
const notice = (page: Page) => page.getByTestId('notices').getByRole('listitem');

test.describe('saved withdrawals', () => {
    test('one the Hub refuses stays on the ID screen, is never sent again, and clears once Captain 5 was told', async ({ page }) => {
        await seedQueue(page, [{ uuid: REFUSED, ageMs: 5 * 60_000 }]);
        const api = await mockApi(page, { script: () => 'refuse' });
        await page.goto('/supply-kiosk');
        await expect(notice(page)).toHaveCount(1);
        await expect(notice(page)).toContainText(/^A withdrawal from [A-Z][a-z]{2} \d{1,2}, \d{1,2}:\d{2} [AP]M by Lt\. J\. Smith could not be recorded: That employee ID was not found\. Check the number and try again\. Tell Captain 5\./);
        expect((await saved(page)).idb).toEqual([{ uuid: REFUSED, refused: true }]);

        await goOnline(page);
        await page.waitForTimeout(400);
        expect(api.postsFor(REFUSED)).toHaveLength(1);
        await expectNoHorizontalOverflow(page);

        await notice(page).getByRole('button', { name: 'Captain 5 was told' }).click();
        await expect(page.getByTestId('notices')).toHaveCount(0);
        expect(await saved(page)).toEqual({ idb: [], local: [], undos: [] });
    });

    test('one that keeps failing does not hold up the next one, and backs off on its own', async ({ page }) => {
        await seedQueue(page, [{ uuid: STUCK, ageMs: 10 * 60_000 }, { uuid: NEXT, ageMs: 5 * 60_000 }]);
        const api = await mockApi(page, { script: uuid => (uuid === STUCK ? 'fail503' : 'ok') });
        await page.goto('/supply-kiosk');
        await expect.poll(() => api.committed.has(NEXT)).toBe(true);
        expect(api.postsFor(STUCK)).toHaveLength(1);
        await expect.poll(async () => (await saved(page)).idb).toEqual([{ uuid: STUCK, refused: false }]);

        await goOnline(page);
        await expect.poll(() => api.postsFor(STUCK).length).toBe(2);
        expect(api.postsFor(NEXT)).toHaveLength(1);
        await expect(page.getByTestId('notices')).toHaveCount(0);
    });

    test('one that could not be sent for a day is shown as not recorded', async ({ page }) => {
        await seedQueue(page, [{ uuid: STUCK, ageMs: 25 * 60 * 60_000 }]);
        await mockApi(page, { script: () => 'fail503' });
        await page.goto('/supply-kiosk');
        await expect(notice(page).getByRole('alert')).toHaveText(/^A withdrawal from .+ by Lt\. J\. Smith could not be recorded: The Hub could not be reached\. Tell Captain 5\.$/);
        expect((await saved(page)).idb).toEqual([{ uuid: STUCK, refused: true }]);
    });
});

test('unpaired by the 5-minute refresh mid-order: after pairing again the next member starts empty', async ({ page }) => {
    await page.clock.install();
    const api = await mockApi(page);
    await page.goto('/supply-kiosk');
    await signIn(page);
    await page.locator('#k-search-input').fill('nrb');
    await page.getByRole('button', { name: 'Add Non-Rebreather Mask Adult' }).click();
    await addFromSheet(page);
    await expect(page.getByTestId('cart-count')).toHaveText('1 item in cart');

    api.revokeBootstrap();
    await page.clock.fastForward(5 * 60_000);
    await expect(page.getByRole('heading', { name: 'This screen is not paired' })).toBeVisible();
    await expect(page.getByRole('alertdialog')).toHaveCount(0);

    await page.getByLabel('Pairing code').fill('ABC-234');
    await page.getByRole('button', { name: 'Pair', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Type your employee ID' })).toBeVisible();
    await expect(page.getByTestId('member-name')).toHaveText(' ');
    await typeId(page, '42');
    await page.getByRole('button', { name: 'GO', exact: true }).click();
    await expect(page.locator('.k-bar-member')).toHaveText('FF A. Jones');
    await page.getByRole('button', { name: 'Search for an item' }).click();
    await expect(page.locator('#k-search-input')).toHaveValue('');
    await expect(page.getByTestId('cart-count')).toHaveCount(0);
});
