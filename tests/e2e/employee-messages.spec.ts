import { expect, test, type Browser, type BrowserContext, type Page, type TestInfo } from '@playwright/test';
import { join } from 'node:path';
import { authDirectory, type PersonaName } from './employee-messages.setup';

/*
 * Support Services messaging in real browsers (formerly Employee <-> Logistics) (docs/employee-logistics-messaging.md).
 * Synthetic personas only; see database/seeders/EmployeeMessagesE2ESeeder.php.
 *
 * The conversation tests run in order inside one serial group per project: each builds on the
 * messages the previous one sent. Every project gets its own token, so the desktop and mobile
 * runs never see each other's text.
 */

const MIN_TARGET_PX = 44;
const RELEASE_A = 'aaaaaaaaaaaa';
const RELEASE_B = 'bbbbbbbbbbbb';
const BUSY_MESSAGE = 'An update is ready. It will apply when you finish.';

interface RunState {
  token: string;
  subject: string;
  firstBody: string;
  logisticsWidgetReply: string;
  memberFollowUp: string;
  logisticsInboxReply: string;
  threadId: string | null;
}

const runs = new Map<string, RunState>();

function run(info: TestInfo): RunState {
  const existing = runs.get(info.project.name);
  if (existing) return existing;
  const token = `${info.project.name}${Date.now().toString(36)}`;
  const created: RunState = {
    token,
    subject: `Helmet pads ${token}`,
    firstBody: `Need two replacement helmet pads for Engine 5 ${token}`,
    logisticsWidgetReply: `Pads are on the shelf, stop by ${token} (widget reply)`,
    memberFollowUp: `Thanks, picking them up Tuesday ${token}`,
    logisticsInboxReply: `Noted, set aside at Logistics ${token} (inbox reply)`,
    threadId: null,
  };
  runs.set(info.project.name, created);

  return created;
}

interface Session {
  context: BrowserContext;
  page: Page;
}

async function signIn(browser: Browser, info: TestInfo, persona: PersonaName): Promise<Session> {
  const use = info.project.use;
  const context = await browser.newContext({
    baseURL: use.baseURL,
    viewport: use.viewport ?? undefined,
    isMobile: use.isMobile,
    hasTouch: use.hasTouch,
    deviceScaleFactor: use.deviceScaleFactor,
    userAgent: use.userAgent,
    storageState: join(authDirectory, `${persona}.json`),
  });
  const page = await context.newPage();

  return { context, page };
}

async function as(browser: Browser, info: TestInfo, persona: PersonaName, scenario: (session: Session) => Promise<void>): Promise<void> {
  const session = await signIn(browser, info, persona);
  try {
    await scenario(session);
  } finally {
    await session.context.close();
  }
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'the page must not scroll sideways').toBeLessThanOrEqual(1);
}

/** The message text of a rendered bubble. */
const bubble = (page: Page, text: string) => page.locator('.emsg-bubble__body', { hasText: text });

/** The page reports a settled Livewire page: no request in flight. */
async function livewireIdle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle');
}

/** Open the drawer on phones, expand the Communications group when it is collapsed, then follow the link. */
async function openCommunicationsLink(page: Page, name: RegExp): Promise<void> {
  const sidebar = page.locator('.fi-sidebar');
  const link = sidebar.getByRole('link', { name }).first();
  if (!(await link.isVisible().catch(() => false))) {
    const opener = page.locator('.fi-topbar-open-sidebar-btn');
    if (await opener.isVisible().catch(() => false)) await opener.click();
  }
  if (!(await link.isVisible().catch(() => false))) {
    await sidebar.getByRole('button', { name: 'Communications' }).click();
  }
  await link.click();
}

const busyReasons = (page: Page) =>
  page.evaluate(() => Array.from((window as unknown as { __mbfdBusy?: Set<string> }).__mbfdBusy ?? []).filter((reason) => reason.startsWith('employee-message:')));

/** Ask the Livewire component whose name contains `fragment` to run its `poll` action, as the 30 second poll does. */
const pollComponent = (page: Page, fragment: string) =>
  page.evaluate(async (needle) => {
    const livewire = (window as unknown as { Livewire: { all(): Array<{ name: string; $wire: { $call(method: string): Promise<unknown> } }> } }).Livewire;
    const component = livewire.all().find((candidate) => candidate.name.includes(needle));
    if (!component) throw new Error(`No Livewire component named like ${needle}: ${livewire.all().map((c) => c.name).join(', ')}`);
    await component.$wire.$call('poll');
  }, fragment);

test.describe.serial('Support Services conversation', () => {
  test('member A: Home offers Message Support Services beside Vehicle Checkout, and a first message is sent and persists', async ({ browser }, info) => {
    const state = run(info);
    await as(browser, info, 'memberA', async ({ page }) => {
      await page.goto('/');
      const actions = page.locator('.hub-page-header__actions');
      const checkout = actions.getByRole('link', { name: 'Vehicle Checkout', exact: true });
      const message = actions.getByRole('link', { name: 'Message Support Services', exact: true });
      await expect(checkout).toBeVisible();
      await expect(message).toBeVisible();
      await expect(message).toHaveAttribute('href', /\/employee\/messages\?compose=1$/);
      for (const control of [checkout, message]) {
        const box = await control.boundingBox();
        expect(box?.height ?? 0, 'touch target height').toBeGreaterThanOrEqual(MIN_TARGET_PX);
      }
      await expectNoHorizontalOverflow(page);

      await message.click();
      // Livewire may rewrite the boolean to compose=true as soon as it boots, so a slower check sees either.
      await expect(page).toHaveURL(/\/employee\/messages\?compose=(1|true)/);
      const body = page.locator('#emsg-member-new-body');
      await expect(body).toBeFocused();
      await expectNoHorizontalOverflow(page);

      await page.locator('#emsg-member-new-subject').fill(state.subject);
      await body.fill(state.firstBody);
      await page.getByRole('button', { name: 'Send to Support Services' }).click();

      await expect(bubble(page, state.firstBody)).toBeVisible();
      await expect(page.locator('.emsg-bubble--mine')).toHaveCount(1);
      state.threadId = new URL(page.url()).searchParams.get('thread');
      expect(state.threadId, 'the open conversation is addressable by ?thread=').toMatch(/^\d+$/);

      await page.reload();
      await expect(bubble(page, state.firstBody)).toBeVisible();
      await expect(page.locator('.emsg-bubble')).toHaveCount(1);
      await expectNoHorizontalOverflow(page);
    });
  });

  test('responder: the admin home card follows the Station Operations board, shows the unread row, expands and replies inline', async ({ browser }, info) => {
    const state = run(info);
    await as(browser, info, 'responder', async ({ page }) => {
      await page.goto('/admin/logistics-board');
      const stations = page.locator('#mbfd-stations-heading');
      const card = page.locator('#emsg-widget-title');
      await expect(stations).toBeVisible();
      await expect(card).toBeVisible();
      const cardFollowsBoard = await page.evaluate(() => {
        const board = document.getElementById('mbfd-stations-heading');
        const widget = document.getElementById('emsg-widget-title');

        return Boolean(board && widget && (board.compareDocumentPosition(widget) & Node.DOCUMENT_POSITION_FOLLOWING));
      });
      expect(cardFollowsBoard, 'Support Services Messages comes after the Station Operations board').toBe(true);

      const row = page.locator('button[data-emsg-row]', { hasText: state.firstBody });
      await expect(row).toBeVisible();
      await expect(row).toContainText('Casey Alpha');
      await expect(row.locator('.emsg-unread-dot')).toHaveText('Unread');

      await row.click();
      await expect(row).toHaveAttribute('aria-expanded', 'true');
      await expect(bubble(page, state.firstBody)).toBeVisible();

      const reply = page.getByLabel('Reply to Casey Alpha');
      await reply.fill(state.logisticsWidgetReply);
      await page.getByRole('button', { name: 'Send reply' }).click();
      await expect(bubble(page, state.logisticsWidgetReply)).toBeVisible();
      await expect(page.locator('.emsg-feedback__status', { hasText: 'Message sent.' })).toBeVisible();
      await expectNoHorizontalOverflow(page);
    });
  });

  test('responder: the Dashboard card is hidden in display mode and no message text leaks into it', async ({ browser }, info) => {
    const state = run(info);
    await as(browser, info, 'responder', async ({ page }) => {
      await page.goto('/admin?display=1');
      await livewireIdle(page);
      await expect(page.locator('#emsg-widget-title')).toHaveCount(0);
      await expect(page.locator('main .emsg-panel')).toHaveCount(0);
      const html = await page.content();
      expect(html).not.toContain(state.firstBody);
      expect(html).not.toContain(state.logisticsWidgetReply);
      expect(html).not.toContain('Casey Alpha');
    });
  });

  test('member A: sees the Logistics reply as unread (Dashboard card, list pill), reads it and replies again', async ({ browser }, info) => {
    const state = run(info);
    await as(browser, info, 'memberA', async ({ page }) => {
      await page.goto('/employee/dashboard');
      const shortcut = page.locator('[data-employee-messages-shortcut]');
      await expect(shortcut).toContainText('1 new reply in My Messages');
      await expect(shortcut.locator('.emsg-unread-dot')).toContainText('1');
      await expectNoHorizontalOverflow(page);

      await page.goto('/employee/messages');
      const row = page.locator('.emsg-row', { hasText: state.logisticsWidgetReply });
      await expect(row).toBeVisible();
      await expect(row.locator('.emsg-unread-dot')).toContainText('New');
      await row.click();

      await expect(bubble(page, state.logisticsWidgetReply)).toBeVisible();
      await expect(page.locator('.emsg-bubble--logistics')).toHaveCount(1);
      await page.getByLabel('Reply to Support Services').fill(state.memberFollowUp);
      await page.getByRole('button', { name: 'Send reply' }).click();
      await expect(bubble(page, state.memberFollowUp)).toBeVisible();
      await expect(page.locator('.emsg-bubble')).toHaveCount(3);
    });
  });

  test('responder: the Communications inbox lists the conversation, searches, filters, opens and replies; Inbound Email stays separate', async ({ browser }, info) => {
    const state = run(info);
    await as(browser, info, 'responder', async ({ page }) => {
      await page.goto('/admin');
      await openCommunicationsLink(page, /^Support Services Messages/);
      await expect(page).toHaveURL(/\/admin\/employee-messages/);
      await expect(page.getByRole('heading', { name: 'Support Services Messages', level: 1 })).toBeVisible();

      const row = page.locator('.emsg-row', { hasText: state.memberFollowUp });
      await expect(row).toBeVisible();
      await expect(row.locator('.emsg-unread-dot')).toHaveText('Unread');
      await expect(row).toContainText('Awaiting reply');

      const search = page.getByRole('searchbox', { name: 'Search Support Services messages' });
      await search.fill('Casey');
      await expect(row).toBeVisible();
      await search.fill('nobody-by-this-name');
      await expect(page.getByText('No conversations match')).toBeVisible();
      await expect(row).toHaveCount(0);
      await search.fill('');
      await expect(row).toBeVisible();

      await page.getByRole('button', { name: 'Unread', exact: true }).click();
      await expect(page.getByRole('button', { name: 'Unread', exact: true })).toHaveAttribute('aria-pressed', 'true');
      await expect(row).toBeVisible();
      await page.getByRole('button', { name: 'All', exact: true }).click();
      await expect(page.getByRole('button', { name: 'All', exact: true })).toHaveAttribute('aria-pressed', 'true');
      await expect(row).toBeVisible();

      await row.click();
      await expect(bubble(page, state.memberFollowUp)).toBeVisible();
      await expect(bubble(page, state.logisticsWidgetReply)).toBeVisible();
      await page.getByLabel('Reply to Casey Alpha').fill(state.logisticsInboxReply);
      await page.getByRole('button', { name: 'Send reply' }).click();
      await expect(bubble(page, state.logisticsInboxReply)).toBeVisible();

      // Opening the thread marked it read: the Unread filter no longer lists it.
      // On a phone the open conversation replaces the list; go back to it.
      const back = page.getByRole('button', { name: 'Back to all messages' });
      if (await back.isVisible().catch(() => false)) await back.click();
      await page.getByRole('button', { name: 'Unread', exact: true }).click();
      await expect(page.locator('.emsg-row', { hasText: state.logisticsInboxReply })).toHaveCount(0);
      await expectNoHorizontalOverflow(page);

      // Email is its own page and nav item (it replaced Inbound Email, Outbound Email and Compose Email).
      await openCommunicationsLink(page, /^Email/);
      await expect(page).toHaveURL(/\/admin\/email/);
      await expect(page.getByRole('heading', { name: 'Email', exact: true }).first()).toBeVisible();
      await expect(page.locator('.emsg-layout')).toHaveCount(0);
    });
  });

  test('member A: sees both Logistics replies in order; member B cannot see the conversation', async ({ browser }, info) => {
    const state = run(info);
    await as(browser, info, 'memberA', async ({ page }) => {
      await page.goto('/employee/dashboard');
      await expect(page.locator('[data-employee-messages-shortcut]')).toContainText('1 new reply in My Messages');

      await page.goto(`/employee/messages?thread=${state.threadId}`);
      const bodies = await page.locator('.emsg-bubble__body').allTextContents();
      expect(bodies).toEqual([state.firstBody, state.logisticsWidgetReply, state.memberFollowUp, state.logisticsInboxReply]);
      await expect(page.locator('.emsg-bubble--logistics')).toHaveCount(2);

      await page.goto('/employee/dashboard');
      await expect(page.locator('[data-employee-messages-shortcut]')).not.toContainText('new repl');
    });

    await as(browser, info, 'memberB', async ({ page }) => {
      await page.goto('/employee/messages');
      // Member B may own conversations of their own (the bunker gear scenario sends one); none is member A's.
      await expect(page.locator('.emsg-row').filter({ hasText: state.firstBody })).toHaveCount(0);
      await expect(page.getByText(state.firstBody)).toHaveCount(0);

      await page.goto(`/employee/messages?thread=${state.threadId}`);
      await expect(page.getByRole('heading', { name: 'Conversation not found' })).toBeVisible();
      expect(await page.content()).not.toContain(state.firstBody);
      expect(await page.content()).not.toContain(state.logisticsWidgetReply);

      await page.goto('/employee/dashboard');
      await expect(page.locator('[data-employee-messages-shortcut]')).not.toContainText('new repl');
    });
  });

  test('view-only admin reads the inbox and card but has no composer; an unrelated admin has no way in', async ({ browser }, info) => {
    const state = run(info);
    await as(browser, info, 'viewer', async ({ page }) => {
      await page.goto('/admin/employee-messages');
      const row = page.locator('.emsg-row', { hasText: state.logisticsInboxReply });
      await expect(row).toBeVisible();
      await row.click();
      await expect(bubble(page, state.memberFollowUp)).toBeVisible();
      await expect(page.getByText(/can read Support Services messages but not reply/i)).toBeVisible();
      await expect(page.locator('[data-employee-message-composer]')).toHaveCount(0);
      await expect(page.getByRole('button', { name: 'Send reply' })).toHaveCount(0);

      await page.goto('/admin/logistics-board');
      await expect(page.locator('#emsg-widget-title')).toBeVisible();
      await page.locator('button[data-emsg-row]', { hasText: state.logisticsInboxReply }).click();
      await expect(page.getByText(/can read Support Services messages but not reply/i)).toBeVisible();
      await expect(page.locator('[data-employee-message-composer]')).toHaveCount(0);
    });

    await as(browser, info, 'unrelated', async ({ page }) => {
      await page.goto('/admin/logistics-board');
      await expect(page.locator('.fi-sidebar, .fi-topbar').first()).toBeVisible();
      await expect(page.locator('#emsg-widget-title')).toHaveCount(0);
      expect(await page.content()).not.toContain(state.firstBody);
      // Expand Communications first: links inside a collapsed group are hidden, which would make "absent" vacuous.
      const sidebar = page.locator('.fi-sidebar');
      const opener = page.locator('.fi-topbar-open-sidebar-btn');
      if (await opener.isVisible().catch(() => false)) await opener.click();
      await sidebar.getByRole('button', { name: 'Communications' }).click();
      await expect(sidebar.getByRole('link', { name: /^Email/ })).toBeVisible();
      await expect(sidebar.getByRole('link', { name: /Support Services Messages/ })).toHaveCount(0);

      const response = await page.goto('/admin/employee-messages');
      expect(response?.status()).toBe(403);
      expect(await page.content()).not.toContain(state.firstBody);
    });
  });
});

// ---------------------------------------------------------------------------
// Drafts, polling, offline and PWA updates
// ---------------------------------------------------------------------------

interface ReleaseState {
  served: string;
}

async function pinReleases(page: Page, state: ReleaseState): Promise<void> {
  await page.route('**/app-release', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', headers: { 'cache-control': 'no-store' }, body: JSON.stringify({ release: state.served }) }),
  );
}

const registrarReady = (page: Page) =>
  page.evaluate(() => Boolean((window as unknown as { __mbfdUpdateRegistrar?: unknown }).__mbfdUpdateRegistrar));

const checkNow = (page: Page) =>
  page.evaluate(() =>
    (window as unknown as { __mbfdUpdateRegistrar: { api: { checkNow(force: boolean): Promise<void> } } }).__mbfdUpdateRegistrar.api.checkNow(true),
  );

const armUnloadProbe = (page: Page) =>
  page.evaluate(() => {
    const target = window as unknown as { __e2eUnloading?: boolean };
    target.__e2eUnloading = false;
    window.addEventListener('beforeunload', () => {
      target.__e2eUnloading = true;
    });
  });

const unloadStarted = (page: Page): Promise<boolean> =>
  page.evaluate(() => Boolean((window as unknown as { __e2eUnloading?: boolean }).__e2eUnloading)).catch(() => true);

/**
 * One draft-guard scenario for a composer: typing registers an employee-message busy reason, a Livewire
 * poll leaves the text alone, a pending update banner refuses "Refresh now" while the draft exists, and a
 * confirmed send releases everything.
 */
async function exerciseDraftGuard(page: Page, state: ReleaseState, composer: { textarea: string; send: string; pollComponent: string; sentText: string; confirmed: () => Promise<void> }): Promise<void> {
  await expect.poll(() => registrarReady(page), { message: 'the update registrar is mounted on this page' }).toBe(true);
  const textarea = page.locator(composer.textarea);
  await expect(textarea).toBeVisible();
  expect(await busyReasons(page), 'an empty composer is not busy').toEqual([]);

  const draft = `${composer.sentText} (draft)`;
  await textarea.fill(draft);
  await expect.poll(() => busyReasons(page).then((reasons) => reasons.length)).toBe(1);

  // A poll re-renders the page around the composer; the typed text must survive it.
  await pollComponent(page, composer.pollComponent);
  await page.waitForLoadState('networkidle');
  await expect(textarea).toHaveValue(draft);
  expect(await busyReasons(page)).toHaveLength(1);

  // A new release arrives while the draft exists: banner, but no reload, and "Refresh now" is held back.
  state.served = RELEASE_B;
  await checkNow(page);
  const banner = page.locator('#mbfd-pwa-banner');
  await expect(banner).toContainText(BUSY_MESSAGE);
  const refresh = banner.getByRole('button', { name: 'Refresh now' });
  await expect(refresh).toHaveAttribute('aria-disabled', 'true');
  await armUnloadProbe(page);
  await refresh.click({ force: true });
  await page.waitForTimeout(800);
  expect(await unloadStarted(page), 'a draft must block the manual refresh').toBe(false);
  await expect(textarea).toHaveValue(draft);

  await textarea.fill(composer.sentText);
  await page.locator(composer.send).click();
  await composer.confirmed();

  // Saved: the busy reason is released and the banner becomes usable.
  await expect.poll(() => busyReasons(page)).toEqual([]);
  await expect(banner.getByRole('button', { name: 'Refresh now' })).not.toHaveAttribute('aria-disabled', 'true');
  await expect(banner).not.toContainText(BUSY_MESSAGE);
}

test.describe('Drafts and PWA updates', () => {
  test('member page: a draft is busy, survives polls, blocks Refresh now, and is released once sent', async ({ browser }, info) => {
    const sent = `Draft guard member ${run(info).token}`;
    await as(browser, info, 'memberA', async ({ page }) => {
      const releases: ReleaseState = { served: RELEASE_A };
      await pinReleases(page, releases);
      await page.goto('/employee/messages?compose=1');
      await exerciseDraftGuard(page, releases, {
        textarea: '#emsg-member-new-body',
        send: 'button:has-text("Send to Support Services")',
        pollComponent: 'my-messages',
        sentText: sent,
        confirmed: async () => {
          await expect(bubble(page, sent)).toBeVisible();
        },
      });
    });
  });

  test('admin inbox: a reply draft is busy, survives polls, blocks Refresh now, and is released once sent', async ({ browser }, info) => {
    const state = run(info);
    const sent = `Draft guard logistics ${state.token}`;
    // Its own conversation (from the view-only persona, who also holds a member profile), so the Logistics
    // reply below never turns member A's read conversation unread again.
    let threadId: string | null = null;
    await as(browser, info, 'viewer', async ({ page }) => {
      await page.goto('/employee/messages?compose=1');
      await page.locator('#emsg-member-new-body').fill(`Guard thread opener ${state.token}`);
      await page.getByRole('button', { name: 'Send to Support Services' }).click();
      await expect(bubble(page, `Guard thread opener ${state.token}`)).toBeVisible();
      threadId = new URL(page.url()).searchParams.get('thread');
    });
    expect(threadId, 'viewer thread id from the page URL').toMatch(/^\d+$/);
    await as(browser, info, 'responder', async ({ page }) => {
      const releases: ReleaseState = { served: RELEASE_A };
      await pinReleases(page, releases);
      await page.goto(`/admin/employee-messages?thread=${threadId}`);
      await exerciseDraftGuard(page, releases, {
        textarea: '[data-employee-message-composer] textarea',
        send: 'button:has-text("Send reply")',
        pollComponent: 'employee-messages',
        sentText: sent,
        confirmed: async () => {
          await expect(bubble(page, sent)).toBeVisible();
        },
      });
    });
  });

  test('offline: Send keeps the draft and creates nothing; back online it is sent exactly once', async ({ browser }, info) => {
    const token = run(info).token;
    const text = `Offline draft ${token}`;
    await as(browser, info, 'memberA', async ({ context, page }) => {
      await page.goto('/employee/messages?compose=1');
      const textarea = page.locator('#emsg-member-new-body');
      await expect(textarea).toBeFocused();
      await textarea.fill(text);

      await context.setOffline(true);
      await page.getByRole('button', { name: 'Send to Support Services' }).click();
      await expect(page.getByRole('alert').filter({ hasText: "You're offline" })).toBeVisible();
      await expect(textarea).toHaveValue(text);
      expect(await busyReasons(page), 'the unsent draft still holds the page').toHaveLength(1);
      await expect(bubble(page, text)).toHaveCount(0);

      await context.setOffline(false);
      await page.getByRole('button', { name: 'Send to Support Services' }).click();
      await expect(bubble(page, text)).toHaveCount(1);
      await expect.poll(() => busyReasons(page)).toEqual([]);

      // Exactly one conversation holds it, however many times the page is reloaded.
      await page.reload();
      await expect(bubble(page, text)).toHaveCount(1);
      await page.goto('/employee/messages');
      await expect(page.locator('.emsg-row', { hasText: text })).toHaveCount(1);
    });
  });
});

// ---------------------------------------------------------------------------
// Accessibility basics
// ---------------------------------------------------------------------------

test.describe('Composer accessibility', () => {
  test('the composer is labelled, announces its status politely, and is reachable and operable by keyboard', async ({ browser }, info) => {
    const text = `Keyboard message ${run(info).token}`;
    await as(browser, info, 'memberA', async ({ page }) => {
      await page.goto('/employee/messages?compose=1');

      const field = page.getByRole('textbox', { name: 'Message', exact: true });
      await expect(field).toBeVisible();
      await expect(page.getByRole('textbox', { name: /^Subject/ })).toBeVisible();
      const live = page.locator('[data-employee-message-composer] [aria-live="polite"]');
      await expect(live).toHaveCount(1);
      await expect(live).toHaveAttribute('aria-atomic', 'true');
      await expect(field).toHaveAttribute('aria-describedby', /emsg-member-new-feedback/);

      await field.fill(text);
      await expect(field).toBeFocused();
      await page.keyboard.press('Tab');
      await expect(page.getByRole('button', { name: 'Send to Support Services' })).toBeFocused();

      await field.focus();
      await page.keyboard.press('Control+Enter');
      await expect(bubble(page, text)).toBeVisible();
      await expect(bubble(page, text)).toHaveCount(1);
    });
  });
});

// ---------------------------------------------------------------------------
// Bunker gear cleaning request: member -> admin home card -> exact conversation -> reply -> member
// ---------------------------------------------------------------------------

test.describe.serial('Bunker gear cleaning request', () => {
  test('member B sends a bunker gear request; the admin home card shows it and Reply opens that exact conversation; member B sees the reply', async ({ browser }, info) => {
    const token = run(info).token;
    const body = `Please clean my turnout coat and pants ${token}`;
    const reply = `Drop the gear at Station 1 Monday ${token}`;
    let threadId: string | null = null;

    await as(browser, info, 'memberB', async ({ page }) => {
      await page.goto('/employee/messages?compose=1');
      const reason = page.getByLabel('Reason');
      await expect(reason).toBeVisible();
      await reason.selectOption({ label: 'Bunker gear cleaning request' });
      await page.locator('#emsg-member-new-body').fill(body);
      await page.getByRole('button', { name: 'Send to Support Services' }).click();
      await expect(bubble(page, body)).toBeVisible();
      await expect(page.getByText('Reason: Bunker gear cleaning request')).toBeVisible();
      await expect(page.locator('[data-emsg-status]').first()).toContainText('Sent, awaiting reply');
      threadId = new URL(page.url()).searchParams.get('thread');
      expect(threadId).toMatch(/^\d+$/);
    });

    await as(browser, info, 'responder', async ({ page }) => {
      await page.goto('/admin/logistics-board');
      const card = page.locator('#emsg-widget-title');
      await expect(card).toHaveText('Support Services Messages');
      const row = page.locator('button[data-emsg-row]', { hasText: body });
      await expect(row).toBeVisible();
      await expect(row.locator('.emsg-tag--reason')).toHaveText('Bunker gear cleaning request');
      const open = page.locator(`a[data-emsg-open-link][href$="thread=${threadId}"]`);
      await expect(open).toHaveText('Reply');
      await expectNoHorizontalOverflow(page);
      await open.click();
      await expect(page).toHaveURL(new RegExp(`/admin/employee-messages\\?thread=${threadId}$`));
      await expect(bubble(page, body)).toBeVisible();
      await page.getByLabel('Reply to ', { exact: false }).first().fill(reply);
      await page.getByRole('button', { name: 'Send reply' }).click();
      await expect(bubble(page, reply)).toBeVisible();

      // The deep link survives a refresh.
      await page.reload();
      await expect(bubble(page, body)).toBeVisible();
      await expect(bubble(page, reply)).toBeVisible();
    });

    await as(browser, info, 'memberB', async ({ page }) => {
      await page.goto(`/employee/messages?thread=${threadId}`);
      await expect(bubble(page, reply)).toBeVisible();
      await expect(page.locator('.emsg-bubble--logistics .emsg-bubble__side')).toHaveText('Support Services');
    });
  });
});
