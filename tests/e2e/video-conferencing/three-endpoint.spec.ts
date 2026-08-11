import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';

const employeeId = process.env.VIDEO_CONFERENCING_E2E_EMPLOYEE_ID;
const password = process.env.VIDEO_CONFERENCING_E2E_PASSWORD;
const commandPin = process.env.VIDEO_CONFERENCING_E2E_COMMAND_PIN;
const forceRelay = process.env.VIDEO_CONFERENCING_E2E_FORCE_RELAY === 'true';

test.skip(!employeeId || !password || !commandPin, 'Set the explicit conference E2E employee credentials and 300 command PIN.');

async function loginAndPrepare(
    browser: Browser,
    baseURL: string,
    joinAs: '300' | 'sta1' | 'sta2',
    mode: 'lineup' | 'direct' = 'lineup',
): Promise<{ context: BrowserContext; page: Page }> {
    const context = await browser.newContext({
        baseURL,
        viewport: { width: 1280, height: 900 },
        permissions: ['camera', 'microphone'],
    });
    await context.addInitScript(() => {
        const NativePeerConnection = window.RTCPeerConnection;
        const peerConnections: RTCPeerConnection[] = [];

        window.RTCPeerConnection = new Proxy(NativePeerConnection, {
            construct(target, argumentsList) {
                const peerConnection = Reflect.construct(target, argumentsList) as RTCPeerConnection;
                peerConnections.push(peerConnection);

                return peerConnection;
            },
        });

        Object.defineProperty(window, '__mbfdPeerConnections', {
            value: peerConnections,
            configurable: false,
            enumerable: false,
            writable: false,
        });
    });
    const page = await context.newPage();
    const relayQuery = forceRelay ? '&force_relay=1' : '';
    await page.goto(`/employee/video-conferencing?room=${mode}&join_as=${joinAs}${relayQuery}`);
    await page.getByLabel('Employee ID').fill(employeeId!);
    await page.getByLabel('Password').fill(password!);
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page.locator('.vc-shell')).toBeVisible();
    await expect(page.locator('.vc-shell')).toHaveAttribute('data-ice-policy', forceRelay ? 'relay' : 'all');
    await page.getByRole('button', { name: 'Test devices' }).click();
    await expect(page.locator('.vc-shell')).toHaveAttribute('data-phase', 'ready');
    if (joinAs === '300') await page.getByLabel('300 command PIN').fill(commandPin!);

    return { context, page };
}

async function everyPeerConnectionIsRelayOnly(page: Page): Promise<boolean> {
    return page.evaluate(() => {
        const peerConnections = (window as Window & { __mbfdPeerConnections?: RTCPeerConnection[] })
            .__mbfdPeerConnections ?? [];

        return peerConnections.length > 0
            && peerConnections.every((peerConnection) => peerConnection.getConfiguration().iceTransportPolicy === 'relay');
    });
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
        await expect(station1.page.locator('.vc-station-mic')).toContainText('MUTED BY 300');
        await expect.poll(() => command.page.evaluate(() => document.documentElement.scrollHeight <= window.innerHeight + 1)).toBe(true);

        if (forceRelay) {
            await Promise.all([
                expect.poll(() => everyPeerConnectionIsRelayOnly(command.page)).toBe(true),
                expect.poll(() => everyPeerConnectionIsRelayOnly(station1.page)).toBe(true),
                expect.poll(() => everyPeerConnectionIsRelayOnly(station2.page)).toBe(true),
            ]);
        }

        await command.page.getByRole('button', { name: 'Mute all stations' }).click();
        const station1Control = command.page.locator('.vc-command__stations > div').filter({ hasText: 'Station 1' });
        await station1Control.getByRole('button', { name: 'Request mic on' }).click();
        await expect(station1.page.locator('.vc-station-mic')).toContainText('MIC LIVE');
    } finally {
        await Promise.all([command.context.close(), station1.context.close(), station2.context.close()]);
    }
});

test('300 PIN, relay-only direct call, and desktop no-scroll layout work together', async ({ browser, baseURL }) => {
    const command = await loginAndPrepare(browser, baseURL!, '300', 'direct');
    const station1 = await loginAndPrepare(browser, baseURL!, 'sta1', 'direct');

    try {
        await command.page.getByRole('button', { name: 'Join conference' }).click();
        await expect(command.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await expect.poll(() => command.page.evaluate(() => document.documentElement.scrollHeight <= window.innerHeight + 1)).toBe(true);
        await station1.page.getByRole('button', { name: 'Join conference' }).click();
        await expect(station1.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await expect(command.page.locator('.vc-tile')).toHaveCount(2);

        if (forceRelay) {
            await Promise.all([
                expect.poll(() => everyPeerConnectionIsRelayOnly(command.page)).toBe(true),
                expect.poll(() => everyPeerConnectionIsRelayOnly(station1.page)).toBe(true),
            ]);
        }

        await command.page.getByRole('button', { name: 'Mute all stations' }).click();
        const station1Control = command.page.locator('.vc-command__stations > div').filter({ hasText: 'Station 1' });
        await station1Control.getByRole('button', { name: 'Request mic on' }).click();
        await expect(station1.page.locator('.vc-station-mic')).toContainText('MIC LIVE');
    } finally {
        await Promise.all([command.context.close(), station1.context.close()]);
    }
});
