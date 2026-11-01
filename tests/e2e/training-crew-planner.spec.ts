import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

type Role = 'training_admin' | 'training_publisher' | 'training_viewer' | 'super_admin';

async function clickButton(page: Page, name: string) {
    const button = page.getByRole('button', { name, exact: true });
    await expect(button).toBeEnabled();
    await button.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await button.click();
}

async function login(page: Page, role: Role = 'training_admin') {
    // Planner owners are per project so parallel device projects never share a saved plan.
    const identity = role === 'training_admin' || role === 'training_publisher' ? `${role}-${test.info().project.name}` : role;
    await page.goto('/login');
    const response = await page.request.post('/login', { form: {
        _token: await page.locator('input[name="_token"]').inputValue(),
        employee_id: `${identity}@example.test`, password: 'synthetic-crew-password',
    }, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location).not.toContain('/login');
}

const fingerprint = async (page: Page) => (await (await page.request.get('/__crew_domain_fingerprint')).json()) as Record<string, string>;
const course = (page: Page) => page.getByRole('combobox', { name: 'Course', exact: true });
const candidate = (page: Page, member: string) => page.getByRole('checkbox', { name: `Select Synthetic ${member}`, exact: true });
const members = (page: Page) => page.getByRole('checkbox', { name: /^Select Synthetic/ });
const reviewList = (page: Page) => page.locator('.crew-planner section[aria-labelledby^="review-"]:not(#review-verify) li');

async function waitForLivewire(page: Page) {
    await page.waitForFunction('window.Livewire && window.Livewire.all().length > 0');
}

/** Opens the planner on a blank plan. The page continues the owner's latest plan, so each test starts over first. */
async function openBlankPlan(page: Page, path = '/training/crew-planner') {
    const response = await page.goto(path);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Crew Planner', exact: true })).toBeVisible();
    await waitForLivewire(page);
    page.once('dialog', dialog => dialog.accept());
    await clickButton(page, 'Start over');
    await expect(page.getByText('Choose a course or enter a class title in step 1', { exact: false })).toBeVisible();
}

async function chooseCourse(page: Page, label: string) {
    await course(page).selectOption({ label });
    // Choosing a course applies it at once; wait for that before touching anything else.
    await expect(page.getByText('Leaving out members who already attended', { exact: false })).toBeVisible();
}

async function planDate(page: Page): Promise<string> {
    const value = await page.getByLabel('Class date', { exact: true }).inputValue();
    expect(value).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    return value;
}

async function openSessions(page: Page) {
    const details = page.locator('#crew-planner-classroom-times details');
    if (!(await details.evaluate(el => (el as HTMLDetailsElement).open))) await details.locator('summary').click();
}

const livewireUpdate = (page: Page) => page.waitForResponse(response => response.request().method() === 'POST' && /\/livewire[^/]*\/update$/.test(new URL(response.url()).pathname));

/** Each field is typed only after the previous one has been saved, so a late render can never replace what was typed. */
async function enterSession(page: Page, start: string, end: string, index = 0) {
    for (const [field, value] of [['start', start], ['end', end]] as const) {
        const saved = livewireUpdate(page);
        await page.getByLabel(`Session ${index + 1} ${field} time`, { exact: true }).fill(value);
        await saved;
    }
}

async function chooseLocation(page: Page, index: number, label: string) {
    const saved = livewireUpdate(page);
    await page.getByLabel(`Session ${index + 1} location`, { exact: true }).selectOption({ label });
    await saved;
}

async function enterVenue(page: Page, venue: string, index: number) {
    const input = page.getByLabel(`Session ${index + 1} venue`, { exact: true });
    await input.fill(venue);
    const saved = livewireUpdate(page);
    await input.blur();
    await saved;
}

async function planWithTwoMembers(page: Page) {
    await openBlankPlan(page);
    await chooseCourse(page, 'Synthetic new course');
    await expect(members(page)).toHaveCount(3);
    await expect(page.getByText('3 eligible · 0 selected', { exact: true })).toBeVisible();
    await candidate(page, '90001').check();
    await expect(reviewList(page)).toHaveCount(1);
    await candidate(page, '90002').check();
    await expect(reviewList(page)).toHaveCount(2);
}

function inspectExport(path: string, format: 'pdf' | 'xlsx'): string {
    const dependencies = process.env.CREW_PLANNER_DEPENDENCY_ROOT || process.env.CREW_PLANNER_SOURCE_ROOT || process.cwd();
    const code = format === 'pdf'
        ? 'require $argv[1]."/vendor/autoload.php"; echo (new Smalot\\PdfParser\\Parser)->parseFile($argv[2])->getText();'
        : 'require $argv[1]."/vendor/autoload.php"; $book=PhpOffice\\PhpSpreadsheet\\IOFactory::load($argv[2]); $data=[];foreach($book->getAllSheets() as $sheet){$data[$sheet->getTitle()]=$sheet->toArray();} echo json_encode($data,JSON_THROW_ON_ERROR);';
    return execFileSync(process.env.PHP_BINARY || 'php', ['-r', code, dependencies, path], { encoding: 'utf8' });
}

async function download(page: Page, label: string, format: 'pdf' | 'xlsx', date: string, savePath: string) {
    const pending = page.waitForEvent('download');
    await clickButton(page, label);
    const file = await pending;
    expect(file.suggestedFilename()).toBe(`MBFD-Training-Class-Roster-${date}.${format}`);
    await file.saveAs(savePath);
    expect(readFileSync(savePath).subarray(0, format === 'pdf' ? 5 : 2).toString()).toBe(format === 'pdf' ? '%PDF-' : 'PK');
}

/** Everything a person must be able to reach: height and width of each target, measured as rendered. */
async function smallTargets(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const found: string[] = [];
        const selectors = '.crew-planner button, .crew-planner select, .crew-planner summary, .crew-planner input:not([type=checkbox]), .crew-planner label:has(input[type=checkbox])';
        for (const el of document.querySelectorAll<HTMLElement>(selectors)) {
            const box = el.getBoundingClientRect();
            if (box.width === 0 || box.height === 0 || getComputedStyle(el).visibility === 'hidden') continue;
            if (box.height < 43.5 || box.width < 43.5) found.push(`${el.tagName.toLowerCase()} "${(el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 40)}" ${Math.round(box.width)}x${Math.round(box.height)}`);
        }
        return found;
    });
}

test('the roster loads by itself with its source line, there is no file input and the old upload address is gone', async ({ page }) => {
    await login(page);
    const response = await page.goto('/training/crew-planner');
    expect(response?.headers()['content-security-policy']).toContain("form-action 'self'");
    await expect(page.getByRole('heading', { name: 'Crew Planner', exact: true })).toBeVisible();
    await waitForLivewire(page);
    const source = page.locator('[data-roster-source="verified"]');
    await expect(source).toBeVisible();
    await expect(source).toContainText('TeleStaff roster');
    await expect(source).toContainText('Shift');
    await expect(page.locator('.crew-planner input[type="file"]')).toHaveCount(0);
    await expect(page.locator('.crew-planner form')).toHaveCount(0);
    await expect(page.locator('.crew-planner').getByText(/upload|daily report|PDF roster/i)).toHaveCount(0);
    expect((await page.request.post('/training/crew-planner/upload', { failOnStatusCode: false })).status()).toBe(404);
    expect((await page.request.get('/training/crew-planner/upload', { failOnStatusCode: false })).status()).toBe(404);
});

test('pick a course and members, review a simple list, add sessions at a station and a typed venue, assign, move, download, send and refresh', async ({ page }, info) => {
    test.setTimeout(300_000);
    await login(page, 'training_publisher');
    const baseline = await fingerprint(page);
    await openBlankPlan(page);
    const date = await planDate(page);

    // 1 Class: nothing is chosen yet, then choosing a course applies it with no apply button.
    await expect(course(page)).toHaveValue('');
    await expect(page.getByRole('button', { name: /Use this scope|Apply/ })).toHaveCount(0);
    await chooseCourse(page, 'Synthetic new course');

    // 2 Personnel: nobody is selected for you, and unselecting never hides the person.
    await expect(members(page)).toHaveCount(3);
    await expect(page.getByText('3 eligible · 0 selected', { exact: true })).toBeVisible();
    await candidate(page, '90001').check();
    await candidate(page, '90002').check();
    await expect(page.getByText('3 eligible · 2 selected', { exact: true })).toBeVisible();
    await candidate(page, '90002').uncheck();
    await expect(candidate(page, '90002')).toBeVisible();
    await candidate(page, '90002').check();

    // 4 Review with no session: one simple list.
    await expect(page.getByRole('heading', { name: /^Class list/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: /^Class list/ })).toContainText('(2)');
    await expect(reviewList(page)).toHaveCount(2);
    await expect(page.getByText('No sessions yet. This plan goes out as one list.', { exact: false })).toBeHidden();

    // 3 Sessions: the first at a Hub station, the second at a typed venue.
    await openSessions(page);
    await expect(page.getByText('No sessions yet. This plan goes out as one list.', { exact: false })).toBeVisible();
    await expect(page.getByLabel('Session 1 start time', { exact: true })).toHaveValue('');
    await enterSession(page, '09:00', '11:00', 0);
    await chooseLocation(page, 0, 'Synthetic Station 99');
    await expect(page.getByText(/Saved: Session 1 09:00–11:00 at Synthetic Station 99/)).toBeVisible();
    await page.getByRole('button', { name: 'Add another session', exact: true }).click();
    await enterSession(page, '13:00', '15:00', 1);
    await chooseLocation(page, 1, 'Other location');
    await enterVenue(page, 'Synthetic Training Tower, Room 4', 1);
    await expect(page.getByText(/Saved: Session 1 09:00–11:00 at Synthetic Station 99 · Session 2 13:00–15:00 at Synthetic Training Tower, Room 4/)).toBeVisible();

    // Members are not placed until someone chooses: assign everyone to Session 1, then move one to Session 2.
    await expect(page.getByRole('heading', { name: /^No session chosen yet/ })).toBeVisible();
    await clickButton(page, 'Assign to Session 1');
    const first = page.getByRole('heading', { name: /^Session 1 — 09:00–11:00 · Synthetic Station 99/ });
    await expect(first).toContainText('(2)');
    await page.getByRole('checkbox', { name: 'Check Synthetic 90002', exact: true }).check();
    await page.locator('select[wire\\:model="targetSlot"]').selectOption({ label: 'Session 2 13:00–15:00' });
    await clickButton(page, 'Move');
    await expect(first).toContainText('(1)');
    const second = page.getByRole('heading', { name: /^Session 2 — 13:00–15:00 · Synthetic Training Tower, Room 4/ });
    await expect(second).toContainText('(1)');
    await expect(page.locator('section[aria-labelledby^="review-"]').filter({ has: second })).toContainText('Synthetic 90002');

    // Both downloads are the same revalidated plan, named for the date and listing sessions with places.
    for (const [format, label] of [['pdf', 'Download PDF'], ['xlsx', 'Download Excel']] as const) {
        const path = info.outputPath(`synthetic-class-roster.${format}`);
        await download(page, label, format, date, path);
        if (info.project.name === 'desktop') {
            const content = inspectExport(path, format);
            for (const expected of ['Synthetic 90001', 'Synthetic 90002', 'Captain', 'Firefighter', 'Station 9', 'Engine 9', 'Synthetic Station 99', 'Synthetic Training Tower']) expect(content).toContain(expected);
            expect(content).not.toContain('Synthetic 90003');
            expect(content.toLowerCase()).not.toContain('uploaded');
        }
    }

    // 5 Publish: pick a destination, send, and see where it went.
    await expect(page.getByRole('button', { name: 'Send plan', exact: true })).toBeDisabled();
    await page.getByRole('checkbox', { name: /300 message card/ }).check();
    page.once('dialog', dialog => dialog.accept());
    await clickButton(page, 'Send plan');
    const status = page.locator('[data-crew-plan-status]');
    await expect(status).toBeVisible();
    await expect(status).toContainText('300 message card');
    await expect(status).toContainText('2 members');
    await expect(status.getByText('Sent', { exact: true })).toBeVisible();

    // A refresh finds the same plan and the same send record, because the plan is saved on the server.
    await page.reload();
    await waitForLivewire(page);
    await expect(first).toContainText('(1)');
    await expect(second).toContainText('(1)');
    await expect(page.locator('[data-crew-plan-status]').getByText('Sent', { exact: true })).toBeVisible();
    await expect(page.locator('[data-crew-plan-status]')).toContainText('300 message card');
    expect(await fingerprint(page)).toEqual(baseline);
});

test('filters, select all shown, remove and undo keep the list right', async ({ page }) => {
    await login(page);
    await openBlankPlan(page);
    await chooseCourse(page, 'Synthetic new course');
    await page.getByText('More filters', { exact: true }).click();
    await page.getByRole('combobox', { name: 'Rank', exact: true }).selectOption('Captain');
    await expect(members(page)).toHaveCount(1);
    await clickButton(page, 'Select all 1 shown');
    await expect(reviewList(page)).toHaveCount(1);
    await page.getByRole('combobox', { name: 'Rank', exact: true }).selectOption('Firefighter');
    await expect(members(page)).toHaveCount(1);
    await candidate(page, '90002').check();
    await expect(reviewList(page)).toHaveCount(2);
    await page.getByRole('combobox', { name: 'Rank', exact: true }).selectOption('');
    await expect(members(page)).toHaveCount(3);
    const remove = reviewList(page).filter({ hasText: 'Synthetic 90001' }).getByRole('button', { name: 'Remove Synthetic 90001' });
    await remove.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await remove.click();
    await expect(reviewList(page)).toHaveCount(1);
    await expect(candidate(page, '90001')).toBeVisible();
    await clickButton(page, 'Undo remove');
    await expect(reviewList(page)).toHaveCount(2);
    await clickButton(page, 'Clear selection');
    await expect(reviewList(page)).toHaveCount(0);
});

test('module choice applies at once, excludes verified prior attendance and explains why', async ({ page }) => {
    await login(page);
    await openBlankPlan(page);
    await course(page).selectOption({ label: 'Synthetic modular course' });
    await page.getByRole('combobox', { name: 'Module', exact: true }).selectOption({ label: 'Synthetic Module 1' });
    await expect(candidate(page, '90001')).toHaveCount(0);
    await expect(members(page)).toHaveCount(2);
    await page.getByText("Why isn't someone listed?", { exact: true }).click();
    await expect(page.locator('details').filter({ hasText: "Why isn't someone listed?" })).toContainText('Already attended this module (verified record)');
    await page.getByRole('combobox', { name: 'Module', exact: true }).selectOption({ label: 'Synthetic Module 2' });
    await expect(candidate(page, '90001')).toBeEnabled();
});

test('picking a session location before the times keeps the sessions section open', async ({ page }) => {
    await login(page);
    await openBlankPlan(page);
    await chooseCourse(page, 'Synthetic new course');
    await openSessions(page);
    await chooseLocation(page, 0, 'Synthetic Station 99');
    await expect(page.locator('#crew-planner-classroom-times details')).toHaveJSProperty('open', true);
    await expect(page.getByLabel('Session 1 start time', { exact: true })).toBeVisible();
    await enterSession(page, '09:00', '11:00', 0);
    await expect(page.getByText(/Saved: Session 1 09:00–11:00 at Synthetic Station 99/)).toBeVisible();
});

test('viewer cannot open the planner', async ({ page }) => {
    await login(page, 'training_viewer');
    const response = await page.goto('/training/crew-planner');
    expect([403, 404]).toContain(response?.status());
    await expect(page.getByRole('button', { name: 'Send plan', exact: true })).toHaveCount(0);
    await expect(page.getByRole('combobox', { name: 'Course', exact: true })).toHaveCount(0);
});

test('results explain who is not listed and keep unmatched roster identities non-selectable', async ({ page }) => {
    await login(page);
    await openBlankPlan(page);
    await chooseCourse(page, 'Synthetic new course');
    await expect(page.getByText(/Attendance is checked (as it stood when class begins|against current verified Hub records as of)/)).toBeVisible();
    await expect(candidate(page, '90004')).toHaveCount(0);
    await expect(page.getByRole('checkbox', { name: /90099/ })).toHaveCount(0);
    const before = await fingerprint(page);
    // Searching for someone who is absent opens the explanation at once.
    await page.getByLabel('Find a member', { exact: true }).fill('90004');
    const why = page.locator('details').filter({ hasText: "Why isn't someone listed?" });
    await expect(why).toContainText('Synthetic 90004');
    await expect(why).toContainText('Duty needs verification');
    await expect(why).not.toContainText('NO_PRIOR_ATTENDANCE');
    await expect(why).not.toContainText('ZZZ');
    await page.getByLabel('Find a member', { exact: true }).fill('90099');
    await expect(why).toContainText('SYNTHETIC, 90099');
    await expect(why).toContainText('Not matched to an active Hub employee (ID 90099)');
    await page.getByLabel('Find a member', { exact: true }).fill('');
    expect(await fingerprint(page)).toEqual(before);
});

test('authorized administrator finds the same independent planner through the Admin Training menu', async ({ page }) => {
    await login(page, 'super_admin');
    const response = await page.goto('/admin/crew-planner');
    expect(response?.status()).toBe(200);
    expect(response?.headers()['content-security-policy']).toContain("form-action 'self'");
    await expect(page.getByRole('heading', { name: 'Crew Planner', exact: true })).toBeVisible();
    const openSidebar = page.getByRole('button', { name: 'Expand sidebar', exact: true }).locator('visible=true');
    if (await openSidebar.isVisible()) await openSidebar.click();
    await page.locator('.fi-sidebar-group-label').filter({ hasText: /^\s*Training\s*$/ }).click();
    const plannerLink = page.locator('.fi-sidebar').getByRole('link', { name: 'Crew Planner', exact: true });
    await expect(plannerLink).toHaveAttribute('href', /\/admin\/crew-planner$/);
    await plannerLink.click();
    await expect(page.getByRole('heading', { name: 'Crew Planner', exact: true })).toBeVisible();
    await waitForLivewire(page);
    await expect(page.locator('.crew-planner input[type="file"]')).toHaveCount(0);
    await expect(page.locator('[data-roster-source="verified"]')).toBeVisible();
    const baseline = await fingerprint(page);
    page.once('dialog', dialog => dialog.accept());
    await clickButton(page, 'Start over');
    await chooseCourse(page, 'Synthetic new course');
    await expect(members(page)).toHaveCount(3);
    expect(await fingerprint(page)).toEqual(baseline);
});

test('the full plan fits at 320, 390 and 1280 px wide and keeps its targets large on touch screens', async ({ page }, info) => {
    test.setTimeout(240_000);
    await login(page);
    await planWithTwoMembers(page);
    await openSessions(page);
    await enterSession(page, '09:00', '11:00', 0);
    await chooseLocation(page, 0, 'Synthetic Station 99');
    await page.getByRole('button', { name: 'Add another session', exact: true }).click();
    await enterSession(page, '13:00', '15:00', 1);
    await chooseLocation(page, 1, 'Other location');
    await enterVenue(page, 'Synthetic Training Tower, Room 4', 1);
    await expect(page.getByText(/Saved: Session 1 .* Session 2 13:00–15:00/)).toBeVisible();
    await clickButton(page, 'Assign to Session 1');
    await expect(page.getByRole('heading', { name: /^Session 1 —/ })).toContainText('(2)');

    const coarse = info.project.name !== 'desktop';
    for (const [width, height] of [[320, 700], [390, 844], [1280, 900]] as const) {
        await page.setViewportSize({ width, height });
        await page.reload();
        await waitForLivewire(page);
        await expect(page.getByRole('heading', { name: /^Session 1 —/ })).toBeVisible();
        await openSessions(page);
        const fit = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth, body: document.body.scrollWidth }));
        expect(fit.scroll, `${width}px page scrollWidth`).toBeLessThanOrEqual(fit.client);
        expect(fit.body, `${width}px body scrollWidth`).toBeLessThanOrEqual(fit.client);
        const rowHeights = await page.locator('.crew-planner .cp-row').evaluateAll(rows => rows.map(row => row.getBoundingClientRect().height));
        expect(rowHeights.length).toBe(3);
        expect(rowHeights.every(h => h >= 44)).toBe(true);
        if (coarse) expect(await smallTargets(page), `${width}px targets under 44px`).toEqual([]);
        await page.screenshot({ path: info.outputPath(`synthetic-planner-${width}.png`), fullPage: true });
    }
});
