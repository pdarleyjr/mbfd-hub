import { test, expect, Page, Route } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';

async function clickButton(page: Page, name: string) {
    const button = page.getByRole('button', { name, exact: true });
    await expect(button).toBeEnabled();
    await button.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await button.click();
}

async function login(page: Page, role = 'training_admin') {
    const identity = role === 'training_admin' ? `${role}-${test.info().project.name}` : role;
    await page.goto('/login');
    const response = await page.request.post('/login', { form: {
        _token: await page.locator('input[name="_token"]').inputValue(),
        employee_id: `${identity}@example.test`, password: 'synthetic-crew-password',
    }, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location).not.toContain('/login');
}

async function uploadBeforeScope(page: Page) {
    await expect(page.getByRole('combobox', { name: 'Course', exact: true })).toHaveValue('');
    await expect(page.locator('.crew-planner form[enctype="multipart/form-data"]')).toHaveAttribute('action', '/training/crew-planner/upload');
    const baseline = await (await page.request.get('/__crew_domain_fingerprint')).json();
    const pdf = await page.request.get('/__synthetic_daily.pdf');
    await page.getByLabel('Daily TeleStaff PDF', { exact: true }).setInputFiles({ name: 'synthetic-daily.pdf', mimeType: 'application/pdf', buffer: await pdf.body() });
    let releaseUpload!: () => void;
    const heldUpload = new Promise<void>(resolve => { releaseUpload = resolve; });
    const uploadPattern = '**/training/crew-planner/upload';
    const holdRequest = async (route: Route) => { await heldUpload; await route.continue(); };
    await page.route(uploadPattern, holdRequest);
    let pendingReceipt: unknown = null;
    await page.exposeFunction('__crewPlannerPending', (receipt: unknown) => { pendingReceipt = receipt; });
    // Capture controls/text before navigation; compositor pixels prove the visible
    // announcement separately, without timing Alpine's deferred x-show transition.
    await page.evaluate(() => {
        const form = document.querySelector<HTMLFormElement>('.crew-planner form[enctype="multipart/form-data"]')!;
        form.addEventListener('submit', () => queueMicrotask(() => {
            const status = form.querySelector<HTMLElement>('[role="status"]')!;
            (window as unknown as { __crewPlannerPending: (receipt: unknown) => void }).__crewPlannerPending({
                announcementBlockedByBusy: status.closest('[aria-busy="true"]') !== null,
                statusText: status.textContent?.trim(),
                submitDisabled: form.querySelector<HTMLButtonElement>('button[type="submit"]')!.disabled,
                resetDisabled: form.querySelector<HTMLButtonElement>('button[type="reset"]')!.disabled,
            });
        }), { once: true });
    });
    const uploadStarted = page.waitForRequest(request => request.method() === 'POST' && new URL(request.url()).pathname === '/training/crew-planner/upload');
    const upload = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/training/crew-planner/upload');
    const submitted = clickButton(page, 'Read daily report');
    let compositor: import('@playwright/test').CDPSession | undefined;
    try {
        await uploadStarted;
        if (test.info().title.startsWith('review results')) {
            compositor = await page.context().newCDPSession(page);
            const { data } = await compositor.send('Page.captureScreenshot', { format: 'png' });
            writeFileSync(test.info().outputPath('synthetic-reading-report.png'), Buffer.from(data, 'base64'));
        }
        await expect.poll(() => pendingReceipt).toEqual({ announcementBlockedByBusy: false, statusText: 'Reading daily report. Please wait while the schedule is checked.', submitDisabled: true, resetDisabled: true });
    } finally {
        releaseUpload();
    }
    await submitted;
    await compositor?.detach();
    expect((await upload).status(), 'Native daily-report upload must redirect successfully').toBe(302);
    await page.waitForLoadState('load');
    await page.waitForFunction('window.Livewire && window.Livewire.all().length > 0');
    await expect(page.getByText('Roster date: 2026-10-21', { exact: false })).toBeVisible();
    await page.unroute(uploadPattern, holdRequest);
    await expect(page.getByText('This snapshot was generated before its roster date.', { exact: false })).toBeVisible();
    await expect(page.getByText('Daily report read successfully.', { exact: true })).toBeVisible();
    await expect(page.getByText('Next, choose the training in step 1.', { exact: true })).toBeVisible();
    expect(await (await page.request.get('/__crew_domain_fingerprint')).json()).toEqual(baseline);
    return baseline;
}

async function enterSession(page: Page, start: string, end: string, index = 0) {
    await page.getByLabel(`Session ${index + 1} start time`, { exact: true }).fill(start);
    await page.getByLabel(`Session ${index + 1} end time`, { exact: true }).fill(end);
}

async function chooseCourse(page: Page, label: string) {
    await page.getByRole('combobox', { name: 'Course', exact: true }).selectOption({ label });
    // Wait for the automatic apply before typing, so a late render cannot replace typed times.
    await expect(page.getByText('Leaving out members who already attended', { exact: false })).toBeVisible();
}

async function freshProposal(page: Page) {
    await login(page);
    const plannerResponse = await page.goto('/training/crew-planner');
    expect(plannerResponse?.headers()['content-security-policy']).toContain("form-action 'self'");
    await expect(page.getByRole('heading', { name: 'Crew Planner', exact: true })).toBeVisible();
    const baseline = await uploadBeforeScope(page);
    // Choosing a course applies it at once: there is no separate apply button.
    await chooseCourse(page, 'Synthetic new course');
    await expect(page.getByText('Enter the class start and end time in step 3 to see eligible members.', { exact: true })).toBeVisible();
    // Recheck after the Livewire render, when an absolute URL could switch to the apex host.
    await expect(page.locator('.crew-planner form[enctype="multipart/form-data"]')).toHaveAttribute('action', '/training/crew-planner/upload');
    // No invented times: the row starts empty and the session saves itself once both times are typed.
    await expect(page.getByLabel('Session 1 start time', { exact: true })).toHaveValue('');
    await enterSession(page, '08:30', '11:30');
    await expect(page.getByText('Saved: Session 1 08:30–11:30', { exact: true })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: /^Select Synthetic/ })).toHaveCount(3);
    await expect(page.getByText('3 eligible · 0 selected', { exact: true })).toBeVisible();
    return baseline;
}

const planned = (page: Page) => page.locator('.crew-planner section[aria-labelledby^="review-"]:not(#review-verify) li');
const candidate = (page: Page, member: string) => page.getByRole('checkbox', { name: `Select Synthetic ${member}`, exact: true });

function inspectExport(path: string, format: 'pdf' | 'xlsx'): string {
    const dependencies = process.env.CREW_PLANNER_DEPENDENCY_ROOT || process.env.CREW_PLANNER_SOURCE_ROOT || process.cwd();
    const code = format === 'pdf'
        ? 'require $argv[1]."/vendor/autoload.php"; echo (new Smalot\\PdfParser\\Parser)->parseFile($argv[2])->getText();'
        : 'require $argv[1]."/vendor/autoload.php"; $book=PhpOffice\\PhpSpreadsheet\\IOFactory::load($argv[2]); $data=[];foreach($book->getAllSheets() as $sheet){$data[$sheet->getTitle()]=$sheet->toArray();} echo json_encode($data,JSON_THROW_ON_ERROR);';
    return execFileSync(process.env.PHP_BINARY || 'php', ['-r', code, dependencies, path], { encoding: 'utf8' });
}

test('native upload, one-tap selection and per-session review survive filters, downloads, refresh and offline interruption', async ({ page }, info) => {
    test.setTimeout(300_000);
    const baseline = await freshProposal(page);
    await page.getByText('More filters', { exact: true }).click();
    await page.getByRole('combobox', { name: 'Rank', exact: true }).selectOption('Captain');
    await expect(page.getByRole('checkbox', { name: /^Select Synthetic/ })).toHaveCount(1);
    await clickButton(page, 'Select all 1 shown');
    await expect(planned(page)).toHaveCount(1);
    await page.getByRole('combobox', { name: 'Rank', exact: true }).selectOption('Firefighter');
    await expect(page.getByRole('checkbox', { name: /^Select Synthetic/ })).toHaveCount(1);
    await candidate(page, '90002').check();
    await expect(planned(page)).toHaveCount(2);
    await page.getByRole('combobox', { name: 'Rank', exact: true }).selectOption('');
    await expect(page.getByRole('checkbox', { name: /^Select Synthetic/ })).toHaveCount(3);
    // Unselecting never hides the person from the candidate list.
    await candidate(page, '90002').uncheck();
    await expect(planned(page)).toHaveCount(1);
    await expect(candidate(page, '90002')).toBeVisible();
    await candidate(page, '90002').check();
    await expect(planned(page)).toHaveCount(2);
    const remove = planned(page).filter({ hasText: 'Synthetic 90001' }).getByRole('button', { name: 'Remove Synthetic 90001' });
    await remove.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await remove.click();
    await expect(planned(page)).toHaveCount(1);
    await clickButton(page, 'Undo remove');
    await expect(planned(page)).toHaveCount(2);
    // A second session adds a per-member choice; the one-session placement stays in Session 1.
    await page.getByRole('button', { name: 'Add another session', exact: true }).click();
    await enterSession(page, '13:00', '16:00', 1);
    await expect(page.getByText('Saved: Session 1 08:30–11:30 · Session 2 13:00–16:00', { exact: true })).toBeVisible();
    await page.getByLabel('Session for Synthetic 90002', { exact: true }).selectOption({ label: 'Session 2 13:00–16:00' });
    await expect(page.getByRole('heading', { name: /^Session 2 — 13:00–16:00/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: /^Session 1 — 08:30–11:30/ })).toBeVisible();
    for (const [format, label] of [['pdf', 'Export Training Personnel PDF'], ['xlsx', 'Download Excel']] as const) {
        const pending = page.waitForEvent('download');
        await clickButton(page, label);
        const download = await pending;
        expect(download.suggestedFilename()).toBe(`MBFD-Training-Personnel-Request-2026-10-21.${format}`);
        const path = info.outputPath(`synthetic-proposal.${format}`);
        await download.saveAs(path);
        expect(readFileSync(path).subarray(0, format === 'pdf' ? 5 : 2).toString()).toBe(format === 'pdf' ? '%PDF-' : 'PK');
        if (info.project.name === 'desktop') {
            const content = inspectExport(path, format);
            for (const expected of ['Synthetic 90001', 'Synthetic 90002', 'Captain', 'Firefighter', 'Station 9', 'Engine 9']) expect(content).toContain(expected);
            expect(content).not.toContain('Synthetic 90003');
        }
    }
    await page.reload();
    await expect(planned(page)).toHaveCount(2);
    await page.context().setOffline(true);
    await expect(page.getByText('Requires connection. Your saved proposal remains', { exact: false })).toBeVisible();
    await page.context().setOffline(false);
    await page.reload();
    await expect(planned(page)).toHaveCount(2);
    const measurements = await page.evaluate(() => ({
        viewport: innerWidth, document: document.documentElement.scrollWidth,
        buttons: [...document.querySelectorAll('.crew-planner button')].filter(el => (el as HTMLElement).offsetParent !== null).map(el => el.getBoundingClientRect().height),
    }));
    expect(measurements.document).toBeLessThanOrEqual(measurements.viewport + 1);
    expect(measurements.buttons.every(height => height >= 44)).toBe(true);
    await page.screenshot({ path: info.outputPath('synthetic-proposal-layout.png'), fullPage: true });
    await page.locator('.fi-section').filter({ hasText: '5. Review and export' }).last().screenshot({ path: info.outputPath('synthetic-review-export.png') });
    page.once('dialog', dialog => dialog.accept());
    await clickButton(page, 'Start over');
    await expect(planned(page)).toHaveCount(0);
    expect(await (await page.request.get('/__crew_domain_fingerprint')).json()).toEqual(baseline);
});

test('module choice applies at once, excludes verified prior attendance and explains why', async ({ page }) => {
    await freshProposal(page);
    await page.getByRole('combobox', { name: 'Course', exact: true }).selectOption({ label: 'Synthetic modular course' });
    await page.getByRole('combobox', { name: 'Module', exact: true }).selectOption({ label: 'Synthetic Module 1' });
    await expect(candidate(page, '90001')).toHaveCount(0);
    await expect(page.getByRole('checkbox', { name: /^Select Synthetic/ })).toHaveCount(2);
    await page.getByText("Why isn't someone listed?", { exact: true }).click();
    await expect(page.locator('details').filter({ hasText: "Why isn't someone listed?" })).toContainText('Already attended this module (verified record)');
    await page.getByRole('combobox', { name: 'Module', exact: true }).selectOption({ label: 'Synthetic Module 2' });
    await expect(candidate(page, '90001')).toBeEnabled();
});

test('viewer cannot open the planner', async ({ page }) => {
    await login(page, 'training_viewer');
    const response = await page.goto('/training/crew-planner');
    expect([403, 404]).toContain(response?.status());
    await expect(page.getByRole('button', { name: 'Read daily report', exact: true })).toHaveCount(0);
});

test('review results explain who is not listed and keep unmatched identities non-selectable', async ({ page }) => {
    await freshProposal(page);
    await expect(page.getByText(/Attendance is checked (as it stood when class begins|against current verified Hub records as of)/)).toBeVisible();
    await expect(candidate(page, '90004')).toHaveCount(0);
    await expect(page.getByRole('checkbox', { name: /90099/ })).toHaveCount(0);
    // Searching for someone who is absent opens the explanation at once.
    await page.getByLabel('Find a member', { exact: true }).fill('90004');
    const why = page.locator('details').filter({ hasText: "Why isn't someone listed?" });
    await expect(why).toContainText('Synthetic 90004');
    await expect(why).toContainText('Duty needs verification');
    await expect(why).not.toContainText('NO_PRIOR_ATTENDANCE');
    await expect(why).not.toContainText('ZZTEST');
    await page.getByLabel('Find a member', { exact: true }).fill('90099');
    await expect(why).toContainText('SYNTHETIC, 90099');
    await expect(why).toContainText('Not matched to an active Hub employee (ID 90099)');
    await page.getByLabel('Find a member', { exact: true }).fill('');
    const beforeRejectedUpload = await (await page.request.get('/__crew_domain_fingerprint')).json();
    await page.getByLabel('Daily TeleStaff PDF', { exact: true }).setInputFiles({ name: 'synthetic-invalid.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\ninvalid synthetic report') });
    const rejectedUpload = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/training/crew-planner/upload');
    await clickButton(page, 'Read daily report');
    expect((await rejectedUpload).status()).toBe(302);
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByText('The previous report remains saved.', { exact: true })).toBeVisible();
    await expect(page.getByText('Daily report read successfully.', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Roster date: 2026-10-21', { exact: false })).toBeVisible();
    expect(await (await page.request.get('/__crew_domain_fingerprint')).json()).toEqual(beforeRejectedUpload);
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
    await expect(page.getByRole('button', { name: 'Read daily report', exact: true })).toBeVisible();
    const baseline = await uploadBeforeScope(page);
    await chooseCourse(page, 'Synthetic new course');
    await enterSession(page, '08:30', '11:30');
    await expect(page.getByRole('checkbox', { name: /^Select Synthetic/ })).toHaveCount(3);
    expect(await (await page.request.get('/__crew_domain_fingerprint')).json()).toEqual(baseline);
});

test('flexible classroom and proposal fields retain readable widths on touch screens', async ({ page }, info) => {
    await freshProposal(page);
    const widths = await page.locator('.crew-planner label.flex-1').evaluateAll(labels => labels.map(label => label.getBoundingClientRect().width));
    expect(widths.length).toBeGreaterThan(1);
    expect(widths.every(width => width >= 190)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    const rowHeights = await page.locator('.crew-planner .cp-row').evaluateAll(rows => rows.map(row => row.getBoundingClientRect().height));
    expect(rowHeights.length).toBe(3);
    expect(rowHeights.every(height => height >= 44)).toBe(true);
    await page.screenshot({ path: info.outputPath('synthetic-readable-fields.png'), fullPage: true });
});
