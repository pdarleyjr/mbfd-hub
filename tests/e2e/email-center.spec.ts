import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { expectNoHorizontalOverflow } from './support/no-horizontal-overflow';

/*
 * The Email Center (/admin/email) in a real browser, on a disposable database (database/seeders/EmailCenterE2ESeeder.php).
 *   npm run test:email-center-e2e
 *
 * Layout and accessibility checks run at every width; flows that change data run at the widths that matter
 * (desktop for the three panes, a phone for the one-column and sheet behaviour). Nothing here reaches Cloudflare:
 * a send is exercised up to the point the Hub itself refuses or pauses it, which is also the wording a person sees.
 */

const ADMIN = '99871';
const VIEWER = '99872';
const MEMBER = '99873';

const PRIMARY = { background: 'rgb(30, 78, 140)', color: 'rgb(255, 255, 255)', radius: '8px' } as const;

async function signIn(page: Page, employeeId: string): Promise<void> {
  await page.goto('/admin');
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password', { exact: true }).fill(process.env.EMAIL_CENTER_E2E_PASSWORD!);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(url => !/\/login/.test(url.pathname));
}

/** Console and page errors. A message frame has no script to block, so a blocked script here is a real failure. */
function watchErrors(page: Page): () => string[] {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() !== 'error') return;
    errors.push(message.text());
  });
  page.on('response', response => { if (response.status() >= 500) errors.push(`HTTP ${response.status()} ${response.url()}`); });

  return () => errors;
}

async function openMail(page: Page, query = ''): Promise<void> {
  await page.goto(`/admin/email${query}`);
  await expect(page.locator('[data-mail-shell]')).toBeVisible();
  await page.waitForFunction(() => Boolean((window as unknown as { Livewire?: unknown }).Livewire));
}

/** A list row by its subject line, exactly. */
const bySubject = (page: Page, subject: string): Locator => page.locator('[data-mail-row]').filter({
  has: page.locator('.mail-row__subject').filter({ hasText: new RegExp(`^${subject.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`) }),
}).first();

async function openConversation(page: Page, subject: string): Promise<void> {
  await bySubject(page, subject).click();
  await expect(page.locator('[data-mail-reader]')).toBeVisible();
  await expect(page.locator('.mail-reader__subject')).toHaveText(subject);
}

const widthOf = (info: TestInfo): number => info.project.use.viewport?.width ?? 1280;
const isPhone = (info: TestInfo): boolean => widthOf(info) < 640;
const runOnly = (info: TestInfo, projects: string[]): void => test.skip(!projects.includes(info.project.name), `runs at ${projects.join(', ')}`);

const busyReasons = (page: Page): Promise<string[]> => page.evaluate(() => Array.from((window as unknown as { __mbfdBusy?: Set<string> }).__mbfdBusy ?? []).filter(reason => reason.startsWith('email-compose:')));

test.describe('layout and display', () => {
  test('folders, list and reading pane arrange themselves for the width', async ({ page }, info) => {
    await signIn(page, ADMIN);
    await openMail(page);
    const width = widthOf(info);
    const nav = page.locator('.mail-nav');
    const list = page.locator('.mail-listpane');
    const read = page.locator('.mail-readpane');

    await expect(list).toBeVisible();
    await expect(nav).toBeVisible();
    if (width >= 1280) {
      const [n, l, r] = await Promise.all([nav.boundingBox(), list.boundingBox(), read.boundingBox()]);
      expect(n!.x).toBeLessThan(l!.x);
      expect(l!.x).toBeLessThan(r!.x);
      expect(Math.abs(n!.y - l!.y)).toBeLessThan(2);
      await expect(read).toBeVisible();
    } else if (width >= 1024) {
      const [n, l, r] = await Promise.all([nav.boundingBox(), list.boundingBox(), read.boundingBox()]);
      expect(n!.y + n!.height).toBeLessThanOrEqual(l!.y + 1);
      expect(l!.x).toBeLessThan(r!.x);
    } else {
      await expect(read).toBeHidden();
      await openConversation(page, 'Turnout gear delivery window');
      await expect(list).toBeHidden();
      await expect(page.locator('[data-mail-reader] [data-mail-back]')).toBeVisible();
      await page.locator('[data-mail-reader] [data-mail-back]').click();
      await expect(list).toBeVisible();
      await expect(read).toBeHidden();
    }
    await page.screenshot({ path: info.outputPath('layout.png'), fullPage: true });
  });

  test('no horizontal scrolling on the list, a conversation of very long strings, health or compose', async ({ page }, info) => {
    await signIn(page, ADMIN);
    await openMail(page);
    await expectNoHorizontalOverflow(page, '.mail-shell');
    await openConversation(page, 'Unbroken' + 'subjectwithnospaces'.repeat(12));
    await expectNoHorizontalOverflow(page, '.mail-shell');
    await page.screenshot({ path: info.outputPath('long-strings.png'), fullPage: true });

    await openMail(page, '?folder=health');
    await expect(page.getByRole('heading', { name: 'Email health' })).toBeVisible();
    await expectNoHorizontalOverflow(page, '.mail-shell');
    await page.screenshot({ path: info.outputPath('health.png'), fullPage: true });

    await openMail(page, '?compose=new');
    await expect(page.locator('[data-mail-compose]')).toBeVisible();
    await expectNoHorizontalOverflow(page, '.mail-shell');
    await page.screenshot({ path: info.outputPath('compose-new.png'), fullPage: true });
    await page.locator('[data-mail-compose]').getByRole('button', { name: 'Save and close' }).click();
  });

  test('primary buttons compute white text on the Hub blue, and touch targets are at least 44px', async ({ page }, info) => {
    await signIn(page, ADMIN);
    await openMail(page);
    const compose = page.locator('.mail-compose-btn');
    await expect(compose).toBeVisible();
    const style = await compose.evaluate(element => {
      const computed = getComputedStyle(element);
      return { background: computed.backgroundColor, color: computed.color, radius: computed.borderTopLeftRadius, height: element.getBoundingClientRect().height };
    });
    expect(style.background).toBe(PRIMARY.background);
    expect(style.color).toBe(PRIMARY.color);
    expect(style.radius).toBe(PRIMARY.radius);
    expect(style.color).not.toBe(style.background);
    expect(style.height).toBeGreaterThanOrEqual(43.5);

    await openConversation(page, 'Turnout gear delivery window');
    const reply = page.locator('[data-mail-reader]').getByRole('button', { name: 'Reply', exact: true });
    const replyStyle = await reply.evaluate(element => ({ background: getComputedStyle(element).backgroundColor, color: getComputedStyle(element).color }));
    expect(replyStyle).toEqual({ background: PRIMARY.background, color: PRIMARY.color });

    for (const locator of [page.locator('[data-mail-row]').first(), page.locator('.mail-filter').first(), page.locator('[data-mail-reader] .mail-btn').first()]) {
      const box = await locator.boundingBox();
      if (box !== null) expect(box.height, 'touch target height').toBeGreaterThanOrEqual(43.5);
    }

    await reply.click();
    const send = page.locator('[data-mail-send]');
    await expect(send).toBeVisible();
    // Move the pointer off the button (the Reply click can leave it right there) so the resting colours are read.
    await page.mouse.move(1, 1);
    await expect(send).toHaveCSS('background-color', PRIMARY.background);
    await expect(send).toHaveCSS('color', PRIMARY.color);
    await page.screenshot({ path: info.outputPath('compose-reply.png'), fullPage: true });
    await page.locator('[data-mail-compose]').getByRole('button', { name: 'Save and close' }).click();
  });

  test('a long conversation shows the whole thread in order, with sender and recipient details and no scripts inside the message', async ({ page }, info) => {
    const errors = watchErrors(page);
    await signIn(page, ADMIN);
    await openMail(page);
    await openConversation(page, 'Turnout gear delivery window');
    const messages = page.locator('[data-mail-reader] .mail-message');
    await expect(messages).toHaveCount(3);
    const times = await messages.locator('time.mail-message__time').evaluateAll(nodes => nodes.map(node => Date.parse(node.getAttribute('datetime') ?? '')));
    expect([...times].sort((a, b) => a - b)).toEqual(times);

    await messages.first().locator('summary', { hasText: 'Sender and recipient details' }).click();
    await expect(messages.first().locator('.mail-details__list')).toContainText('dispatch@vendor.example.test');
    await expect(messages.first().locator('.mail-details__list')).toContainText('colleague@example.test');

    // The HTML body is a sandboxed frame without scripts or the Hub's origin.
    const frame = page.locator('[data-mail-reader] iframe.mail-frame').first();
    await expect(frame).toHaveAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
    const inner = page.frameLocator('[data-mail-reader] iframe.mail-frame').first();
    await expect(inner.getByText('Please confirm the delivery window.')).toBeVisible();
    await expect(inner.getByRole('link', { name: 'Track the shipment' })).toHaveAttribute('target', '_blank');
    // The frame is sized to its content: it is neither a sliver nor a tall box that scrolls inside.
    await expect.poll(() => frame.evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThan(100);
    expect(await frame.evaluate(element => element.getBoundingClientRect().height)).toBeLessThan(420);

    // Attachments, the part that could not be kept, and delivery per recipient in the architecture's words.
    await expect(page.getByRole('link', { name: /packing-list\.txt/ })).toHaveAttribute('href', /\/admin\/email\/messages\/inbound\/\d+\/attachments\/0$/);
    await expect(page.locator('.mail-callout--note').first()).toContainText('could not be kept');
    const delivery = page.locator('.mail-delivery');
    await expect(delivery).toContainText('dispatch@vendor.example.test');
    await expect(delivery.locator('.mail-badge', { hasText: 'Accepted by receiving server' }).first()).toBeVisible();
    await expect(delivery.locator('.mail-badge', { hasText: 'Deferred' })).toBeVisible();
    await expect(delivery).toContainText('private-copy@example.test');
    await expect(delivery).toContainText('cannot show which mailbox folder');
    expect(await page.locator('[data-mail-reader]').innerText()).not.toMatch(/delivered to (the )?inbox/i);

    // Internal notes cannot be mistaken for mail.
    const notes = page.locator('.mail-notes');
    await expect(notes).toContainText('Internal notes');
    await expect(notes).toContainText('Internal only. Never sent.');
    await expect(notes).toContainText('call the vendor if the truck is late');
    expect(await notes.evaluate(element => getComputedStyle(element).borderTopStyle)).toBe('dashed');
    await page.screenshot({ path: info.outputPath('reader.png'), fullPage: true });
    // Reading inside the frame makes Playwright itself try to run a script there, and the sandbox refuses it. Only
    // this test looks inside; every other test keeps treating a blocked script as a real failure.
    expect(errors().filter(error => !/Blocked script execution/.test(error))).toEqual([]);
  });

  test('automated mail is read-only, with no action controls', async ({ page }) => {
    await signIn(page, ADMIN);
    await openMail(page, '?folder=automated');
    await openConversation(page, 'New submission: take-home vehicle inspection');
    const reader = page.locator('[data-mail-reader]');
    await expect(reader).toContainText('read-only');
    await expect(reader.getByRole('button', { name: /Reply|Forward|Archive|Star|Trash|Mark unread/ })).toHaveCount(0);
    await expect(reader.locator('.mail-notes')).toHaveCount(0);
    await expect(reader.locator('.mail-organize')).toHaveCount(0);
  });

  test('health is concise and says complaints are not reported rather than zero', async ({ page }) => {
    await signIn(page, ADMIN);
    await openMail(page, '?folder=health');
    const health = page.locator('.mail-health');
    await expect(health).toContainText('Sending');
    await expect(health).toContainText('Not reported by Cloudflare analytics');
    await expect(health).not.toContainText(/Complaint reported\s*0/);
    expect(await health.innerText()).not.toMatch(/inbox placement|delivered to inbox/i);
  });
});

test.describe('accessibility', () => {
  test('axe finds nothing on the list, a conversation, the composer or health', async ({ page }, info) => {
    runOnly(info, ['w320', 'w390', 'w768', 'w1024', 'w1440']);
    await signIn(page, ADMIN);
    const scan = async (label: string) => {
      const result = await new AxeBuilder({ page }).include('.mail-shell').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
      expect(result.violations.map(violation => `${label}: ${violation.id} (${violation.nodes.length}) ${violation.nodes[0]?.target.join(' ')}`), label).toEqual([]);
    };
    await openMail(page);
    await scan('list');
    await openConversation(page, 'Turnout gear delivery window');
    await expect(page.locator('[data-mail-reader] .mail-message').first()).toBeVisible();
    await scan('conversation');
    await page.locator('[data-mail-reader]').getByRole('button', { name: 'Reply', exact: true }).click();
    await expect(page.locator('[data-mail-compose] trix-editor')).toBeVisible();
    await scan('composer');
    await page.locator('[data-mail-compose]').getByRole('button', { name: 'Save and close' }).click();
    await openMail(page, '?folder=health');
    await scan('health');
  });

  test('the search is a labelled search landmark, folders are a navigation and each unread row says so', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    await signIn(page, ADMIN);
    await openMail(page);
    await expect(page.getByRole('search').getByLabel('Search mail')).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Folders' })).toBeVisible();
    await expect(page.getByRole('group', { name: 'Filter conversations' }).getByRole('button', { name: 'Unread' })).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator('[data-mail-row][data-unread="1"] .mail-sr').first()).toHaveText(/Unread/);
    await expect(page.locator('[data-mail-folder="inbox"]')).toHaveAttribute('aria-current', 'page');
  });

  test('text reflows at 200 percent zoom: the same layout in half the width with no horizontal scroll', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    await page.setViewportSize({ width: 640, height: 450 });
    await signIn(page, ADMIN);
    await openMail(page);
    await expectNoHorizontalOverflow(page, '.mail-shell');
    await openConversation(page, 'Turnout gear delivery window');
    await expectNoHorizontalOverflow(page, '.mail-shell');
    await page.screenshot({ path: info.outputPath('zoom-200.png'), fullPage: true });
    await page.setViewportSize({ width: 320, height: 568 });
    await expectNoHorizontalOverflow(page, '.mail-shell');
  });
});

test.describe('opening, searching and the address bar', () => {
  test('a conversation opens, survives a refresh, the Back button walks between them and bad links are harmless', async ({ page }, info) => {
    runOnly(info, ['w1440', 'w390']);
    await signIn(page, ADMIN);
    await openMail(page);
    await openConversation(page, 'Turnout gear delivery window');
    await expect(page).toHaveURL(/thread=\d+/);
    const first = new URL(page.url()).searchParams.get('thread');
    if (isPhone(info)) {
      await page.locator('[data-mail-reader] [data-mail-back]').click();
      await expect(page.locator('.mail-listpane')).toBeVisible();
    }
    await openConversation(page, 'Bulk conversation 1');
    const second = new URL(page.url()).searchParams.get('thread');
    expect(second).not.toBe(first);
    await page.reload();
    await expect(page.locator('.mail-reader__subject')).toHaveText('Bulk conversation 1');
    await page.goBack();
    if (isPhone(info)) {
      // On a phone the in-app Back button made the list its own history entry; Back lands on it first.
      await expect.poll(() => new URL(page.url()).searchParams.get('thread')).toBe(null);
      await expect(page.locator('.mail-listpane')).toBeVisible();
      await page.goBack();
    }
    await expect.poll(() => new URL(page.url()).searchParams.get('thread')).toBe(first);
    await expect(page.locator('.mail-reader__subject')).toHaveText('Turnout gear delivery window');

    await page.goto('/admin/email?thread=999999');
    await expect(page.getByText('That conversation could not be found')).toBeVisible();
    await page.goto('/admin/email?thread=abc&folder=nonsense&compose=zzz&draft=-4&msg=x');
    await expect(page.locator('[data-mail-shell]')).toBeVisible();
    await expect(page.locator('[data-mail-compose]')).toHaveCount(0);
  });

  test('search narrows the list, lives in the address bar and the filters combine', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    await signIn(page, ADMIN);
    await openMail(page);
    await page.getByRole('search').getByLabel('Search mail').fill('Bulk conversation 7');
    await expect(page.locator('[data-mail-row]')).toHaveCount(11);
    await expect(bySubject(page, 'Bulk conversation 7')).toBeVisible();
    await expect(page).toHaveURL(/q=Bulk(\+|%20)conversation(\+|%20)7/);
    await page.reload();
    await expect(page.getByRole('search').getByLabel('Search mail')).toHaveValue('Bulk conversation 7');
    await expect(page.locator('[data-mail-row]')).toHaveCount(11);
    await page.getByRole('search').getByLabel('Search mail').fill('zzz-no-such-text');
    await expect(page.getByText('No conversations match')).toBeVisible();
    await page.getByRole('button', { name: 'Clear search and filters' }).click();
    await expect(bySubject(page, 'Turnout gear delivery window')).toBeVisible();

    await page.getByRole('button', { name: 'High priority', exact: true }).click();
    await expect(page.locator('[data-mail-row]')).toHaveCount(1);
    await expect(bySubject(page, 'Turnout gear delivery window')).toBeVisible();
    await page.getByRole('button', { name: 'High priority', exact: true }).click();
    await page.getByRole('button', { name: 'Has attachment' }).click();
    await expect(page.locator('[data-mail-row]')).toHaveCount(1);
    await page.getByRole('button', { name: 'Has attachment' }).click();
    await page.getByLabel('Filter by label').selectOption('Logistics');
    await expect(page.locator('[data-mail-row]')).toHaveCount(1);
    await page.getByRole('button', { name: 'Clear', exact: true }).click();
    await expect(page.locator('[data-mail-row]').nth(2)).toBeVisible();
  });

  test('a large mailbox loads thirty at a time with a cursor', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    await signIn(page, ADMIN);
    await openMail(page);
    await expect(page.locator('[data-mail-row]')).toHaveCount(30);
    await page.getByRole('button', { name: 'Show older conversations' }).click();
    await expect(page.locator('[data-mail-row]')).toHaveCount(60);
    await page.getByRole('button', { name: 'Show older conversations' }).click();
    await expect(page.locator('[data-mail-row]')).toHaveCount(90);
  });
});

test.describe('acting on a conversation', () => {
  test('star, unread, priority, labels, assignee, snooze, notes and archive all work and say what happened', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    const errors = watchErrors(page);
    await signIn(page, ADMIN);
    await openMail(page, '?q=Bulk+conversation+9');
    await openConversation(page, 'Bulk conversation 9');
    const reader = page.locator('[data-mail-reader]');

    await reader.getByRole('button', { name: 'Star', exact: true }).click();
    await expect(reader.getByRole('button', { name: 'Starred' })).toHaveAttribute('aria-pressed', 'true');
    await reader.getByRole('button', { name: 'Starred' }).click();
    await expect(reader.getByRole('button', { name: 'Star', exact: true })).toBeVisible();

    await reader.getByRole('button', { name: 'Mark unread' }).click();
    await expect(reader.locator('.mail-callout--ok')).toContainText('Marked as unread');
    await expect(page.locator('[data-mail-row][aria-current="true"]')).toHaveAttribute('data-unread', '1');

    await reader.locator('summary', { hasText: 'Assign, priority, snooze and labels' }).click();
    const priority = reader.getByRole('button', { name: 'High priority', exact: true });
    await expect(priority).toHaveAttribute('aria-pressed', 'false');
    await priority.click();
    await expect(reader.locator('.mail-callout--ok')).toContainText('high priority');
    await expect(priority).toHaveAttribute('aria-pressed', 'true');
    await expect(reader.locator('.mail-reader__summary .mail-tag--high')).toBeVisible();
    await priority.click();
    await expect(priority).toHaveAttribute('aria-pressed', 'false');
    await expect(reader.locator('.mail-reader__summary .mail-tag--high')).toHaveCount(0);

    await reader.getByLabel('Labels', { exact: true }).fill('E2E label');
    await reader.getByRole('button', { name: 'Add label' }).click();
    await expect(reader.locator('.mail-chips')).toContainText('E2E label');
    await reader.getByRole('button', { name: 'Remove label E2E label' }).click();
    await expect(reader.locator('.mail-chips')).not.toContainText('E2E label');

    await reader.getByLabel('Assigned to').selectOption({ label: 'Email Test Admin' });
    await expect(reader.locator('.mail-callout--ok')).toContainText('Assigned to Email Test Admin');
    await reader.getByLabel('Assigned to').selectOption({ label: 'Nobody' });
    await expect(reader.locator('.mail-callout--ok')).toContainText('Assignee removed');

    await reader.getByRole('button', { name: 'Snooze', exact: true }).click();
    await expect(reader.locator('.mail-callout--ok')).toContainText('Snoozed until');
    await reader.getByRole('button', { name: 'Wake now' }).click();
    await expect(reader.locator('.mail-callout--ok')).toContainText('Snooze removed');

    await reader.getByLabel('Add an internal note').fill('E2E note, visible to colleagues only');
    await reader.getByRole('button', { name: 'Add internal note' }).click();
    await expect(reader.locator('.mail-note')).toContainText('E2E note');
    await expect(reader.locator('.mail-callout--ok')).toContainText('never sent');

    await reader.getByRole('button', { name: 'Archive', exact: true }).click();
    await expect(reader.locator('.mail-callout--ok')).toContainText('Archived');
    await expect(reader.locator('.mail-reader__summary .mail-tag', { hasText: 'Archived' })).toBeVisible();
    await reader.getByRole('button', { name: 'Move to Inbox' }).click();
    await expect(reader.locator('.mail-callout--ok')).toContainText('Moved back to the Inbox');

    await reader.locator('summary', { hasText: /^Activity/ }).click();
    await expect(reader.locator('.mail-activity__list')).toContainText('archived this conversation');
    expect(errors()).toEqual([]);
  });

  test('trash asks first, moves the conversation to Trash and Restore brings it back', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    await signIn(page, ADMIN);
    await openMail(page, '?q=Bulk+conversation+11');
    await openConversation(page, 'Bulk conversation 11');
    const reader = page.locator('[data-mail-reader]');
    page.once('dialog', dialog => dialog.dismiss());
    await reader.getByRole('button', { name: 'Trash', exact: true }).click();
    await expect(reader.locator('.mail-reader__summary .mail-tag--bad')).toHaveCount(0);
    page.once('dialog', dialog => dialog.accept());
    await reader.getByRole('button', { name: 'Trash', exact: true }).click();
    await expect(reader.locator('.mail-reader__summary .mail-tag--bad')).toContainText('In Trash');
    await expect(reader.getByRole('button', { name: 'Reply', exact: true })).toHaveCount(0);
    await reader.getByRole('button', { name: 'Restore from Trash' }).click();
    await expect(reader.locator('.mail-reader__summary .mail-tag--bad')).toHaveCount(0);
  });

  test('a read-only viewer can read and star but sees no way to write, assign, archive or trash', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    await signIn(page, VIEWER);
    await openMail(page);
    await expect(page.locator('.mail-nav .mail-compose-btn')).toHaveCount(0);
    await expect(page.locator('[data-mail-folder="trash"]')).toHaveCount(0);
    await openConversation(page, 'Turnout gear delivery window');
    const reader = page.locator('[data-mail-reader]');
    await expect(reader.getByRole('button', { name: 'Star', exact: true })).toBeVisible();
    for (const name of ['Reply', 'Reply all', 'Forward', 'Archive', 'Trash', 'Add internal note']) {
      await expect(reader.getByRole('button', { name, exact: true })).toHaveCount(0);
    }
    await expect(reader.locator('.mail-organize')).toHaveCount(0);
    // The viewer never sees the sender's Bcc.
    await expect(page.locator('.mail-delivery')).not.toContainText('private-copy@example.test');
    await page.goto('/admin/email?compose=new');
    await expect(page.locator('[data-mail-compose]')).toHaveCount(0);
  });
});

test.describe('writing', () => {
  test('a new message: suggestions, signature, autosave, recovery after a reload, then discard with confirmation', async ({ page }, info) => {
    runOnly(info, ['w1440', 'w390']);
    const errors = watchErrors(page);
    await signIn(page, ADMIN);
    await openMail(page);
    await page.locator('.mail-compose-btn').click();
    const compose = page.locator('[data-mail-compose]');
    await expect(compose).toBeVisible();
    await expect(compose).toHaveAttribute('data-mbfd-unsaved-work-guard', '');
    await expect(page).toHaveURL(/compose=new/);
    await expect(page).toHaveURL(/draft=\d+/);

    // Limits are visible before anything is attached; the signature is plain, editable text.
    await expect(compose).toContainText('Up to 5 files');
    await expect(compose.locator('trix-editor')).toContainText('Email Test Admin');
    await expect(compose.locator('trix-editor')).toContainText('Captain');
    await expect(compose.locator('trix-editor')).toContainText('Miami Beach Fire Department');

    // Authorized suggestions come from the Hub's own directory; Bcc stays hidden until asked for.
    const to = compose.getByRole('combobox', { name: 'To' });
    await to.fill('Viewer');
    await expect(compose.getByRole('option', { name: /Email Test Viewer/ })).toBeVisible();
    await to.press('ArrowDown');
    await to.press('Enter');
    await expect(compose.locator('.mail-chip', { hasText: 'emailfixture99872@miamibeachfl.gov' })).toBeVisible();
    await expect(compose.getByRole('combobox', { name: 'Bcc' })).toBeHidden();
    await compose.getByRole('button', { name: 'Add Bcc' }).click();
    await compose.getByRole('combobox', { name: 'Bcc' }).fill('audit@example.test,');
    await expect(compose.locator('.mail-chip', { hasText: 'audit@example.test' })).toBeVisible();

    await compose.getByLabel('Subject').fill('E2E draft subject');
    // Moving into the message puts the cursor above the signature, not under it.
    await compose.locator('trix-editor').click({ position: { x: 24, y: 16 } });
    await page.keyboard.type('Typed by the browser test.');
    const written = await compose.locator('trix-editor').innerText();
    expect(written).toContain('Typed by the browser test.');
    expect(written.indexOf('Typed by the browser test.')).toBeLessThan(written.indexOf('Email Test Admin'));
    await expect(page.locator('[data-mail-save-status]')).toContainText(/Draft saved at/, { timeout: 15_000 });

    // Busy guard: the PWA update system will not reload this page while a message is open.
    expect((await busyReasons(page)).length).toBeGreaterThan(0);

    await page.reload();
    const recovered = page.locator('[data-mail-compose]');
    await expect(recovered.getByLabel('Subject')).toHaveValue('E2E draft subject');
    await expect(recovered.locator('.mail-chip', { hasText: 'emailfixture99872@miamibeachfl.gov' })).toBeVisible();
    await expect(recovered.locator('.mail-chip', { hasText: 'audit@example.test' })).toBeVisible();
    await expect(recovered.locator('trix-editor')).toContainText('Typed by the browser test.');
    await page.screenshot({ path: info.outputPath('compose-recovered.png'), fullPage: true });

    // Leaving keeps the draft; it is listed in Drafts and reopens.
    await recovered.getByRole('button', { name: 'Save and close' }).click();
    await expect(page.locator('[data-mail-compose]')).toHaveCount(0);
    if (isPhone(info)) await page.locator('#mail-folder-select').selectOption('drafts');
    else await page.locator('[data-mail-folder="drafts"]').click();
    await expect(page.locator('[data-mail-row]').filter({ hasText: 'E2E draft subject' }).first()).toBeVisible();
    await page.locator('[data-mail-row]').filter({ hasText: 'E2E draft subject' }).first().click();
    await expect(page.locator('[data-mail-compose]').getByLabel('Subject')).toHaveValue('E2E draft subject');

    page.once('dialog', dialog => dialog.dismiss());
    await page.locator('[data-mail-compose]').getByRole('button', { name: 'Discard draft' }).click();
    await expect(page.locator('[data-mail-compose]').getByLabel('Subject')).toHaveValue('E2E draft subject');
    page.once('dialog', dialog => dialog.accept());
    await page.locator('[data-mail-compose]').getByRole('button', { name: 'Discard draft' }).click();
    await expect(page.locator('[data-mail-compose]')).toHaveCount(0);
    await expect(page.locator('[data-mail-draft]').filter({ hasText: 'E2E draft subject' })).toHaveCount(0);
    expect(await busyReasons(page)).toEqual([]);
    expect(errors()).toEqual([]);
  });

  test('send says plainly what happened: a missing recipient, too many recipients, and a send the Hub will not make', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    await signIn(page, ADMIN);
    await openMail(page, '?compose=new');
    const compose = page.locator('[data-mail-compose]');
    await expect(compose).toBeVisible();
    await compose.getByLabel('Subject').fill('E2E refusal');
    await compose.locator('trix-editor').click({ position: { x: 24, y: 16 } });
    await page.keyboard.type('Body text.');
    await expect(compose.locator('trix-editor')).toContainText('Body text.');
    await compose.locator('[data-mail-send]').click();
    const refusal = compose.locator('.mail-callout--error');
    await expect(refusal).toContainText('Add at least one recipient in To');
    await expect(refusal).toContainText('Nothing left the Hub');
    // Dismissing it really removes it, even straight after the refusal arrives.
    await compose.getByRole('button', { name: 'Keep editing' }).click();
    await expect(refusal).toHaveCount(0);

    const to = compose.getByRole('combobox', { name: 'To' });
    for (const n of [1, 2, 3, 4, 5, 6]) await to.fill(`person${n}@example.test,`);
    await expect(compose.getByText(/sends to at most/)).toBeVisible();
    await expect(compose.getByText(/Department Updates/).first()).toBeVisible();
    await expect(compose.locator('[data-mail-send]')).toBeDisabled();
    await page.screenshot({ path: info.outputPath('too-many.png'), fullPage: true });
    for (let n = 6; n > 1; n--) await compose.getByRole('button', { name: `Remove person${n}@example.test` }).click();
    await expect(compose.locator('[data-mail-send]')).toBeEnabled();
    await expect(compose.locator('.mail-count')).toContainText('1 of');

    // This browser run has no email credentials: the Hub says nothing was sent and the draft is kept.
    await compose.locator('[data-mail-send]').click();
    const outcome = compose.locator('.mail-callout--error, .mail-callout--warn').first();
    await expect(outcome).toContainText(/Not sent|Not confirmed/);
    await expect(outcome).toContainText(/nothing was sent|Nothing was sent|draft is kept|Nothing left the Hub/i);
    // The address on screen reached the server: the refusal is about the service, not a missing recipient.
    await expect(outcome).not.toContainText('Add at least one recipient');
    // The whole message is readable, not cut off by the top of the pane.
    const paneTop = await compose.evaluate(el => {
      let scroller: HTMLElement | null = el;
      while (scroller && !/(auto|scroll)/.test(getComputedStyle(scroller).overflowY)) scroller = scroller.parentElement;
      return (scroller ?? document.documentElement).getBoundingClientRect().top;
    });
    await expect.poll(async () => (await outcome.boundingBox())!.y).toBeGreaterThanOrEqual(paneTop);
    await page.screenshot({ path: info.outputPath('send-refused.png'), fullPage: true });
    await compose.getByRole('button', { name: 'Keep editing' }).click();
    page.once('dialog', dialog => dialog.accept());
    await compose.getByRole('button', { name: 'Discard draft' }).click();
    await expect(page.locator('[data-mail-compose]')).toHaveCount(0);
  });

  test('a reply starts from the conversation, keeps Re: once, and a forward starts with nobody in To', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    await signIn(page, ADMIN);
    await openMail(page);
    await openConversation(page, 'Turnout gear delivery window');
    await page.locator('[data-mail-reader]').getByRole('button', { name: 'Reply all', exact: true }).click();
    const compose = page.locator('[data-mail-compose]');
    await expect(compose.getByLabel('Subject')).toHaveValue('Re: Turnout gear delivery window');
    await expect(compose.locator('.mail-chip', { hasText: 'dispatch@vendor.example.test' })).toBeVisible();
    await expect(compose.locator('trix-editor')).toContainText('wrote:');
    await expect(compose.locator('trix-editor')).not.toContainText('call the vendor');
    await compose.getByRole('button', { name: 'Save and close' }).click();
    await expect(page.locator('[data-mail-reader]')).toBeVisible();

    // A forward is of one message: here the first, which carries the file.
    await page.locator('[data-mail-reader] .mail-message').first().getByRole('button', { name: 'Forward this' }).click();
    const forward = page.locator('[data-mail-compose]');
    await expect(forward.getByLabel('Subject')).toHaveValue('Fwd: Turnout gear delivery window');
    await expect(forward.locator('.mail-recipients__box').first().locator('.mail-chip')).toHaveCount(0);
    await expect(forward.locator('trix-editor')).toContainText('Forwarded message');
    // Only chosen original files are forwarded.
    await forward.getByRole('button', { name: 'Include packing-list.txt' }).click();
    await expect(forward.getByRole('button', { name: 'Remove packing-list.txt' })).toBeVisible();
    await forward.getByRole('button', { name: 'Remove packing-list.txt' }).click();
    await expect(forward.getByRole('button', { name: 'Remove packing-list.txt' })).toHaveCount(0);
    await forward.getByRole('button', { name: 'Save and close' }).click();

    // Discard both drafts so the run leaves nothing behind.
    await page.locator('[data-mail-folder="drafts"]').click();
    await expect(page.locator('[data-mail-draft]').first()).toBeVisible();
    for (let remaining = await page.locator('[data-mail-draft]').count(); remaining > 0; remaining--) {
      // The list refreshes after each discard; a click that lands mid-refresh is simply repeated.
      await expect(async () => {
        await page.locator('[data-mail-draft]').first().click();
        await expect(page.locator('[data-mail-compose]')).toBeVisible({ timeout: 4_000 });
      }).toPass({ timeout: 20_000 });
      page.once('dialog', dialog => dialog.accept());
      await page.locator('[data-mail-compose]').getByRole('button', { name: 'Discard draft' }).click();
      await expect(page.locator('[data-mail-compose]')).toHaveCount(0);
    }
  });

  test('on a phone, composing is a full-height sheet with safe-area padding', async ({ page }, info) => {
    runOnly(info, ['w320', 'w360', 'w390']);
    await signIn(page, ADMIN);
    await openMail(page);
    // A browser tab has no safe areas, so fake them the way the theme documents (the page's CSP would refuse an injected <style>).
    await page.evaluate(() => {
      document.documentElement.style.setProperty('--hub-safe-top', '12px');
      document.documentElement.style.setProperty('--hub-safe-bottom', '24px');
    });
    await page.locator('.mail-compose-btn').click();
    const compose = page.locator('[data-mail-compose]');
    await expect(compose).toBeVisible();
    const box = await compose.boundingBox();
    const viewport = page.viewportSize()!;
    expect(box!.x).toBeLessThanOrEqual(1);
    expect(box!.width).toBeGreaterThanOrEqual(viewport.width - 2);
    expect(box!.height).toBeGreaterThanOrEqual(viewport.height - 2);
    expect(await compose.evaluate(element => getComputedStyle(element).paddingTop)).toBe('12px');
    expect(await page.locator('.mail-compose__footer').evaluate(element => parseFloat(getComputedStyle(element).paddingBottom))).toBeGreaterThanOrEqual(34);
    await page.screenshot({ path: info.outputPath('compose-sheet.png') });
    await expect(compose.locator('[data-mail-send]')).toBeVisible();
    await expect(compose.getByRole('button', { name: 'Save and close' })).toBeVisible();
    await expectNoHorizontalOverflow(page, '.mail-shell');
    await compose.getByRole('button', { name: 'Save and close' }).click();
    await expect(page.locator('[data-mail-compose]')).toHaveCount(0);
  });

  test('an old Compose Email, Inbound Email or Outbound Email link lands in the Email Center', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    await signIn(page, ADMIN);
    await page.goto('/admin/inbound-emails');
    await expect(page).toHaveURL(/\/admin\/email$/);
    await page.goto('/admin/outbound-emails');
    await expect(page).toHaveURL(/\/admin\/email\?folder=sent/);
    await page.goto('/admin/compose-email');
    await expect(page).toHaveURL(/\/admin\/email$/);
    await page.goto('/admin/inbound-emails/1');
    await expect(page).toHaveURL(/\/admin\/email\?folder=inbox&thread=\d+/);
    await expect(page.locator('.mail-reader__subject')).toHaveText('Turnout gear delivery window');
    await page.goto('/admin/outbound-emails/1');
    await expect(page).toHaveURL(/folder=sent&thread=/);
    await page.goto('/admin/compose-email?source=inbound&message=1&mode=reply');
    await expect(page).toHaveURL(/compose=reply/);
    await expect(page.locator('[data-mail-compose]').getByLabel('Subject')).toHaveValue('Re: Turnout gear delivery window');
    await page.locator('[data-mail-compose]').getByRole('button', { name: 'Save and close' }).click();
  });
});

test.describe('keyboard shortcuts', () => {
  test('j and k move through the list, c composes, / searches, ? shows help, and nothing fires while typing', async ({ page }, info) => {
    runOnly(info, ['w1440']);
    await signIn(page, ADMIN);
    await openMail(page);
    await page.locator('body').click({ position: { x: 5, y: 400 } });
    await page.keyboard.press('j');
    await expect(page.locator('[data-mail-row][aria-current="true"]')).toHaveCount(1);
    const first = await page.locator('[data-mail-row][aria-current="true"]').getAttribute('data-mail-thread');
    await page.keyboard.press('j');
    await expect.poll(async () => page.locator('[data-mail-row][aria-current="true"]').getAttribute('data-mail-thread')).not.toBe(first);
    await page.keyboard.press('k');
    await expect.poll(async () => page.locator('[data-mail-row][aria-current="true"]').getAttribute('data-mail-thread')).toBe(first);

    await page.keyboard.press('/');
    await expect(page.getByLabel('Search mail')).toBeFocused();
    await page.keyboard.type('jjkk');
    await expect(page.getByLabel('Search mail')).toHaveValue('jjkk');
    await page.getByLabel('Search mail').fill('');
    await page.locator('body').click({ position: { x: 5, y: 400 } });

    await page.keyboard.press('?');
    const help = page.getByRole('dialog', { name: 'Email keyboard shortcuts' });
    await expect(help).toBeVisible();
    await expect(help).toContainText('Reply all');
    await page.keyboard.press('Escape');
    await expect(help).toBeHidden();

    await page.keyboard.press('c');
    const compose = page.locator('[data-mail-compose]');
    await expect(compose).toBeVisible();
    await compose.getByLabel('Subject').click();
    await page.keyboard.type('crfae?/');
    await expect(compose.getByLabel('Subject')).toHaveValue('crfae?/');
    await expect(help).toBeHidden();
    await compose.getByLabel('Subject').fill('');
    await compose.getByRole('button', { name: 'Save and close' }).click();

    // r acts on the open conversation.
    await openConversation(page, 'Turnout gear delivery window');
    await page.locator('body').click({ position: { x: 5, y: 400 } });
    await page.keyboard.press('r');
    await expect(page.locator('[data-mail-compose]').getByLabel('Subject')).toHaveValue('Re: Turnout gear delivery window');
    await page.locator('[data-mail-compose]').getByRole('button', { name: 'Save and close' }).click();
  });
});

test.describe('entry points', () => {
  test('the header envelope shows the unread count to people who may read mail and opens the Email Center', async ({ page }, info) => {
    runOnly(info, ['w1440', 'w390', 'w320']);
    await signIn(page, VIEWER);
    await page.goto('/admin');
    const icon = page.locator('.fi-topbar a.fi-topbar-mail-btn');
    await expect(icon).toBeVisible();
    await expect(icon).toHaveAttribute('aria-label', /^Email, \d+ unread$/);
    await expect(icon).toHaveAttribute('href', /\/admin\/email$/);
    const badge = page.locator('.fi-topbar .fi-topbar-mail-btn .fi-icon-btn-badge-ctn');
    await expect(badge).toBeVisible();
    const box = await icon.boundingBox();
    expect(box!.width).toBeGreaterThanOrEqual(43.5);
    expect((await badge.boundingBox())!.y).toBeGreaterThanOrEqual(0);
    await expectNoHorizontalOverflow(page, '.fi-topbar');
    await page.screenshot({ path: info.outputPath('header-icon.png') });
    await icon.click();
    await expect(page).toHaveURL(/\/admin\/email/);
    await expect(page.locator('[data-mail-shell]')).toBeVisible();
  });

  test('Email is one entry in the sidebar and in the Home apps menu, and ordinary members see neither', async ({ page, browser }, info) => {
    runOnly(info, ['w1440']);
    await signIn(page, ADMIN);
    await page.goto('/admin/email');
    const sidebar = page.locator('.fi-sidebar');
    await sidebar.locator('.fi-sidebar-group').filter({ hasText: 'Communications' }).first().getByRole('button').first().click();
    await expect(sidebar.getByRole('link', { name: /^Email/ })).toHaveCount(1);
    for (const old of ['Inbound Email', 'Outbound Email', 'Compose Email']) await expect(sidebar.getByText(old, { exact: true })).toHaveCount(0);

    await page.goto('/');
    await page.locator('details[data-hub-menu] summary').first().click();
    await expect(page.getByRole('menuitem', { name: 'Email' })).toHaveAttribute('href', '/admin/email');

    const member = await browser.newContext();
    const memberPage = await member.newPage();
    await signIn(memberPage, MEMBER);
    await memberPage.goto('/');
    expect(await memberPage.locator('a[href="/admin/email"]').count()).toBe(0);
    const forbidden = await memberPage.goto('/admin/email');
    expect(forbidden?.status() === 403 || !/\/admin\/email/.test(memberPage.url())).toBe(true);
    await member.close();
  });
});
