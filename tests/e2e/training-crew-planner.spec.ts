import { test, expect, Page, Route } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

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
    const uploadStarted = page.waitForRequest(request => request.method() === 'POST' && new URL(request.url()).pathname === '/training/crew-planner/upload');
    const upload = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/training/crew-planner/upload');
    const submitted = clickButton(page, 'Read daily report');
    try {
        await uploadStarted;
        await expect(page.getByRole('status').filter({ hasText: 'Reading daily report. Please wait while the schedule is checked.' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Reading daily report…', exact: true })).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Clear file selection', exact: true })).toBeDisabled();
    } finally {
        releaseUpload();
    }
    await submitted;
    expect((await upload).status(), 'Native daily-report upload must redirect successfully').toBe(302);
    await page.unroute(uploadPattern, holdRequest);
    await page.waitForLoadState('load');
    await page.waitForFunction('window.Livewire && window.Livewire.all().length > 0');
    await expect(page.getByText('Roster date: 2026-10-21', { exact: false })).toBeVisible();
    await expect(page.getByText('This snapshot was generated before its roster date.', { exact: false })).toBeVisible();
    await expect(page.getByText('Daily report read successfully.', { exact: true })).toBeVisible();
    await expect(page.getByText('Next, choose a course above and select Use this scope to check attendance.', { exact: true })).toBeVisible();
    expect(await (await page.request.get('/__crew_domain_fingerprint')).json()).toEqual(baseline);
    return baseline;
}

async function freshProposal(page: Page) {
    await login(page);
    const plannerResponse = await page.goto('/training/crew-planner');
    expect(plannerResponse?.headers()['content-security-policy']).toContain("form-action 'self'");
    await expect(page.getByRole('heading', { name: 'Crew Planner', exact: true })).toBeVisible();
    const baseline = await uploadBeforeScope(page);
    await page.getByRole('combobox', { name: 'Course', exact: true }).selectOption({ label: 'Synthetic new course' });
    await clickButton(page, 'Use this scope');
    await expect(page.getByText('The proposal excludes members with verified attendance', { exact: false })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Save classroom times above to show eligible members.', exact: true })).toBeVisible();
    // Recheck after the Livewire render, when an absolute URL could switch to the apex host.
    await expect(page.locator('.crew-planner form[enctype="multipart/form-data"]')).toHaveAttribute('action', '/training/crew-planner/upload');
    await clickButton(page, 'Save classroom times');
    await expect(page.getByRole('checkbox', { name: /^Propose Synthetic/ })).toHaveCount(3);
    return baseline;
}

const planned = (page: Page) => page.locator('.crew-planner li').filter({ has: page.locator('input[aria-label^="Select proposed member"]') });

function inspectExport(path: string, format: 'pdf' | 'xlsx'): string {
    const dependencies = process.env.CREW_PLANNER_DEPENDENCY_ROOT || process.env.CREW_PLANNER_SOURCE_ROOT || process.cwd();
    const code = format === 'pdf'
        ? 'require $argv[1]."/vendor/autoload.php"; echo (new Smalot\\PdfParser\\Parser)->parseFile($argv[2])->getText();'
        : 'require $argv[1]."/vendor/autoload.php"; $book=PhpOffice\\PhpSpreadsheet\\IOFactory::load($argv[2]); $data=[];foreach($book->getAllSheets() as $sheet){$data[$sheet->getTitle()]=$sheet->toArray();} echo json_encode($data,JSON_THROW_ON_ERROR);';
    return execFileSync(process.env.PHP_BINARY || 'php', ['-r', code, dependencies, path], { encoding: 'utf8' });
}

test('native upload and cumulative editable proposals survive filters, downloads, refresh and offline interruption', async ({ page }, info) => {
    test.setTimeout(300_000);
    const baseline = await freshProposal(page);
    await page.getByRole('combobox', { name: 'Rank', exact: true }).selectOption('Captain');
    await expect(page.getByRole('checkbox', { name: /^Propose Synthetic/ })).toHaveCount(1);
    await clickButton(page, 'Add all eligible in these results');
    await expect(planned(page)).toHaveCount(1);
    await page.getByRole('combobox', { name: 'Rank', exact: true }).selectOption('Firefighter');
    await expect(page.getByRole('checkbox', { name: /^Propose Synthetic/ })).toHaveCount(1);
    await page.getByRole('checkbox', { name: 'Propose Synthetic 90002', exact: true }).check();
    await clickButton(page, 'Add selected to planning list');
    await expect(planned(page)).toHaveCount(2);
    await page.getByRole('combobox', { name: 'Rank', exact: true }).selectOption('Captain');
    await expect(page.getByRole('checkbox', { name: /^Propose Synthetic/ })).toHaveCount(1);
    await clickButton(page, 'Add all eligible in these results');
    await expect(planned(page)).toHaveCount(2);
    const remove = planned(page).filter({ hasText: 'Synthetic 90001' }).getByRole('button', { name: 'Remove from proposal' });
    await remove.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await remove.click();
    await expect(planned(page)).toHaveCount(1);
    await clickButton(page, 'Undo remove');
    await expect(planned(page)).toHaveCount(2);
    for (const [member, slot] of [['90001', 'Morning'], ['90002', 'Afternoon']]) {
        await page.getByRole('checkbox', { name: `Select proposed member Synthetic ${member}`, exact: true }).check();
        await page.getByRole('combobox', { name: 'Move selected to', exact: true }).selectOption({ label: slot });
        await clickButton(page, 'Move selected');
        await expect(planned(page).filter({ hasText: `Synthetic ${member}` })).toContainText(slot);
    }
    for (const [format, label] of [['pdf', 'Download proposal PDF'], ['xlsx', 'Download proposal Excel']] as const) {
        const pending = page.waitForEvent('download');
        await clickButton(page, label);
        const download = await pending;
        const path = info.outputPath(`synthetic-proposal.${format}`);
        await download.saveAs(path);
        expect(readFileSync(path).subarray(0, format === 'pdf' ? 5 : 2).toString()).toBe(format === 'pdf' ? '%PDF-' : 'PK');
        if (info.project.name === 'desktop') {
            const content = inspectExport(path, format);
            for (const expected of ['Synthetic 90001', 'Synthetic 90002', 'Captain', 'Firefighter', 'Station 9', 'Engine 9', 'Battalion Chief 300']) expect(content).toContain(expected);
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
    await page.locator('.fi-section').filter({ has: page.getByRole('heading', { name: 'Planning list · 2 proposed', exact: true }) }).screenshot({ path: info.outputPath('synthetic-planning-list.png') });
    page.once('dialog', dialog => dialog.accept());
    await clickButton(page, 'Discard proposal');
    await expect(planned(page)).toHaveCount(0);
    expect(await (await page.request.get('/__crew_domain_fingerprint')).json()).toEqual(baseline);
});

test('module scope excludes verified prior attendance and restores candidates for another module', async ({ page }) => {
    await freshProposal(page);
    await page.getByRole('combobox', { name: 'Course', exact: true }).selectOption({ label: 'Synthetic modular course' });
    await page.getByRole('combobox', { name: 'Training scope', exact: true }).selectOption({ label: 'Synthetic Module 1 — no verified session attended' });
    await clickButton(page, 'Use this scope');
    await expect(page.getByRole('checkbox', { name: 'Propose Synthetic 90001', exact: true })).toHaveCount(0);
    await expect(page.getByRole('checkbox', { name: /^Propose Synthetic/ })).toHaveCount(2);
    await page.getByRole('combobox', { name: 'Training scope', exact: true }).selectOption({ label: 'Synthetic Module 2 — no verified session attended' });
    await clickButton(page, 'Use this scope');
    await expect(page.getByRole('checkbox', { name: 'Propose Synthetic 90001', exact: true })).toBeEnabled();
});

test('viewer cannot open the planner', async ({ page }) => {
    await login(page, 'training_viewer');
    const response = await page.goto('/training/crew-planner');
    expect([403, 404]).toContain(response?.status());
    await expect(page.getByRole('button', { name: 'Read daily report', exact: true })).toHaveCount(0);
});

test('review results explain unavailable duty and keep unmatched identities non-selectable', async ({ page }) => {
    await freshProposal(page);
    await expect(page.getByText('Attendance is checked against current verified Hub records as of', { exact: false })).toBeVisible();
    await page.getByText('Published bid preferences and review results', { exact: true }).click();
    await page.getByLabel('Show members requiring review / already attended', { exact: true }).check();
    await expect(page.getByRole('checkbox', { name: 'Propose Synthetic 90004', exact: true })).toBeDisabled();
    const disabled = page.locator('li').filter({ has: page.getByRole('checkbox', { name: 'Propose Synthetic 90004', exact: true }) });
    await expect(disabled).toContainText('Needs training · staffing requires verification');
    await expect(disabled).not.toContainText('NO_PRIOR_ATTENDANCE');
    await expect(disabled).not.toContainText('ZZTEST');
    await expect(page.getByRole('heading', { name: 'Unmatched schedule identities — verify before proposing', exact: true })).toBeVisible();
    await expect(page.getByText('SYNTHETIC, 90099', { exact: true })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: /90099/ })).toHaveCount(0);
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
    await page.getByRole('combobox', { name: 'Course', exact: true }).selectOption({ label: 'Synthetic new course' });
    await clickButton(page, 'Use this scope');
    await clickButton(page, 'Save classroom times');
    await expect(page.getByRole('checkbox', { name: /^Propose Synthetic/ })).toHaveCount(3);
    expect(await (await page.request.get('/__crew_domain_fingerprint')).json()).toEqual(baseline);
});

test('flexible classroom and proposal fields retain readable widths on touch screens', async ({ page }, info) => {
    await freshProposal(page);
    const widths = await page.locator('.crew-planner label.flex-1').evaluateAll(labels => labels.map(label => label.getBoundingClientRect().width));
    expect(widths.length).toBeGreaterThan(3);
    expect(widths.every(width => width >= 190)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath('synthetic-readable-fields.png'), fullPage: true });
});
