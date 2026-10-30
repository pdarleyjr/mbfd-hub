import { expect, test, type Browser, type BrowserContext, type Locator, type Page, type Route, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { authDirectory, type PersonaName } from './narcotics-check.setup';
import { localPhpBinary } from './support/test-environment';

/*
 * Narcotics check in real browsers (docs/narcotics-check.md): a member photographs and sends a check,
 * Captain 5 approves or rejects it, and nobody else can see the photo. Synthetic personas only; see
 * database/seeders/NarcoticsChecksE2ESeeder.php.
 *
 * The tests run in order inside one serial group per project and build on each other's checks. Every project
 * gets its own token, so the desktop and phone runs never see each other's text.
 */

const SENT = 'Narcotics check sent to Captain 5.';
const RELEASE_A = 'aaaaaaaaaaaa';
const RELEASE_B = 'bbbbbbbbbbbb';
const BUSY_MESSAGE = 'An update is ready. It will apply when you finish.';
const BUSY_REASON = 'narcotics-check';
const GPS_MARKER = 'MBFD-E2E-GPS-25.7907N-80.1300W';
const SELF_REVIEW_MESSAGE = 'Another Captain 5 reviewer needs to review your own narcotics check.';
const FORM_URL = '/employee/forms?form=narcotics-check';
const MINE_URL = '/employee/forms?form=narcotics-check&view=mine';

interface RunState {
  token: string;
  approvedNote: string;
  rejectedNote: string;
  resentNote: string;
  ownNote: string;
  approvedId: string | null;
  rejectedId: string | null;
  ownId: string | null;
}

const runs = new Map<string, RunState>();

function run(info: TestInfo): RunState {
  const existing = runs.get(info.project.name);
  if (existing) return existing;
  const token = `${info.project.name}${Date.now().toString(36)}`;
  const created: RunState = {
    token,
    approvedNote: `Count matches the log ${token} (approve)`,
    rejectedNote: `Seal photo attached ${token} (reject)`,
    resentNote: `Retaken with the label in focus ${token} (resend)`,
    ownNote: `Captain 5 own check ${token} (own)`,
    approvedId: null,
    rejectedId: null,
    ownId: null,
  };
  runs.set(info.project.name, created);

  return created;
}

// --------------------------------------------------------------------------------------------- sessions

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


/** On a phone every control the work needs is at least 44 px tall. */
async function expectTouchTargets(info: TestInfo, controls: Locator[]): Promise<void> {
  if (!info.project.use.isMobile) return;
  for (const control of controls) {
    const box = await control.boundingBox();
    expect(box?.height ?? 0, `touch target ${await control.textContent()}`).toBeGreaterThanOrEqual(44);
  }
}

const screenshotDirectory = join(process.cwd(), 'test-results', 'visual');

async function shot(page: Page, info: TestInfo, name: string): Promise<void> {
  mkdirSync(screenshotDirectory, { recursive: true });
  await page.screenshot({ path: join(screenshotDirectory, `${info.project.name}-narcotics-${name}.png`), fullPage: true });
}

// ------------------------------------------------------------------------------------------------ photos

interface TestPhoto {
  name: string;
  mimeType: string;
  buffer: Buffer;
}

const photoCache = new Map<string, TestPhoto>();

/** Writes a synthetic photo with GD (see support/narcotics-photo.php) and returns its bytes. */
function makePhoto(kind: 'jpeg' | 'png', width: number, height: number, orientation: number, name: string): TestPhoto {
  const key = `${kind}-${width}x${height}-${orientation}`;
  const cached = photoCache.get(key);
  if (cached) return cached;
  const directory = join(tmpdir(), 'mbfd-narcotics-photos');
  mkdirSync(directory, { recursive: true });
  const path = join(directory, name);
  execFileSync(localPhpBinary('NARCOTICS_CHECK_E2E_PHP'), [join(process.cwd(), 'tests', 'e2e', 'support', 'narcotics-photo.php'), kind, String(width), String(height), String(orientation), path, kind === 'jpeg' ? GPS_MARKER : '']);
  const photo = { name, mimeType: kind === 'png' ? 'image/png' : 'image/jpeg', buffer: readFileSync(path) };
  photoCache.set(key, photo);

  return photo;
}

/** A phone-sized JPEG stored sideways (EXIF orientation 6) with GPS text: upright it is 3000 x 4000, red on top and blue below. */
const phoneJpeg = () => makePhoto('jpeg', 4000, 3000, 6, 'IMG_4001.jpg');
/** Red on the left, blue on the right, 1600 x 1200. */
const screenshotPng = () => makePhoto('png', 1600, 1200, 1, 'Screenshot_1.png');

// -------------------------------------------------------------------------------------------- form helpers

const formHeading = (page: Page) => page.getByRole('heading', { level: 1, name: 'Narcotics check' });
const uploadInput = (page: Page) => page.getByTestId('narcotics-upload-input');
const sendButton = (page: Page) => page.getByTestId('narcotics-send');
const photoInfo = (page: Page) => page.getByTestId('narcotics-photo-info');
const unitSelect = (page: Page) => page.getByTestId('narcotics-unit');
const unitOther = (page: Page) => page.getByTestId('narcotics-unit-other');
const sealField = (page: Page) => page.getByTestId('narcotics-seal');
/** The server's list (config/narcotics_checks.php), in the order the form must show it. */
const UNIT_OPTIONS = ['R1', 'R11', 'R2', 'R22', 'R3', 'R4', 'R44', 'FB6', 'Other'];

interface UnitChoice {
  choice: string;
  other?: string;
}

/** Picks the rescue unit; for Other the text box appears and is filled. */
async function chooseUnit(page: Page, unit: UnitChoice): Promise<void> {
  await unitSelect(page).selectOption(unit.choice);
  if (unit.other !== undefined) {
    await expect(unitOther(page)).toBeVisible();
    await unitOther(page).fill(unit.other);
  }
}

async function chooseFile(page: Page, photo: TestPhoto): Promise<void> {
  await uploadInput(page).setInputFiles({ name: photo.name, mimeType: photo.mimeType, buffer: photo.buffer });
}

/** The preview picture's width and height as reported by the form ("2400 x 3200 . WebP . 140 KB"). */
async function previewDimensions(page: Page): Promise<{ width: number; height: number; format: string }> {
  await expect(photoInfo(page)).toContainText('×');
  const text = (await photoInfo(page).textContent()) ?? '';
  const match = /(\d+) × (\d+) · (WebP|JPEG)/.exec(text);
  if (!match) throw new Error(`Unexpected photo info: ${text}`);

  return { width: Number(match[1]), height: Number(match[2]), format: match[3] };
}

/** Reads the colour of the preview picture at two relative points, as the browser draws it. */
function previewColours(page: Page, points: Array<[number, number]>) {
  return page.getByTestId('narcotics-preview').locator('img').evaluate(async (image, relative) => {
    const element = image as HTMLImageElement;
    await element.decode();
    const canvas = document.createElement('canvas');
    canvas.width = element.naturalWidth;
    canvas.height = element.naturalHeight;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('No 2d canvas');
    context.drawImage(element, 0, 0);

    return relative.map(([x, y]) => Array.from(context.getImageData(Math.floor(x * (canvas.width - 1)), Math.floor(y * (canvas.height - 1)), 1, 1).data.slice(0, 3)));
  }, points);
}

const isRed = ([r, , b]: number[]) => r > 150 && b < 110;
const isBlue = ([r, , b]: number[]) => b > 150 && r < 110;

const busyReasons = (page: Page) =>
  page.evaluate((reason) => Array.from((window as unknown as { __mbfdBusy?: Set<string> }).__mbfdBusy ?? []).filter((candidate) => candidate === reason), BUSY_REASON);

// -------------------------------------------------------------------------------- update-banner helpers

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

/** A new release shows the banner; while a photo is held "Refresh now" is refused and nothing reloads. */
async function expectRefreshHeld(page: Page, releases: ReleaseState): Promise<void> {
  releases.served = RELEASE_B;
  await checkNow(page);
  const banner = page.locator('#mbfd-pwa-banner');
  await expect(banner).toContainText(BUSY_MESSAGE);
  const refresh = banner.getByRole('button', { name: 'Refresh now' });
  await expect(refresh).toHaveAttribute('aria-disabled', 'true');
  await armUnloadProbe(page);
  await refresh.click({ force: true });
  await page.waitForTimeout(800);
  expect(await unloadStarted(page), 'a held photo must block the manual refresh').toBe(false);
}

// ------------------------------------------------------------------------------------------- API helpers

interface ApiCheck {
  id: string;
  status: string;
  status_label: string;
  member_note: string | null;
  rescue_unit: string | null;
  rescue_unit_choice: string | null;
  rescue_unit_other: string | null;
  seal_number: string | null;
  decision_note: string | null;
  can_resubmit: boolean;
  resubmitted_as: string | null;
  resubmission_of: string | null;
  image: { available: boolean; width: number | null; height: number | null; mime: string | null };
}

async function myChecks(page: Page): Promise<ApiCheck[]> {
  const response = await page.request.get('/api/narcotics-checks/mine?per_page=50', { headers: { Accept: 'application/json' } });
  expect(response.status()).toBe(200);

  return (await response.json()).data as ApiCheck[];
}

async function checkWithNote(page: Page, note: string): Promise<ApiCheck> {
  const found = (await myChecks(page)).filter((check) => check.member_note === note);
  expect(found, `exactly one check carries "${note}"`).toHaveLength(1);

  return found[0];
}

/** Opens the form, chooses the phone JPEG and a rescue unit, types a note and sends it once; returns the new check. */
async function sendOneCheck(page: Page, note: string, photo: TestPhoto = phoneJpeg(), unit: UnitChoice = { choice: 'R22' }, seal = 'ZX-4471'): Promise<ApiCheck> {
  await page.goto(FORM_URL);
  await expect(formHeading(page)).toBeVisible();
  await chooseFile(page, photo);
  await expect(page.getByTestId('narcotics-preview')).toBeVisible();
  await chooseUnit(page, unit);
  await sealField(page).fill(seal);
  await page.getByTestId('narcotics-note').fill(note);
  await sendButton(page).click();
  await expect(page.getByRole('heading', { name: SENT })).toBeVisible();

  return checkWithNote(page, note);
}

// ---------------------------------------------------------------------------------------- Captain 5 helpers

const reviewRow = (page: Page, note: string) => page.getByTestId('narcotics-row').filter({ hasText: note });
const sheet = (page: Page) => page.getByTestId('narcotics-sheet');

async function openNarcoticsTab(page: Page): Promise<void> {
  await page.goto('/daily/captain-5?tab=narcotics');
  await expect(page.getByTestId('narcotics-tab')).toBeVisible({ timeout: 30_000 });
}

async function imageIsSharp(page: Page, selector: ReturnType<Page['locator']>, minimumLongEdge: number): Promise<void> {
  await expect(selector).toBeVisible();
  await expect
    .poll(() => selector.evaluate((element) => ({ w: (element as HTMLImageElement).naturalWidth, h: (element as HTMLImageElement).naturalHeight })), { message: 'the photo has loaded' })
    .toMatchObject({ w: expect.any(Number) });
  const { w, h } = await selector.evaluate((element) => ({ w: (element as HTMLImageElement).naturalWidth, h: (element as HTMLImageElement).naturalHeight }));
  expect(Math.max(w, h), 'the photo is shown at a sharp resolution').toBeGreaterThanOrEqual(minimumLongEdge);
}

// =====================================================================================================
// Member: take or upload a photo
// =====================================================================================================

test.describe.serial('Narcotics check', () => {
  test('member: the Forms card opens the form; JPEG and PNG preview; the browser stands the photo upright, caps it and drops GPS', async ({ browser }, info) => {
    await as(browser, info, 'member', async ({ page }) => {
      await page.goto('/employee/forms');
      const card = page.locator('[data-library-link="narcotics-check"]');
      await expect(card.getByRole('heading', { name: 'Narcotics check' })).toBeVisible();
      await expect(card.getByRole('link', { name: 'My narcotics checks' })).toBeVisible();
      await shot(page, info, '00-forms-library');
      await card.getByRole('link', { name: 'Start a check' }).click();
      await expect(formHeading(page)).toBeVisible();
      await expect(page).toHaveURL(/form=narcotics-check/);
      await expect(sendButton(page)).toBeDisabled();
      await expect(page.getByText('Add a photo to send.')).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '01-form-empty');

      // Not a photo: a plain-language problem, nothing held.
      await uploadInput(page).setInputFiles({ name: 'notes.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('this is not a picture at all') });
      await expect(page.getByRole('alert').filter({ hasText: /photo/i }).first()).toBeVisible();
      await expect(page.getByTestId('narcotics-preview')).toHaveCount(0);
      expect(await busyReasons(page), 'no photo, nothing to protect').toEqual([]);

      // A PNG first: the preview shows, left half red and right half blue.
      await chooseFile(page, screenshotPng());
      await expect(page.getByTestId('narcotics-preview')).toBeVisible();
      const png = await previewDimensions(page);
      expect(Math.max(png.width, png.height)).toBeLessThanOrEqual(1600);
      const [pngLeft, pngRight] = await previewColours(page, [[0.2, 0.5], [0.8, 0.5]]);
      expect(isRed(pngLeft), `PNG left half is red, got ${pngLeft}`).toBe(true);
      expect(isBlue(pngRight), `PNG right half is blue, got ${pngRight}`).toBe(true);
      await expect(page.getByRole('button', { name: 'Retake photo' })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Choose a different photo' })).toBeVisible();

      // Replace it with the sideways phone JPEG while watching nothing leaves the browser yet.
      const posts: string[] = [];
      page.on('request', (request) => { if (request.method() === 'POST' && request.url().includes('/api/narcotics-checks')) posts.push(request.url()); });
      await chooseFile(page, phoneJpeg());
      await expect.poll(async () => (await previewDimensions(page)).height, { message: 'the JPEG replaced the PNG' }).toBeGreaterThan(png.height);
      const jpeg = await previewDimensions(page);
      expect(Math.max(jpeg.width, jpeg.height), 'the long edge is capped at 3200').toBeLessThanOrEqual(3200);
      expect(Math.max(jpeg.width, jpeg.height), 'a big photo is reduced, not left alone').toBeGreaterThanOrEqual(3000);
      expect(jpeg.height, 'the sideways photo was stood upright').toBeGreaterThan(jpeg.width);
      const [top, bottom] = await previewColours(page, [[0.5, 0.15], [0.5, 0.85]]);
      expect(isRed(top), `upright top is red (rotated exactly once), got ${top}`).toBe(true);
      expect(isBlue(bottom), `upright bottom is blue, got ${bottom}`).toBe(true);
      expect(posts, 'choosing a photo sends nothing').toEqual([]);
      expect(await busyReasons(page), 'a held photo is unsaved work').toEqual([BUSY_REASON]);
      await expect(page.locator('[data-mbfd-unsaved-work-guard]').first()).toBeAttached();

      // Tap to zoom opens the viewer and closes again.
      await page.getByTestId('narcotics-preview').click();
      const viewer = page.getByRole('dialog', { name: 'Photo viewer' });
      await expect(viewer).toBeVisible();
      await shot(page, info, '03-form-zoom');
      await page.keyboard.press('Escape');
      await expect(viewer).toHaveCount(0);

      await expectTouchTargets(info, [page.getByTestId('narcotics-take'), page.getByTestId('narcotics-upload'), sendButton(page)]);
      await page.getByTestId('narcotics-note').fill('A note that is optional');
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '02-form-preview');
    });
  });

  test('member: the rescue unit is a required drop-down; Other shows a text box that hides and clears again; a picked unit is unsaved work', async ({ browser }, info) => {
    await as(browser, info, 'member', async ({ page }) => {
      const posts: string[] = [];
      page.on('request', (request) => { if (request.method() === 'POST' && request.url().includes('/api/narcotics-checks')) posts.push(request.url()); });
      await page.goto(FORM_URL);
      await expect(formHeading(page)).toBeVisible();

      // A labelled, required drop-down with exactly the server's list, Other last; no text box yet.
      const select = unitSelect(page);
      await expect(page.getByLabel(/^Rescue unit/)).toBeVisible(); // a required Fluent field adds " *" to its accessible name
      await expect(select).toHaveValue('');
      expect(await select.locator('option').evaluateAll((options) => options.map((option) => (option as HTMLOptionElement).value).filter((value) => value !== ''))).toEqual(UNIT_OPTIONS);
      await expect(unitOther(page)).toHaveCount(0);
      expect(await busyReasons(page), 'nothing picked, nothing to protect').toEqual([]);
      await expectTouchTargets(info, [select]);
      expect(await select.evaluate((element) => getComputedStyle(element).fontSize), 'a 16px picker does not make iOS zoom the page').toBe('16px');
      await shot(page, info, '07-unit-empty');

      // Picking a unit is typed work, like the note: it blocks the refresh banner.
      await select.selectOption('R22');
      await expect.poll(() => busyReasons(page)).toEqual([BUSY_REASON]);
      await expect(unitOther(page)).toHaveCount(0);

      // Other reveals a labelled, required text box (16px, counted); a listed unit hides and clears it.
      await select.selectOption('Other');
      await expect(unitOther(page)).toBeVisible();
      await expect(page.getByLabel('Which unit?')).toBeVisible();
      expect(await unitOther(page).evaluate((element) => getComputedStyle(element).fontSize)).toBe('16px');
      await unitOther(page).fill('Rescue 9');
      await expectTouchTargets(info, [unitOther(page)]);
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '08-unit-other');
      await select.selectOption('R44');
      await expect(unitOther(page)).toHaveCount(0);
      await select.selectOption('Other');
      await expect(unitOther(page), 'the old text is gone, not just hidden').toHaveValue('');

      // Required: with a photo, sending without a unit explains what is missing next to the field and sends nothing.
      await chooseFile(page, screenshotPng());
      await expect(page.getByTestId('narcotics-preview')).toBeVisible();
      await select.selectOption('');
      await sendButton(page).click();
      await expect(select).toBeFocused();
      await expect(select).toHaveAccessibleDescription(/Choose your rescue unit\./);
      await expect(page.getByTestId('narcotics-unit-card')).toContainText('Choose your rescue unit.');

      await select.selectOption('Other');
      await sendButton(page).click();
      await expect(unitOther(page)).toBeFocused();
      await expect(unitOther(page)).toHaveAccessibleDescription(/Enter which rescue unit you are on\./);
      await unitOther(page).fill('x'.repeat(41));
      await expect(unitOther(page)).toHaveAccessibleDescription(/at most 40 characters/);
      await sendButton(page).click();
      await expect(unitOther(page)).toBeFocused();
      await unitOther(page).fill('Rescue 9');
      await expect(page.getByTestId('narcotics-unit-card')).not.toContainText('at most 40');
      await shot(page, info, '09-unit-errors-cleared');
      expect(posts, 'a missing or unusable unit is stopped before anything is sent').toEqual([]);
    });
  });

  test('member: the seal number is a required text box with a linked error; a typed value is unsaved work; nothing is sent without it', async ({ browser }, info) => {
    await as(browser, info, 'member', async ({ page }) => {
      const posts: string[] = [];
      page.on('request', (request) => { if (request.method() === 'POST' && request.url().includes('/api/narcotics-checks')) posts.push(request.url()); });
      await page.goto(FORM_URL);
      await expect(formHeading(page)).toBeVisible();

      // A labelled, required text box that sits between Rescue unit and Photo. A required Fluent field adds " *" to its name.
      const seal = sealField(page);
      await expect(page.getByLabel(/^Seal number/)).toBeVisible();
      await expect(seal).toHaveValue('');
      const inOrder = await page.evaluate(() => {
        const element = (id: string) => document.querySelector('[data-testid="' + id + '"]') as Element;
        const follows = (first: Element, second: Element) => Boolean(first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING);
        return follows(element('narcotics-unit'), element('narcotics-seal')) && follows(element('narcotics-seal'), element('narcotics-take'));
      });
      expect(inOrder, 'Rescue unit, then Seal number, then Photo').toBe(true);
      expect(await seal.evaluate((element) => getComputedStyle(element).fontSize), 'a 16px input does not make iOS zoom the page').toBe('16px');
      await expect(seal).toHaveAttribute('autocomplete', 'off');
      await expectTouchTargets(info, [seal]);
      expect(await busyReasons(page), 'nothing typed, nothing to protect').toEqual([]);

      // Letters, digits, dashes and spaces are all fine, and what is typed stays as typed.
      await seal.fill('ab-12 C');
      await expect(seal).toHaveValue('ab-12 C');
      await expect.poll(() => busyReasons(page), { message: 'a typed seal number is unsaved work' }).toEqual([BUSY_REASON]);
      await seal.fill('');
      await expect.poll(() => busyReasons(page), { message: 'emptied again, nothing to protect' }).toEqual([]);
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '16-seal-empty');

      // Required: with a photo and a unit, sending without a seal number explains it next to the field and sends nothing.
      await chooseFile(page, screenshotPng());
      await expect(page.getByTestId('narcotics-preview')).toBeVisible();
      await chooseUnit(page, { choice: 'R22' });
      await sendButton(page).click();
      await expect(seal).toBeFocused();
      await expect(seal).toHaveAccessibleDescription(/Enter the seal number\./);
      await expect(page.getByTestId('narcotics-seal-card')).toContainText('Enter the seal number.');
      await shot(page, info, '17-seal-required');

      // Unusable text is flagged as it is typed, and the message stays linked to the field.
      await seal.fill('x'.repeat(41));
      await expect(seal).toHaveAccessibleDescription(/at most 40 characters/);
      await sendButton(page).click();
      await expect(seal).toBeFocused();
      await seal.fill('SEAL-0001');
      await expect(page.getByTestId('narcotics-seal-card')).not.toContainText('Enter the seal number.');
      await expect(page.getByTestId('narcotics-seal-card')).not.toContainText('at most 40');
      await expect(seal).not.toHaveAccessibleDescription(/Enter the seal number|at most 40/);
      expect(posts, 'a missing or unusable seal number is stopped before anything is sent').toEqual([]);
    });
  });

  test('member (desktop): Take photo without a camera explains why and keeps the upload choice', async ({ browser }, info) => {
    test.skip(info.project.name !== 'desktop', 'The in-page camera is a computer-only path; phones open the system camera.');
    await as(browser, info, 'member', async ({ page }) => {
      await page.goto(FORM_URL);
      await expect(formHeading(page)).toBeVisible();
      await page.getByTestId('narcotics-take').click();
      // Headless Chromium has no camera: either an immediate notice, or the in-page camera reports it.
      const notice = page.getByRole('alert').filter({ hasText: /camera|upload/i }).first();
      await expect(notice).toBeVisible({ timeout: 15_000 });
      await expect(page.getByTestId('narcotics-upload')).toBeEnabled();
      await shot(page, info, '04-form-no-camera');
    });
  });

  test('member: a failed upload keeps the photo and offers a retry; the held photo blocks Refresh now; one tap-pair sends one record; confirmation waits for the server', async ({ browser }, info) => {
    const state = run(info);
    await as(browser, info, 'member', async ({ page }) => {
      const releases: ReleaseState = { served: RELEASE_A };
      await pinReleases(page, releases);

      let attempts = 0;
      const bodies: Buffer[] = [];
      let openGate!: () => void;
      const gate = new Promise<void>((resolve) => { openGate = resolve; });
      await page.route('**/api/narcotics-checks', async (route: Route) => {
        if (route.request().method() !== 'POST') return route.continue();
        attempts += 1;
        const body = route.request().postDataBuffer();
        if (body) bodies.push(body);
        if (attempts === 1) return route.abort('failed'); // the connection drops mid-upload
        await gate; // the server answers only when the test lets it

        return route.continue();
      });

      await page.goto(FORM_URL);
      await expect(formHeading(page)).toBeVisible();
      await expect.poll(() => registrarReady(page), { message: 'the update registrar is mounted on this page' }).toBe(true);
      expect(await busyReasons(page), 'an empty form is not busy').toEqual([]);

      await chooseFile(page, phoneJpeg());
      await expect(page.getByTestId('narcotics-preview')).toBeVisible();
      await chooseUnit(page, { choice: 'R22' });
      await sealField(page).fill('ZX-4471');
      await page.getByTestId('narcotics-note').fill(state.approvedNote);
      await expect.poll(() => busyReasons(page)).toEqual([BUSY_REASON]);
      await expectRefreshHeld(page, releases);

      // 1. The upload fails once.
      await sendButton(page).click();
      const failure = page.getByTestId('narcotics-failure');
      await expect(failure).toBeVisible();
      await expect(page.getByRole('heading', { name: SENT })).toHaveCount(0);
      await expect(page.getByTestId('narcotics-preview')).toBeVisible();
      await expect(page.getByTestId('narcotics-note')).toHaveValue(state.approvedNote);
      await expect(unitSelect(page), 'the unit survives a failed upload').toHaveValue('R22');
      await expect(sealField(page), 'the seal number survives a failed upload').toHaveValue('ZX-4471');
      await expect(sendButton(page)).toHaveText(/Send again/);
      await expect(sendButton(page)).toBeEnabled();
      expect(await busyReasons(page), 'a failed send stays unsafe to refresh').toEqual([BUSY_REASON]);
      await shot(page, info, '05-form-failed-retry');
      expect(await checkCount(page, state.approvedNote), 'the failed upload created nothing').toBe(0);

      // 2. Retry with a double click: one request goes out, and "sent" is not claimed until the server answers.
      await sendButton(page).dblclick();
      await expect(page.getByTestId('narcotics-progress')).toBeVisible();
      await page.waitForTimeout(600);
      expect(attempts, 'a double click sends one request').toBe(2);
      await expect(page.getByRole('heading', { name: SENT })).toHaveCount(0);
      await expect(sendButton(page)).toBeDisabled();
      expect(await busyReasons(page), 'sending is busy').toEqual([BUSY_REASON]);
      await expect(page.locator('#mbfd-pwa-banner').getByRole('button', { name: 'Refresh now' })).toHaveAttribute('aria-disabled', 'true');

      openGate();
      await expect(page.getByRole('heading', { name: SENT })).toBeVisible();
      await expect(page.getByTestId('narcotics-sent')).toBeVisible();
      await shot(page, info, '06-form-submitted');
      await expectNoHorizontalOverflow(page);

      // 3. Saved: nothing is held, the banner works, and exactly one record exists.
      // The narcotics reason is released. (The whole Forms page also carries data-mbfd-unsaved-work-guard, because other
      // Forms keep work in memory, so the banner's "Refresh now" stays held until the member leaves Forms. That page-level
      // guard is older than this feature and is not asserted here.)
      await expect.poll(() => busyReasons(page)).toEqual([]);
      expect(attempts).toBe(2);
      const check = await checkWithNote(page, state.approvedNote);
      state.approvedId = check.id;
      expect(check.status).toBe('pending');
      expect(check.status_label).toBe('Sent to Captain 5');
      expect(check.rescue_unit).toBe('R22');
      expect(check.rescue_unit_other).toBeNull();
      expect(check.seal_number).toBe('ZX-4471');
      expect(bodies[1].toString('latin1'), 'the retry carries the unit').toMatch(/name="rescue_unit"\r\n\r\nR22\r\n/);
      expect(bodies[1].toString('latin1'), 'and the seal number').toMatch(/name="seal_number"\r\n\r\nZX-4471\r\n/);

      // What was uploaded: an image part, no EXIF, no GPS text, no raw 4000 x 3000 original.
      expect(bodies).toHaveLength(2);
      const uploaded = bodies[1];
      const text = uploaded.toString('latin1');
      expect(text).toMatch(/Content-Type: image\/(webp|jpeg)/);
      expect(text, 'GPS text must not leave the browser').not.toContain(GPS_MARKER);
      expect(text, 'no EXIF segment must leave the browser').not.toContain('Exif\u0000\u0000');
      expect(uploaded.length, 'the upload is smaller than the original').toBeLessThan(phoneJpeg().buffer.length * 1.2);
      // The server stored an upright picture no larger than the cap.
      expect(check.image.available).toBe(true);
      expect(Math.max(check.image.width ?? 0, check.image.height ?? 0)).toBeLessThanOrEqual(3200);
      expect(check.image.height ?? 0).toBeGreaterThan(check.image.width ?? 0);

      // Leaving the Narcotics views with nothing held does not ask anything.
      await page.getByRole('button', { name: 'My narcotics checks' }).click();
      await expect(page.getByTestId('narcotics-mine')).toBeVisible();
      await expect(reviewRowMine(page, state.approvedNote)).toBeVisible();
    });
  });

  // ===================================================================================================
  // Captain 5
  // ===================================================================================================

  test('Captain 5: sees the member, time and note with a sharp photo and zoom; Approve & Archive moves it to the archive once; the member sees Approved', async ({ browser }, info) => {
    const state = run(info);
    await as(browser, info, 'captain5', async ({ page }) => {
      await openNarcoticsTab(page);
      await expect(page.getByRole('tab', { name: /Narcotics Checks/ })).toBeVisible();
      const row = reviewRow(page, state.approvedNote);
      await expect(row).toBeVisible();
      await expect(row).toContainText('Pat Member');
      await expect(row).toContainText('Sent');
      await expect(row).toContainText(state.approvedNote);
      await expect(row).toContainText('Pending review');
      await expect(row.getByTestId('narcotics-row-unit')).toHaveText('Rescue unit: R22');
      await expect(row.getByTestId('narcotics-row-seal')).toHaveText('Seal number: ZX-4471');
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '10-captain5-inbox');

      await row.click();
      await expect(sheet(page)).toBeVisible();
      await expect(page).toHaveURL(/check=/);
      // Focus moves into the sheet (not left on the page's skip link behind it).
      await expect.poll(() => page.evaluate(() => Boolean(document.activeElement?.closest('[role="dialog"]')))).toBe(true);
      await expect(page.getByRole('link', { name: 'Skip to main content' })).not.toBeInViewport();
      const photo = page.getByTestId('narcotics-photo').locator('img');
      await imageIsSharp(page, photo, 1200);
      await expectTouchTargets(info, [page.getByTestId('narcotics-approve'), page.getByTestId('narcotics-reject'), row]);
      await expect(sheet(page).getByTestId('narcotics-summary')).toContainText('Pat Member');
      await expect(sheet(page).getByTestId('narcotics-summary')).toContainText(state.approvedNote);
      await expect(sheet(page).getByTestId('narcotics-summary')).toContainText('Rescue unit');
      await expect(sheet(page).getByTestId('narcotics-summary')).toContainText('R22');
      await expect(sheet(page).getByTestId('narcotics-summary')).toContainText('Seal number');
      await expect(sheet(page).getByTestId('narcotics-summary')).toContainText('ZX-4471');
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '11-captain5-sheet');

      // Zoom shows the full-size picture and changes magnification.
      await page.getByTestId('narcotics-photo').click();
      const zoom = page.getByTestId('narcotics-zoom');
      await expect(zoom).toBeVisible();
      await imageIsSharp(page, zoom.locator('img'), 3000);
      await zoom.getByRole('button', { name: 'Zoom in' }).click();
      await expect(zoom).not.toContainText('100%');
      await shot(page, info, '12-captain5-zoom');
      await zoom.getByRole('button', { name: 'Close photo' }).click();
      await expect(zoom).toHaveCount(0);
      await expect(sheet(page)).toBeVisible();

      // One click approves; the request goes out once.
      const approvals: string[] = [];
      page.on('request', (request) => { if (request.method() === 'POST' && request.url().endsWith('/approve')) approvals.push(request.url()); });
      await page.getByTestId('narcotics-approve').click();
      await expect(page.getByTestId('narcotics-notice')).toContainText('Approved and archived');
      await expect(page.getByTestId('narcotics-actions')).toHaveCount(0);
      expect(approvals).toHaveLength(1);
      await shot(page, info, '13-captain5-approved');

      await page.keyboard.press('Escape');
      await expect(sheet(page)).toHaveCount(0);
      await expect(reviewRow(page, state.approvedNote), 'the decided check left the pending inbox').toHaveCount(0);

      await page.getByTestId('narcotics-scope').getByText('Archive', { exact: true }).click();
      // Archive rows say who decided; the member's note is searchable.
      await page.getByLabel('Search').fill(state.approvedNote);
      const archived = page.getByTestId('narcotics-row');
      await expect(archived.filter({ hasText: 'Pending review' }), 'the archive list replaced the inbox list').toHaveCount(0);
      await expect(archived).toHaveCount(1);
      await expect(archived).toContainText('Pat Member');
      await expect(archived).toContainText('Approved by');
      await expect(archived).toContainText('Rescue unit: R22');
      await expect(archived).toContainText('Seal number: ZX-4471');
      await expectNoHorizontalOverflow(page);
      await shot(page, info, '14-captain5-archive');
    });

    await as(browser, info, 'member', async ({ page }) => {
      await page.goto(MINE_URL);
      // A decided check lists its decision, not the note; the newest Approved row is this run's.
      const row = page.getByTestId('narcotics-row').filter({ hasText: 'Approved' }).first();
      await expect(row).toBeVisible();
      await row.click();
      await expect(page.getByTestId('narcotics-banner')).toContainText('Approved');
      await expect(page.getByTestId('narcotics-banner')).toContainText('Cap Five');
      await expect(page.getByTestId('narcotics-detail-unit')).toContainText('Rescue unit: R22');
      await expect(page.getByTestId('narcotics-detail-seal')).toContainText('Seal number: ZX-4471');
      await shot(page, info, '15-member-approved');
    });
  });

  test('Captain 5 rejects with an explanation; the member sees Rejected and why, and sends a new photo', async ({ browser }, info) => {
    const state = run(info);
    const reason = `The seal number is not readable ${state.token}`;
    await as(browser, info, 'member', async ({ page }) => {
      const check = await sendOneCheck(page, state.rejectedNote, screenshotPng(), { choice: 'R44' }, 'RJ-1002');
      expect(check.rescue_unit).toBe('R44');
      expect(check.seal_number).toBe('RJ-1002');
      state.rejectedId = check.id;
    });

    await as(browser, info, 'captain5', async ({ page }) => {
      await openNarcoticsTab(page);
      await reviewRow(page, state.rejectedNote).click();
      await expect(sheet(page)).toBeVisible();
      await page.getByTestId('narcotics-reject').click();
      const form = page.getByTestId('narcotics-reject-form');
      await expect(form).toBeVisible();
      await expect(form.getByLabel(/Why is it being rejected/)).toBeFocused();
      await form.getByLabel(/Why is it being rejected/).fill(reason);
      await shot(page, info, '20-captain5-reject-form');
      await form.getByRole('button', { name: 'Reject check' }).click();
      await expect(page.getByTestId('narcotics-notice')).toContainText('Rejected and archived');
      await expect(page.getByTestId('narcotics-sheet').getByTestId('narcotics-summary')).toContainText(reason);
    });

    await as(browser, info, 'member', async ({ page }) => {
      await page.goto(MINE_URL);
      const row = reviewRowMine(page, reason);
      await expect(row).toContainText('Rejected');
      await row.click();
      await expect(page.getByTestId('narcotics-banner')).toContainText('Rejected');
      await expect(page.getByTestId('narcotics-reason')).toContainText(reason);
      await shot(page, info, '21-member-rejected');

      await page.getByTestId('narcotics-resubmit').click();
      await expect(page.getByText('Captain 5 did not accept your last photo')).toBeVisible();
      // The answer starts with the rejected check's unit; it is only a prefill, so it is not unsaved work yet and it can be changed.
      await expect(unitSelect(page)).toHaveValue('R44');
      await expect(sealField(page)).toHaveValue('RJ-1002');
      expect(await busyReasons(page), 'a prefilled unit and seal number alone are not unsaved work').toEqual([]);
      await chooseFile(page, phoneJpeg());
      await expect(page.getByTestId('narcotics-preview')).toBeVisible();
      await chooseUnit(page, { choice: 'Other', other: '  Rescue 9  ' });
      await sealField(page).fill('  RJ-1003  ');
      await page.getByTestId('narcotics-note').fill(state.resentNote);
      await sendButton(page).click();
      await expect(page.getByRole('heading', { name: SENT })).toBeVisible();

      const resent = await checkWithNote(page, state.resentNote);
      expect(resent.rescue_unit, 'the typed text is trimmed and shown as the unit').toBe('Rescue 9');
      expect(resent.rescue_unit_choice).toBe('Other');
      expect(resent.rescue_unit_other).toBe('Rescue 9');
      expect(resent.seal_number, 'the seal number is trimmed and editable on a resubmission').toBe('RJ-1003');
      expect(resent.status).toBe('pending');
      expect(resent.resubmission_of).toBe(state.rejectedId);
      const original = (await myChecks(page)).find((check) => check.id === state.rejectedId);
      expect(original?.status, 'the rejected record is never edited').toBe('rejected');
      expect(original?.resubmitted_as).toBe(resent.id);
      expect(original?.can_resubmit).toBe(false);
    });
  });

  test('a reviewer cannot decide their own check; the other Captain 5 can', async ({ browser }, info) => {
    const state = run(info);
    await as(browser, info, 'captain5', async ({ page }) => {
      const own = await sendOneCheck(page, state.ownNote, phoneJpeg(), { choice: 'Other', other: 'Spare Rescue 12' }, 'OWN 77');
      expect(own.rescue_unit).toBe('Spare Rescue 12');
      state.ownId = own.id;

      await openNarcoticsTab(page);
      await reviewRow(page, state.ownNote).click();
      await expect(sheet(page)).toBeVisible();
      await page.getByTestId('narcotics-approve').click();
      await expect(sheet(page).getByRole('alert').filter({ hasText: SELF_REVIEW_MESSAGE })).toBeVisible();
      await expect(page.getByTestId('narcotics-actions'), 'the check is still waiting').toBeVisible();
      await shot(page, info, '30-captain5-own-check-refused');
      await page.keyboard.press('Escape');
      await expect(reviewRow(page, state.ownNote), 'still in the pending inbox').toBeVisible();
    });

    await as(browser, info, 'second', async ({ page }) => {
      await openNarcoticsTab(page);
      const row = reviewRow(page, state.ownNote);
      await expect(row).toContainText('Cap Five');
      await expect(row).toContainText('Rescue unit: Spare Rescue 12');
      await expect(row).toContainText('Seal number: OWN 77');
      await row.click();
      await expect(sheet(page).getByTestId('narcotics-summary')).toContainText('Spare Rescue 12');
      await expect(sheet(page).getByTestId('narcotics-summary')).toContainText('OWN 77');
      await page.getByTestId('narcotics-approve').click();
      await expect(page.getByTestId('narcotics-notice')).toContainText('Approved and archived');
    });

    await as(browser, info, 'captain5', async ({ page }) => {
      await page.goto(MINE_URL);
      await expect(page.getByTestId('narcotics-row').first()).toContainText('Approved');
    });
  });

  test('another member and non-reviewers get 404 or no tab; the sender and Captain 5 get the photo', async ({ browser }, info) => {
    const state = run(info);
    expect(state.approvedId).toMatch(/^[0-9a-f-]{36}$/);
    const variants = ['thumb', 'preview', 'full'] as const;

    await as(browser, info, 'other', async ({ page }) => {
      for (const variant of variants) {
        const response = await page.request.get(`/api/narcotics-checks/${state.approvedId}/image/${variant}`);
        expect(response.status(), `Olive reading Pat's ${variant}`).toBe(404);
      }
      expect((await page.request.get(`/api/narcotics-checks/mine/${state.approvedId}`, { headers: { Accept: 'application/json' } })).status()).toBe(404);
      const meta = await page.request.get('/api/narcotics-checks/staff/meta', { headers: { Accept: 'application/json' } });
      expect((await meta.json()).data.permissions.review).toBe(false);
      expect((await page.request.get('/api/narcotics-checks/staff/checks', { headers: { Accept: 'application/json' } })).status()).toBeGreaterThanOrEqual(403);
      expect((await page.request.get('/api/narcotics-checks/staff/checks', { headers: { Accept: 'application/json' } })).status()).toBeLessThan(500);
      await page.goto(`${MINE_URL.replace('&view=mine', '')}&check=${state.approvedId}`);
      await expect(page.getByText('That narcotics check is not available to you.')).toBeVisible();
      expect(await page.content()).not.toContain(state.approvedNote);
      // Olive is not Captain rank and holds no seat: the Captain 5 workspace itself is closed.
      const workspace = await page.goto('/daily/captain-5?tab=narcotics');
      expect(workspace?.status()).toBe(403);
    });

    await as(browser, info, 'member', async ({ page }) => {
      for (const variant of variants) {
        const response = await page.request.get(`/api/narcotics-checks/${state.approvedId}/image/${variant}`);
        expect(response.status()).toBe(200);
        expect(response.headers()['cache-control']).toContain('no-store');
        expect(response.headers()['content-type']).toMatch(/^image\/(webp|jpeg)/);
        expect(response.headers()['x-content-type-options']).toBe('nosniff');
      }
      expect((await page.request.get('/api/narcotics-checks/staff/checks', { headers: { Accept: 'application/json' } })).status(), 'a sender is not a reviewer').toBeGreaterThanOrEqual(403);
    });

    await as(browser, info, 'captain5', async ({ page }) => {
      const response = await page.request.get(`/api/narcotics-checks/${state.approvedId}/image/full`);
      expect(response.status()).toBe(200);
    });

    // Signed out: no photo.
    const anonymous = await browser.newContext({ baseURL: info.project.use.baseURL });
    try {
      const response = await anonymous.request.get(`/api/narcotics-checks/${state.approvedId}/image/thumb`, { headers: { Accept: 'application/json' }, maxRedirects: 0 });
      expect([302, 401, 404]).toContain(response.status());
    } finally {
      await anonymous.close();
    }
  });
});

// ---------------------------------------------------------------------------------------------- tiny helpers

const reviewRowMine = (page: Page, note: string) => page.getByTestId('narcotics-row').filter({ hasText: note });

async function checkCount(page: Page, note: string): Promise<number> {
  return (await myChecks(page)).filter((check) => check.member_note === note).length;
}
