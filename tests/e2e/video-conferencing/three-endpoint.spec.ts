import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';

const employeeId = process.env.VIDEO_CONFERENCING_E2E_EMPLOYEE_ID;
const password = process.env.VIDEO_CONFERENCING_E2E_PASSWORD;

test.skip(!employeeId || !password, 'Set the explicit conference E2E employee credentials.');

async function loginAndPrepare(
    browser: Browser,
    baseURL: string,
    joinAs: '300' | 'sta1' | 'sta2',
): Promise<{ context: BrowserContext; page: Page }> {
    const context = await browser.newContext({
        baseURL,
        viewport: { width: 1280, height: 900 },
        permissions: ['camera', 'microphone'],
    });
    const page = await context.newPage();
    await page.goto(`/employee/video-conferencing?room=lineup&join_as=${joinAs}`);
    await page.getByLabel('Employee ID').fill(employeeId!);
    await page.getByLabel('Password').fill(password!);
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page.locator('#video-conferencing-root')).toBeVisible();
    await page.getByRole('button', { name: 'Test devices' }).click();
    await expect(page.locator('.vc-shell')).toHaveAttribute('data-phase', 'ready');

    return { context, page };
}

test('300, Station 1, and Station 2 share Morning Lineup and moderation works', async ({ browser, baseURL }) => {
    const command = await loginAndPrepare(browser, baseURL!, '300');
    const station1 = await loginAndPrepare(browser, baseURL!, 'sta1');
    const station2 = await loginAndPrepare(browser, baseURL!, 'sta2');

    try {
        await command.page.getByRole('button', { name: 'Join conference' }).click();
        await expect(command.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');

        await Promise.all([
            station1.page.getByRole('button', { name: 'Join conference' }).click(),
            station2.page.getByRole('button', { name: 'Join conference' }).click(),
        ]);
        await Promise.all([
            expect(station1.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected'),
            expect(station2.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected'),
        ]);
        await expect(command.page.locator('.vc-tile')).toHaveCount(3);
        await expect(station1.page.getByRole('status')).toContainText('MUTED BY 300');

        await command.page.getByRole('button', { name: 'Mute all stations' }).click();
        const station1Control = command.page.locator('.vc-command__stations > div').filter({ hasText: 'Station 1' });
        await station1Control.getByRole('button', { name: 'Request mic on' }).click();
        await expect(station1.page.getByRole('status')).toContainText('MIC LIVE');
    } finally {
        await Promise.all([command.context.close(), station1.context.close(), station2.context.close()]);
    }
});
