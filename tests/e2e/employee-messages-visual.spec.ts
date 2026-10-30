import { expect, test, type Browser, type BrowserContext, type Locator, type Page, type TestInfo } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { authDirectory, type PersonaName } from './employee-messages.setup';

/*
 * Visual acceptance of the Support Services release screens, in real browsers (desktop 1440 x 900 and phone 390 x 844,
 * light mode: the Hub's Filament panels are light-only). Each screen is photographed to test-results/visual and also
 * measured: no sideways scrolling, phone touch targets of at least 44 px, one select chevron, and readable,
 * distinct message bubbles. Synthetic personas only (EmployeeMessagesE2ESeeder + VisualQaE2ESeeder).
 */

const MIN_TARGET_PX = 44;
const directory = join(process.cwd(), 'test-results', 'visual');

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
    colorScheme: 'light',
    storageState: join(authDirectory, `${persona}.json`),
  });

  return { context, page: await context.newPage() };
}

async function as(browser: Browser, info: TestInfo, persona: PersonaName, scenario: (session: Session) => Promise<void>): Promise<void> {
  const session = await signIn(browser, info, persona);
  try {
    await scenario(session);
  } finally {
    await session.context.close();
  }
}

async function shot(target: Page | Locator, info: TestInfo, name: string, fullPage = false): Promise<void> {
  mkdirSync(directory, { recursive: true });
  const path = join(directory, `${info.project.name}-support-${name}.png`);
  if ('goto' in target) await target.screenshot({ path, fullPage });
  else await target.screenshot({ path });
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'the page must not scroll sideways').toBeLessThanOrEqual(1);
}

async function expectTouchTarget(info: TestInfo, control: Locator, label: string): Promise<void> {
  if (!info.project.use.isMobile) return;
  const box = await control.boundingBox();
  expect(box?.height ?? 0, `${label} touch target height`).toBeGreaterThanOrEqual(MIN_TARGET_PX);
}

/** WCAG contrast ratio of two CSS colours as the browser resolves them. */
const contrast = (page: Page, foreground: string, background: string) =>
  page.evaluate(([fg, bg]) => {
    const parse = (value: string): number[] => {
      const probe = document.createElement('canvas').getContext('2d');
      if (!probe) throw new Error('no canvas');
      probe.fillStyle = '#000';
      probe.fillStyle = value;
      const normalized = probe.fillStyle as string;
      if (normalized.startsWith('#')) return [1, 3, 5].map((i) => parseInt(normalized.slice(i, i + 2), 16));
      return (normalized.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);
    };
    const luminance = ([r, g, b]: number[]) => {
      const channel = (v: number) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; };
      return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
    };
    const a = luminance(parse(fg));
    const b = luminance(parse(bg));

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  }, [foreground, background]);

const styleOf = (locator: Locator, properties: string[]) =>
  locator.evaluate((element, names) => {
    const style = getComputedStyle(element);

    return Object.fromEntries(names.map((name) => [name, style.getPropertyValue(name)]));
  }, properties);

test.describe.serial('Support Services visual QA', () => {
  const token = () => `vqa${Date.now().toString(36)}`;
  const state = { subject: '', body: '', reply: '' };

  test('member Home: Message Support Services sits beside Vehicle Checkout', async ({ browser }, info) => {
    await as(browser, info, 'memberA', async ({ page }) => {
      await page.goto('/');
      const actions = page.locator('.hub-page-header__actions');
      const message = actions.getByRole('link', { name: 'Message Support Services', exact: true });
      await expect(message).toBeVisible();
      await expectTouchTarget(info, message, 'Message Support Services');
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '01-home', true);
      await shot(actions, info, '01-home-actions');
    });
  });

  test('composer: one select chevron, label "Message", readable fields', async ({ browser }, info) => {
    state.subject = `Visual QA helmet pads ${token()}`;
    state.body = `Need two helmet pads for Engine 5 ${token()}`;
    await as(browser, info, 'memberA', async ({ page }) => {
      await page.goto('/employee/messages?compose=1');
      const select = page.locator('select').first();
      await expect(select).toBeVisible();
      await expect(page.locator('label[for="emsg-member-new-body"]')).toHaveText(/^\s*Message\s*$/);
      const style = await styleOf(select, ['appearance', 'background-image', 'background-repeat']);
      const arrows = (style['background-image'].match(/url\(/g) ?? []).length;
      expect(arrows, `exactly one chevron image on the select (${style['background-image'].slice(0, 80)})`).toBeLessThanOrEqual(1);
      expect(style['background-repeat'], 'the chevron does not tile').toMatch(/^no-repeat/);
      await expectTouchTarget(info, select, 'reason select');
      await expectTouchTarget(info, page.getByRole('button', { name: 'Send to Support Services' }), 'Send to Support Services');
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '02-composer', true);
      await shot(select, info, '02-composer-select');

      await page.locator('#emsg-member-new-subject').fill(state.subject);
      await page.locator('#emsg-member-new-body').fill(state.body);
      await shot(page, info, '02-composer-filled', true);
      await page.getByRole('button', { name: 'Send to Support Services' }).click();
      await expect(page.locator('.emsg-bubble__body', { hasText: state.body })).toBeVisible();
    });
  });

  test('logistics board: Support Services Messages and Equipment Change Requests cards; the reply is sent', async ({ browser }, info) => {
    state.reply = `Pads are on the shelf ${token()}`;
    await as(browser, info, 'responder', async ({ page }) => {
      await page.goto('/admin/logistics-board'); // the former /admin widgets
      const messages = page.locator('#emsg-widget-title');
      const equipment = page.getByRole('heading', { name: /Equipment Change Requests/ });
      await expect(messages).toBeVisible();
      await expect(equipment).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '03-admin-home', true);
      await shot(page.locator('.emsg-panel').first(), info, '03-admin-messages-card');
      await shot(page.locator('section, div').filter({ has: equipment }).last(), info, '03-admin-equipment-card');

      const row = page.locator('button[data-emsg-row]', { hasText: state.body });
      await expect(row).toBeVisible();
      await row.click();
      await shot(page, info, '03-admin-messages-expanded', true);
      await page.getByLabel('Reply to Casey Alpha').fill(state.reply);
      await page.getByRole('button', { name: 'Send reply' }).click();
      await expect(page.locator('.emsg-bubble__body', { hasText: state.reply })).toBeVisible();
    });
  });

  test('thread: sent and received bubbles are distinct from the page and readable', async ({ browser }, info) => {
    await as(browser, info, 'memberA', async ({ page }) => {
      await page.goto('/employee/messages');
      await page.locator('.emsg-row', { hasText: state.subject }).first().click();
      // One pane at a time on a phone, both panes beside each other on a desktop.
      const allMessages = page.getByRole('button', { name: 'All messages' });
      const listPanel = page.locator('section.emsg-panel[aria-labelledby="emsg-member-list-heading"]');
      if (info.project.use.isMobile) {
        await expect(listPanel, 'the list hides while a conversation is open on a phone').toBeHidden();
        await expect(allMessages).toBeVisible();
        await expectTouchTarget(info, allMessages, 'All messages');
      } else {
        await expect(listPanel).toBeVisible();
        await expect(allMessages, 'no back control beside the list on a desktop').toBeHidden();
      }
      const mine = page.locator('.emsg-bubble--mine').first();
      const theirs = page.locator('.emsg-bubble:not(.emsg-bubble--mine)').first();
      await expect(mine).toBeVisible();
      await expect(theirs).toBeVisible();
      const scroll = page.locator('.emsg-thread-scroll');
      const page_ = (await styleOf(scroll, ['background-color']))['background-color'];
      for (const [name, bubble] of [['sent', mine], ['received', theirs]] as const) {
        const body = bubble.locator('.emsg-bubble__body');
        const { bg, fg, border } = await bubble.evaluate((element) => {
          const style = getComputedStyle(element);
          const inner = element.querySelector('.emsg-bubble__body') ?? element;

          return { bg: style.backgroundColor, fg: getComputedStyle(inner).color, border: style.borderTopColor };
        });
        const text = await contrast(page, fg, bg);
        expect(text, `${name} bubble text contrast`).toBeGreaterThanOrEqual(4.5);
        // Distinct from what surrounds it: a different fill, or a border that stands out from that fill.
        const fill = await contrast(page, bg, page_);
        const edge = await contrast(page, border, page_);
        expect(Math.max(fill, edge), `${name} bubble vs page (fill ${fill.toFixed(2)}, border ${edge.toFixed(2)})`).toBeGreaterThanOrEqual(1.15);
        await expect(body).toBeVisible();
      }
      const sentBg = await mine.evaluate((element) => getComputedStyle(element).backgroundColor);
      const receivedBg = await theirs.evaluate((element) => getComputedStyle(element).backgroundColor);
      expect(sentBg, 'sent and received bubbles differ').not.toBe(receivedBg);
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '04-thread', true);
    });
  });

  test('equipment change request: the review page shows before and after', async ({ browser }, info) => {
    await as(browser, info, 'responder', async ({ page }) => {
      await page.goto('/admin/logistics-board');
      const link = page.locator('a[href*="inventory-corrections"]').first();
      await expect(link).toBeVisible();
      await link.click();
      await expect(page).toHaveURL(/inventory-corrections\/\d+/);
      await expect(page.getByText(/Passports/).first()).toBeVisible();
      await expect(page.getByText('Now on the checklist').first()).toBeVisible();
      await expect(page.getByText('Requested', { exact: true }).first()).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '05-equipment-review', true);
    });
  });

  test('vehicle issue page: Back to Admin control and breadcrumb', async ({ browser }, info) => {
    await as(browser, info, 'superAdmin', async ({ page }) => {
      await page.goto('/admin/inspections/1?return_to=%2Fadmin');
      const back = page.locator('[data-hub-back]').first();
      await expect(back).toBeVisible();
      await expect(back).toContainText('Back to Admin');
      await expectTouchTarget(info, back, 'Back to Admin');
      const crumbs = page.locator('nav.fi-breadcrumbs').first();
      // Filament hides the trail below 640 px; the Back control above is the phone's way out.
      if (info.project.use.isMobile) await expect(crumbs).toBeHidden();
      else await expect(crumbs).toBeVisible();
      await expect(crumbs).toContainText('Daily Checkouts');
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '06-vehicle-issue', true);
      await back.click();
      await expect(page).toHaveURL(/\/admin\/?$/);
    });
  });
});
