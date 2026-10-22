import { expect, test, type Page, type Route } from '@playwright/test';
import type { CommandPost } from '../../resources/js/daily-checkout/src/components/command-communications/types';

/**
 * 300 Command communications, with every API response mocked. The server contract is
 * /api/command-communications (posts, summary, posts/{id}/pin, read).
 */

const BASE = '/api/command-communications';
const PNG_1X1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

const minutesAgo = (minutes: number): string => new Date(Date.now() - minutes * 60_000).toISOString();

function makePost(id: string, overrides: Partial<CommandPost> = {}): CommandPost {
  return {
    id,
    parent_id: null,
    subject: null,
    body: `Message ${id}`,
    author: { name: 'Alex Rivera', rank: 'Captain', side: 'command' },
    is_mine: false,
    created_at: minutesAgo(30),
    pinned: false,
    attachments: [],
    replies: [],
    ...overrides,
  };
}

interface ServerState {
  /** Newest first. */
  posts: CommandPost[];
  unread: number;
  capability: boolean | undefined;
  overviewStatus: number;
  postsStatus: number;
  created: number;
  requests: string[];
  submissions: string[];
  parents: Array<string | null>;
  pinBodies: unknown[];
  readCalls: number;
  onCreate?: (route: Route) => Promise<boolean>;
}

function newServer(overrides: Partial<ServerState> = {}): ServerState {
  return { posts: [], unread: 0, capability: true, overviewStatus: 200, postsStatus: 200, created: 0, requests: [], submissions: [], parents: [], pinBodies: [], readCalls: 0, ...overrides };
}

const stations = [1, 2].map((number) => ({
  id: 40 + number, name: `Station ${number}`, address: '1 Test Street', city: 'Miami Beach', state: 'FL', zip_code: '33139', phone: '305-555-0100',
  station_number: number, is_active: true, created_at: '2026-08-31T00:00:00.000000Z', updated_at: '2026-08-31T00:00:00.000000Z',
}));

const overview = {
  generated_at: new Date().toISOString(),
  total: stations.length,
  stations: stations.map((station) => ({
    ...station, apparatus_count: 0, open_defects: 0, open_service_tickets: 0, last_checkout_at: null,
    daily_checkout: { required_total: 0, completed: 0, not_checked: 0, review_pending: 0, out_of_service: 0, completion_percent: null },
    apparatuses: [],
  })),
};

const pinnedOf = (server: ServerState) => server.posts.filter((post) => post.pinned);

function pageOf(server: ServerState, before: string | null, limit: number) {
  const start = before ? server.posts.findIndex((post) => post.id === before) + 1 : 0;
  const data = server.posts.slice(start, start + limit);
  const more = start + limit < server.posts.length;
  return { data, pinned: pinnedOf(server), next_before: more ? data[data.length - 1].id : null, unread_count: server.unread };
}

async function handleCreate(route: Route, server: ServerState): Promise<void> {
  if (server.onCreate && await server.onCreate(route)) return;
  const body = route.request().postData() ?? '';
  const field = (name: string) => new RegExp(`name="${name.replace(/[[\]]/g, '\\$&')}"\\r\\n\\r\\n([^\\r\\n]*)`).exec(body)?.[1] ?? null;
  server.submissions.push(field('client_submission_id') ?? '');
  const parent = field('parent_id');
  server.parents.push(parent);
  server.created += 1;
  const post = makePost(`new-${server.created}`, {
    parent_id: parent, body: field('body') ?? '', subject: field('subject'), is_mine: true,
    author: { name: 'Test Member', rank: 'Captain', side: 'command' }, created_at: new Date().toISOString(),
  });
  if (parent) {
    server.posts = server.posts.map((candidate) => (candidate.id === parent ? { ...candidate, replies: [...candidate.replies, post] } : candidate));
  } else {
    server.posts = [post, ...server.posts];
  }
  await route.fulfill({ status: 201, json: { data: post } });
}

async function mockApi(page: Page, server: ServerState): Promise<void> {
  await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
  await page.route('**/favicon*', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    const { pathname } = url;
    const method = route.request().method();
    server.requests.push(`${method} ${pathname}`);

    if (pathname === '/api/me/context') {
      return route.fulfill({
        json: {
          version: 1,
          identity: { user_id: 301, has_personnel_profile: true },
          personnel: { employee_profile_id: 1_301, employee_number: 'E301', name: 'Test Member', rank: 'Captain' },
          offline: { security_version: 1 },
          session: { authenticated: true },
          ...(server.capability === undefined ? {} : { authorization: { capabilities: { command_300_access: server.capability } } }),
        },
      });
    }
    if (pathname === '/api/public/stations') return route.fulfill({ json: { stations } });
    if (pathname === '/api/public/command/overview') {
      return server.overviewStatus === 200
        ? route.fulfill({ json: overview })
        : route.fulfill({ status: server.overviewStatus, json: { message: server.overviewStatus === 403 ? 'Forbidden' : 'Service unavailable' } });
    }
    if (pathname.startsWith(`${BASE}/files/`)) return route.fulfill({ body: PNG_1X1, contentType: 'image/png' });
    if (pathname === `${BASE}/posts` && method === 'GET') {
      if (server.postsStatus !== 200) return route.fulfill({ status: server.postsStatus, json: { message: 'Forbidden' } });
      return route.fulfill({ json: pageOf(server, url.searchParams.get('before'), Number(url.searchParams.get('limit') ?? 20)) });
    }
    if (pathname === `${BASE}/posts` && method === 'POST') return handleCreate(route, server);
    if (pathname === `${BASE}/summary`) return route.fulfill({ json: { unread_count: server.unread, latest_activity_at: null } });
    if (pathname === `${BASE}/read`) {
      server.readCalls += 1;
      server.unread = 0;
      return route.fulfill({ json: { unread_count: 0, last_read_at: new Date().toISOString() } });
    }
    const pin = /\/posts\/([^/]+)\/pin$/.exec(pathname);
    if (pin) {
      const body = JSON.parse(route.request().postData() ?? '{}') as { pinned: boolean };
      server.pinBodies.push(body);
      server.posts = server.posts.map((post) => (post.id === pin[1] ? { ...post, pinned: body.pinned } : post));
      return route.fulfill({ json: { data: server.posts.find((post) => post.id === pin[1]) } });
    }
    return route.fulfill({ status: 404, json: { message: `Unmocked route ${pathname}` } });
  });
}

async function open(page: Page, server: ServerState, path = '/daily/command-300'): Promise<void> {
  await mockApi(page, server);
  await page.goto(path);
}

const messageBox = (page: Page) => page.getByRole('textbox', { name: 'Message', exact: true });
const send = (page: Page) => page.getByTestId('command-send');

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const dimensions = await page.evaluate(() => ({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  expect(dimensions.scroll).toBeLessThanOrEqual(dimensions.client);
}

test('Communications is the first and default tab; unknown stations fall back to it and station links still work', async ({ page }) => {
  const server = newServer({ posts: [makePost('a')] });
  await open(page, server);
  const tabs = page.getByRole('tablist', { name: 'Communications and stations' });
  await expect(tabs.getByRole('tab')).toHaveCount(3);
  await expect(tabs.getByRole('tab').first()).toHaveText('Communications');
  await expect(tabs.getByRole('tab').first()).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('command-post')).toHaveCount(1);

  await tabs.getByRole('tab', { name: 'Station 2', exact: true }).click();
  await expect(page).toHaveURL(/station=42$/);
  await expect(page.getByTestId('command-station')).toHaveCount(1);
  await tabs.getByRole('tab', { name: 'Communications' }).click();
  await expect(page).not.toHaveURL(/station=/);

  await page.goto('/daily/command-300?station=999');
  await expect(page.getByTestId('command-comms')).toBeVisible();
  await page.goto('/daily/command-300?station=41');
  await expect(page.getByRole('heading', { name: 'Station 1', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Open Station 1', exact: true })).toHaveAttribute('href', /return_to=%2Fdaily%2Fcommand-300%3Fstation%3D41$/);
});

test('Communications stays usable with no stations and when the overview fails', async ({ page }) => {
  const server = newServer({ overviewStatus: 503 });
  await open(page, server);
  await expect(page.getByTestId('command-comms')).toBeVisible();
  await expect(page.getByRole('tab')).toHaveCount(1);
  await expect(page.getByRole('alert').filter({ hasText: 'Station overview unavailable' })).toBeVisible();
  await expect(page.getByText('No messages yet')).toBeVisible();
});

test('a posted message appears only after the server answers 201, and Ctrl+Enter sends', async ({ page }) => {
  let release!: () => void;
  const gate = new Promise<void>((resolve) => { release = resolve; });
  const server = newServer({ onCreate: async () => { await gate; return false; } });
  await open(page, server);
  await expect(page.getByText('No messages yet')).toBeVisible();

  await messageBox(page).fill('Engine 1 on scene');
  await messageBox(page).press('Enter');
  await messageBox(page).type('second line');
  await expect(messageBox(page)).toHaveValue('Engine 1 on scene\nsecond line');
  await messageBox(page).press('Control+Enter');
  await expect(page.getByText('Sending message…')).toBeVisible();
  await expect(page.getByTestId('command-post')).toHaveCount(0);
  await expect(messageBox(page)).toHaveValue('Engine 1 on scene\nsecond line');

  release();
  await expect(page.getByTestId('command-post')).toHaveCount(1);
  await expect(page.getByTestId('command-post')).toContainText('Engine 1 on scene');
  await expect(page.getByTestId('command-post')).toContainText('You');
  await expect(messageBox(page)).toHaveValue('');
});

test('a failed send keeps the draft and Retry reuses the same client_submission_id', async ({ page }) => {
  let attempts = 0;
  const server = newServer({
    onCreate: async (route) => {
      attempts += 1;
      const id = /name="client_submission_id"\r\n\r\n([^\r\n]*)/.exec(route.request().postData() ?? '')?.[1] ?? '';
      server.submissions.push(id);
      if (attempts === 1) { await route.fulfill({ status: 500, json: { message: 'Server error' } }); return true; }
      server.submissions.pop();
      return false;
    },
  });
  await open(page, server);
  await messageBox(page).fill('Do not lose this');
  await send(page).click();
  await expect(page.getByTestId('command-send-error')).toContainText('Message not sent.');
  await expect(page.getByTestId('command-send-error')).toContainText('Your text and files are still here');
  await expect(messageBox(page)).toHaveValue('Do not lose this');
  await expect(page.getByTestId('command-post')).toHaveCount(0);

  await page.getByTestId('command-send-error').getByRole('button', { name: 'Retry' }).click();
  await expect(page.getByTestId('command-post')).toHaveCount(1);
  expect(server.submissions).toHaveLength(2);
  expect(server.submissions[0]).not.toBe('');
  expect(server.submissions[1]).toBe(server.submissions[0]);
  await expect(page.getByTestId('command-send-error')).toHaveCount(0);
});

test('a validation error from the server is shown and the draft is kept', async ({ page }) => {
  const server = newServer({ onCreate: async (route) => { await route.fulfill({ status: 422, json: { message: 'Invalid', errors: { body: ['The message may not be longer than 5000 characters.'] } } }); return true; } });
  await open(page, server);
  await messageBox(page).fill('x');
  await send(page).click();
  await expect(page.getByTestId('command-send-error')).toContainText('may not be longer than 5000 characters');
  await expect(messageBox(page)).toHaveValue('x');
});

test('reply mode shows who is being answered, nests the reply, and collapses older replies', async ({ page }) => {
  const replies = [1, 2, 3, 4].map((n) => makePost(`r${n}`, { parent_id: 'root', body: `Reply ${n}`, created_at: minutesAgo(20 - n) }));
  const server = newServer({ posts: [makePost('root', { subject: 'Staffing', body: 'Who can cover Tuesday?', replies })] });
  await open(page, server);
  const post = page.getByTestId('command-post');
  await expect(post.getByRole('heading', { name: 'Staffing' })).toBeVisible();
  await expect(post.getByTestId('command-reply')).toHaveCount(2);
  await expect(post).toContainText('Reply 4');
  await expect(post).not.toContainText('Reply 1');
  await post.getByRole('button', { name: 'Show 2 earlier replies' }).click();
  await expect(post.getByTestId('command-reply')).toHaveCount(4);

  await post.getByRole('button', { name: 'Reply to Alex Rivera' }).click();
  await expect(page.getByTestId('command-reply-chip')).toContainText('Replying to Alex Rivera');
  await page.getByRole('textbox', { name: 'Reply to Alex Rivera' }).fill('I can cover');
  await send(page).click();
  await expect(post.getByTestId('command-reply')).toHaveCount(5);
  expect(server.parents).toEqual(['root']);
  await expect(page.getByTestId('command-reply-chip')).toHaveCount(0);

  await post.getByRole('button', { name: 'Reply to Alex Rivera' }).click();
  await page.getByRole('button', { name: 'Cancel reply' }).click();
  await expect(page.getByTestId('command-reply-chip')).toHaveCount(0);
});

test('pinned messages sit in a collapsible strip and can be unpinned', async ({ page }) => {
  const server = newServer({ posts: [makePost('b'), makePost('pin-1', { pinned: true, subject: 'Standing order', body: 'Radio check at 0700.' })] });
  await open(page, server);
  const strip = page.getByTestId('command-pinned');
  await expect(strip).toBeVisible();
  await expect(strip.getByRole('button', { name: /Pinned \(1\)/ })).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByTestId('command-pinned-post')).toHaveCount(0);
  await strip.getByRole('button', { name: /Pinned \(1\)/ }).click();
  await expect(page.getByTestId('command-pinned-post')).toContainText('Radio check at 0700.');
  await page.getByTestId('command-pinned-post').getByRole('button', { name: 'Unpin message from Alex Rivera' }).click();
  await expect(strip).toHaveCount(0);
  expect(server.pinBodies).toEqual([{ pinned: false }]);
});

test('message text is escaped and only http(s) addresses become links', async ({ page }) => {
  const body = 'See https://example.com/a?b=1. Also javascript:alert(1) and <img src=x onerror=alert(1)> and http://user:pw@evil.test/ plus https://example.org/x_(y).';
  const server = newServer({ posts: [makePost('links', { body })] });
  const dialogs: string[] = [];
  page.on('dialog', (dialog) => { dialogs.push(dialog.message()); void dialog.dismiss(); });
  await open(page, server);
  const post = page.getByTestId('command-post');
  const links = post.locator('a[href]');
  await expect(links).toHaveCount(2);
  await expect(links.nth(0)).toHaveAttribute('href', 'https://example.com/a?b=1');
  await expect(links.nth(1)).toHaveAttribute('href', 'https://example.org/x_(y)');
  for (const link of await links.all()) {
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener noreferrer nofollow');
  }
  await expect(post.locator('a[href^="javascript"]')).toHaveCount(0);
  await expect(post.locator('img[src="x"]')).toHaveCount(0);
  await expect(post).toContainText('javascript:alert(1)');
  await expect(post).toContainText('<img src=x onerror=alert(1)>');
  expect(dialogs).toEqual([]);
});

test('attachments render as thumbnails and download chips', async ({ page }) => {
  const server = newServer({
    posts: [makePost('files', {
      attachments: [
        { id: 'i1', name: 'scene.png', mime_type: 'image/png', size: 2048, kind: 'image', url: `${BASE}/files/i1` },
        { id: 'd1', name: 'Roster.pdf', mime_type: 'application/pdf', size: 3 * 1024 * 1024, kind: 'document', url: `${BASE}/files/d1` },
      ],
    })],
  });
  await open(page, server);
  const image = page.getByRole('link', { name: /Open image scene.png/ });
  await expect(image).toHaveAttribute('href', `${BASE}/files/i1?inline=1`);
  await expect(image.getByRole('img')).toHaveAttribute('loading', 'lazy');
  const chip = page.getByRole('link', { name: /Roster.pdf/ });
  await expect(chip).toHaveAttribute('download', 'Roster.pdf');
  await expect(chip).toContainText('3.0 MB');
});

test('the composer validates attachments before upload and lets members remove them', async ({ page }) => {
  await open(page, newServer());
  const input = page.getByTestId('command-file-input');
  await input.setInputFiles({ name: 'virus.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('x') });
  await expect(page.getByTestId('command-file-notice')).toContainText('not a supported file type');
  await input.setInputFiles({ name: 'huge.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(16 * 1024 * 1024) });
  await expect(page.getByTestId('command-file-notice')).toContainText('larger than 15 MB');
  const files = Array.from({ length: 6 }, (_, index) => ({ name: `note-${index}.txt`, mimeType: 'text/plain', buffer: Buffer.from(`n${index}`) }));
  await input.setInputFiles(files);
  await expect(page.getByTestId('command-file-notice')).toContainText('up to 5 files');
  await expect(page.getByRole('list', { name: 'Files to attach' }).getByRole('listitem')).toHaveCount(5);
  await page.getByRole('button', { name: 'Remove note-0.txt' }).click();
  await expect(page.getByRole('list', { name: 'Files to attach' }).getByRole('listitem')).toHaveCount(4);
  await page.getByRole('button', { name: 'Add subject' }).click();
  await expect(page.getByLabel('Subject')).toBeVisible();
});

test('the unread count is a badge on the Communications tab and clears once the newest message is in view', async ({ page }) => {
  let release!: () => void;
  const gate = new Promise<void>((resolve) => { release = resolve; });
  const server = newServer({ posts: [makePost('u1'), makePost('u2')], unread: 3 });
  await mockApi(page, server);
  await page.route(`**${BASE}/read`, async (route) => { await gate; await route.fallback(); });
  await page.goto('/daily/command-300');
  await expect(page.getByRole('tab', { name: /^Communications 3 unread messages$/ })).toBeVisible();
  release();
  await expect(page.getByRole('tab', { name: 'Communications', exact: true })).toBeVisible();
  expect(server.readCalls).toBe(1);
});

test('the feed scrolls inside a bounded box: history never lengthens the page', async ({ page }) => {
  const posts = Array.from({ length: 45 }, (_, index) => makePost(`m${45 - index}`, { body: `Message ${45 - index}\nsecond line\nthird line`, created_at: minutesAgo(index + 1) }));
  await open(page, newServer({ posts }));
  const feed = page.getByTestId('command-feed');
  await expect(page.getByTestId('command-post')).toHaveCount(20);

  const metrics = await feed.evaluate((element) => ({
    client: element.clientHeight, scroll: element.scrollHeight, top: element.scrollTop, vh: window.innerHeight, vw: window.innerWidth,
  }));
  const cap = metrics.vw < 640 ? Math.min(metrics.vh * 0.5, 480) : Math.min(metrics.vh * 0.6, 560);
  expect(metrics.client).toBeLessThanOrEqual(cap + 1);
  expect(metrics.scroll).toBeGreaterThan(metrics.client);
  // Oldest at the top, newest at the bottom, and the view starts at the newest.
  expect(metrics.top + metrics.client).toBeGreaterThanOrEqual(metrics.scroll - 48);
  await expect(page.getByTestId('command-post').last()).toContainText('Message 45');

  const pageHeight = () => page.evaluate(() => document.documentElement.scrollHeight);
  const before = await pageHeight();
  await feed.evaluate((element) => { element.scrollTop = 0; });
  await page.getByRole('button', { name: 'Load earlier messages' }).click();
  await expect(page.getByTestId('command-post')).toHaveCount(40);
  expect(Math.abs((await pageHeight()) - before)).toBeLessThanOrEqual(1);
  await expect(page.getByTestId('command-post').first()).toContainText('Message 6');
  await expectNoHorizontalOverflow(page);
  await expect(send(page)).toBeVisible();
});

test('new posts while scrolled up show a jump pill instead of moving the view', async ({ page }) => {
  const posts = Array.from({ length: 20 }, (_, index) => makePost(`n${20 - index}`, { body: `Message ${20 - index}\nsecond line\nthird line`, created_at: minutesAgo(index + 5) }));
  const server = newServer({ posts });
  await open(page, server);
  const feed = page.getByTestId('command-feed');
  await expect(page.getByTestId('command-post')).toHaveCount(20);
  await feed.evaluate((element) => { element.scrollTop = 0; });
  await expect(page.getByTestId('command-new-messages')).toHaveCount(0);

  server.posts = [makePost('n21', { body: 'Fresh arrival', created_at: new Date().toISOString() }), ...server.posts];
  await page.getByTestId('command-overview-refresh').click();
  await expect(page.getByTestId('command-new-messages')).toBeVisible();
  expect(await feed.evaluate((element) => element.scrollTop)).toBeLessThan(40);
  await page.getByTestId('command-new-messages').click();
  await expect(page.getByTestId('command-new-messages')).toHaveCount(0);
  await expect(page.getByTestId('command-post').last()).toContainText('Fresh arrival');
});

test('accounts without 300 Command access see no card and an explanatory page, and make no message requests', async ({ page }) => {
  const server = newServer({ capability: false });
  await mockApi(page, server);
  await page.goto('/daily/stations');
  await expect(page.getByRole('heading', { name: 'MBFD Stations' })).toBeVisible();
  await expect(page.getByTestId('daily-station-card')).toHaveCount(stations.length);
  await expect(page.getByTestId('daily-command-card')).toHaveCount(0);

  await page.goto('/daily/command-300');
  await expect(page.getByRole('heading', { name: '300 Command is not available to your account' })).toBeVisible();
  await expect(page.getByText('Your station tools are not affected')).toBeVisible();
  await expect(page.getByTestId('command-comms')).toHaveCount(0);
  await expect(page.getByTestId('command-stations')).toHaveCount(0);
  await expectNoHorizontalOverflow(page);
  await page.getByRole('link', { name: 'Back to stations' }).click();
  await expect(page).toHaveURL(/\/daily\/stations$/);
  expect(server.requests.filter((request) => request.includes(BASE))).toEqual([]);
});

test('eligible accounts see the card with an unread count', async ({ page }) => {
  await open(page, newServer({ unread: 2 }), '/daily/stations');
  await expect(page.getByTestId('daily-command-card')).toBeVisible();
  await expect(page.getByTestId('daily-command-unread')).toContainText('2 unread messages');
});

test('a 403 from the station overview closes the page even when the capability said yes', async ({ page }) => {
  await open(page, newServer({ capability: true, overviewStatus: 403 }));
  await expect(page.getByRole('heading', { name: '300 Command is not available to your account' })).toBeVisible();
});

test('a 403 from the message feed closes the page', async ({ page }) => {
  await open(page, newServer({ postsStatus: 403 }));
  await expect(page.getByRole('heading', { name: '300 Command is not available to your account' })).toBeVisible();
  await expect(page.getByTestId('command-send')).toHaveCount(0);
});

test('the Communications tab fits every viewport without horizontal overflow', async ({ page }) => {
  const long = 'https://example.com/' + 'a'.repeat(120);
  const server = newServer({ posts: [makePost('wide', { subject: 'A fairly long subject line that must wrap on phones', body: `${long}\n${'wordwrap '.repeat(30)}`, pinned: true, attachments: [{ id: 'd', name: `${'long-file-name-'.repeat(6)}.pdf`, mime_type: 'application/pdf', size: 1024, kind: 'document', url: `${BASE}/files/d` }] })], unread: 4 });
  await open(page, server);
  await expect(page.getByTestId('command-post')).toBeVisible();
  await page.getByRole('button', { name: /Pinned \(1\)/ }).click();
  await page.getByRole('button', { name: 'Add subject' }).click();
  await expectNoHorizontalOverflow(page);
  for (const locator of [send(page), page.getByRole('button', { name: 'Attach' }), page.getByRole('button', { name: 'Reply to Alex Rivera' })]) {
    const box = await locator.boundingBox();
    expect(box!.height).toBeGreaterThanOrEqual(43.5);
    expect(box!.x).toBeGreaterThanOrEqual(0);
  }
});
