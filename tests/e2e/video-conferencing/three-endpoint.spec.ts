import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';

const employeeId = process.env.VIDEO_CONFERENCING_E2E_EMPLOYEE_ID;
const password = process.env.VIDEO_CONFERENCING_E2E_PASSWORD;
const commandPin = process.env.VIDEO_CONFERENCING_E2E_COMMAND_PIN;
const forceRelay = process.env.VIDEO_CONFERENCING_E2E_FORCE_RELAY === 'true';

test.skip(!employeeId || !password || !commandPin, 'Set explicit disposable conference E2E credentials and the 300 command PIN.');

type Endpoint = { context: BrowserContext; page: Page };

async function endpointContext(browser: Browser, baseURL: string): Promise<Endpoint> {
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
        Object.defineProperty(window, '__mbfdPeerConnections', { value: peerConnections });

        const nativeGetUserMedia = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
        let gainNode: GainNode | undefined;
        navigator.mediaDevices.getUserMedia = async (constraints) => {
            const stream = await nativeGetUserMedia(constraints);
            if (constraints.video) {
                for (const track of stream.getVideoTracks()) {
                    stream.removeTrack(track);
                    track.stop();
                }
                // Six simultaneous 720p fake-camera publishers can saturate one
                // acceptance host and cause artificial WebRTC renegotiations.
                // Keep the real LiveKit topology while using a lightweight,
                // animated source for the synthetic camera only.
                const canvas = document.createElement('canvas');
                canvas.width = 320;
                canvas.height = 180;
                const context = canvas.getContext('2d')!;
                let frame = 0;
                const draw = () => {
                    context.fillStyle = `hsl(${frame % 360} 65% 28%)`;
                    context.fillRect(0, 0, canvas.width, canvas.height);
                    context.fillStyle = '#fff';
                    context.font = '600 24px sans-serif';
                    context.fillText('MBFD TEST CAMERA', 35, 98);
                    frame += 4;
                };
                draw();
                window.setInterval(draw, 200);
                stream.addTrack(canvas.captureStream(5).getVideoTracks()[0]);
            }

            if (constraints.audio) {
                for (const track of stream.getAudioTracks()) {
                    stream.removeTrack(track);
                    track.stop();
                }
                const audio = new AudioContext();
                const oscillator = audio.createOscillator();
                gainNode = audio.createGain();
                gainNode.gain.value = 0;
                oscillator.frequency.value = 440;
                oscillator.connect(gainNode);
                const destination = audio.createMediaStreamDestination();
                gainNode.connect(destination);
                oscillator.start();
                await audio.resume();
                stream.addTrack(destination.stream.getAudioTracks()[0]);
            }

            return stream;
        };
        Object.defineProperty(window, '__mbfdSetAudioLevel', {
            value: (level: number) => {
                if (gainNode) gainNode.gain.value = level;
            },
        });
    });

    return { context, page: await context.newPage() };
}

async function prepareStation(browser: Browser, baseURL: string, station: 1 | 2 | 3 | 4 | 6): Promise<Endpoint> {
    const endpoint = await endpointContext(browser, baseURL);
    await endpoint.page.goto(`/video-conferencing/stations/${station}${forceRelay ? '?force_relay=1' : ''}`);
    await expect(endpoint.page.locator('.vc-shell')).toHaveAttribute('data-entry-mode', 'station');
    await expect(endpoint.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'standing_by');
    await expect(endpoint.page.getByText(`Station ${station}`, { exact: true }).first()).toBeVisible();
    await expect(endpoint.page.getByText('READY — STANDING BY', { exact: true })).toBeVisible();

    return endpoint;
}

async function prepareCommand(browser: Browser, baseURL: string): Promise<Endpoint> {
    const endpoint = await endpointContext(browser, baseURL);
    await endpoint.page.goto(`/employee/video-conferencing/command${forceRelay ? '?force_relay=1' : ''}`);
    if (endpoint.page.url().includes('/employee/login')) {
        await endpoint.page.getByLabel('Employee ID').fill(employeeId!);
        await endpoint.page.getByLabel('Password').fill(password!);
        await endpoint.page.getByRole('button', { name: /sign in/i }).click();
    }
    await expect(endpoint.page.locator('.vc-shell')).toHaveAttribute('data-entry-mode', 'command');
    await expect(endpoint.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'ready');
    await endpoint.page.getByLabel('300 command PIN').fill(commandPin!);
    await endpoint.page.getByRole('button', { name: 'Continue as 300' }).click();
    await expect(endpoint.page.getByText(/LiveKit Cloud API: Healthy/)).toBeVisible();

    return endpoint;
}

async function selectedCandidatePairsUseRelayOnly(page: Page): Promise<boolean> {
    return page.evaluate(async () => {
        const peerConnections = (window as Window & { __mbfdPeerConnections?: RTCPeerConnection[] })
            .__mbfdPeerConnections ?? [];
        if (peerConnections.length === 0) return false;

        for (const peerConnection of peerConnections) {
            if (peerConnection.getConfiguration().iceTransportPolicy !== 'relay') return false;
            const stats = await peerConnection.getStats();
            const transports = [...stats.values()].filter((report) => report.type === 'transport');
            const selectedPairIds = transports
                .map((transport) => transport.selectedCandidatePairId as string | undefined)
                .filter((id): id is string => Boolean(id));
            const selectedPairs = selectedPairIds.length > 0
                ? selectedPairIds.map((id) => stats.get(id)).filter(Boolean)
                : [...stats.values()].filter((report) => report.type === 'candidate-pair'
                    && report.state === 'succeeded'
                    && (report.selected === true || report.nominated === true));

            if (selectedPairs.length === 0) return false;
            for (const pair of selectedPairs) {
                const localCandidate = stats.get(pair.localCandidateId as string);
                if (localCandidate?.candidateType !== 'relay') return false;
            }
        }

        return true;
    });
}

async function setSyntheticAudioLevel(page: Page, level: number): Promise<void> {
    await page.evaluate((nextLevel) => {
        (window as Window & { __mbfdSetAudioLevel?: (value: number) => void }).__mbfdSetAudioLevel?.(nextLevel);
    }, level);
}

async function conferenceFitsWithoutPageScroll(page: Page): Promise<boolean> {
    return page.evaluate(() => {
        const shell = document.querySelector('.vc-shell');

        return shell !== null
            && getComputedStyle(document.documentElement).overflowY === 'hidden'
            && shell.getBoundingClientRect().bottom <= window.innerHeight + 1;
    });
}

test('one browser may deliberately move from Station 1 to Station 4 without a station login', async ({ browser, baseURL }) => {
    const endpoint = await prepareStation(browser, baseURL!, 1);
    try {
        await endpoint.page.goto('/video-conferencing/stations/4');
        await expect(endpoint.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'standing_by');
        await expect(endpoint.page.getByText('Station 4', { exact: true }).first()).toBeVisible();
        await expect(endpoint.page.getByLabel('Employee ID')).toHaveCount(0);
    } finally {
        await endpoint.context.close();
    }
});

test('five stations wait without LiveKit, then all join 300 and floor controls work', async ({ browser, baseURL }) => {
    test.setTimeout(240_000);
    const cloudRequests = new Map<number, number>();
    const stations = await Promise.all(([1, 2, 3, 4, 6] as const).map(async (station) => {
        const endpoint = await endpointContext(browser, baseURL!);
        cloudRequests.set(station, 0);
        endpoint.page.on('request', (request) => {
            if (request.url().includes('.livekit.cloud')) {
                cloudRequests.set(station, (cloudRequests.get(station) ?? 0) + 1);
            }
        });
        await endpoint.page.goto(`/video-conferencing/stations/${station}${forceRelay ? '?force_relay=1' : ''}`);
        await expect(endpoint.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'standing_by');

        return { station, ...endpoint };
    }));
    const command = await prepareCommand(browser, baseURL!);

    try {
        await new Promise((resolve) => setTimeout(resolve, 6_000));
        for (const station of stations) expect(cloudRequests.get(station.station)).toBe(0);
        for (const station of stations) {
            const row = command.page.locator('.vc-ready-list > div').filter({ hasText: `Station ${station.station}` });
            await expect(row).toContainText('READY');
        }

        await command.page.getByRole('button', { name: 'Start Morning Lineup' }).click();
        await expect(command.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await Promise.all(stations.map(({ page }) => expect(page.locator('.vc-shell')).toHaveAttribute(
            'data-phase',
            'connected',
            { timeout: 45_000 },
        )));
        await expect(command.page.locator('.vc-tile')).toHaveCount(6);
        await expect.poll(() => conferenceFitsWithoutPageScroll(command.page)).toBe(true);
        await command.page.setViewportSize({ width: 1280, height: 800 });
        await expect.poll(() => conferenceFitsWithoutPageScroll(command.page)).toBe(true);
        for (const station of stations) await expect(station.page.locator('.vc-station-mic')).toContainText('MIC MUTED');

        await setSyntheticAudioLevel(command.page, 0.45);
        await expect(command.page.locator('.vc-focus-stage .vc-mic')).toContainText('Speaking');

        if (forceRelay) {
            await expect.poll(() => selectedCandidatePairsUseRelayOnly(command.page)).toBe(true);
            for (const station of stations) {
                await expect.poll(() => selectedCandidatePairsUseRelayOnly(station.page)).toBe(true);
            }
        }

        const stationOne = command.page.locator('.vc-command__stations > div').filter({ hasText: 'Station 1' });
        const stationThree = command.page.locator('.vc-command__stations > div').filter({ hasText: 'Station 3' });
        await stationOne.getByRole('button', { name: 'Give Floor' }).click();
        await setSyntheticAudioLevel(stations[0].page, 0.8);
        await command.page.waitForTimeout(2_500);
        await setSyntheticAudioLevel(command.page, 0);
        await expect(stations[0].page.locator('.vc-station-mic')).toContainText('MIC LIVE');
        await expect(stations[0].page.locator('.vc-focus-stage .vc-mic')).toContainText('Speaking');
        await stationThree.getByRole('button', { name: 'Give Floor' }).click();
        await setSyntheticAudioLevel(stations[0].page, 0);
        await setSyntheticAudioLevel(stations[2].page, 0.9);
        await expect(stations[2].page.locator('.vc-station-mic')).toContainText('MIC LIVE');
        await expect(stations[2].page.locator('.vc-focus-stage .vc-mic')).toContainText('Speaking');
        await stationOne.getByRole('button', { name: 'Mute' }).click();
        await expect(stations[0].page.locator('.vc-station-mic')).toContainText('MIC MUTED');
        await command.page.getByRole('button', { name: 'Mute all stations' }).click();
        await expect(stations[2].page.locator('.vc-station-mic')).toContainText('MIC MUTED');

        await command.page.locator('.vc-controls').getByRole('button', { name: 'Mute' }).click();
        const stationTwo = command.page.locator('.vc-command__stations > div').filter({ hasText: 'Station 2' });
        await stationTwo.getByRole('button', { name: 'Give Floor' }).click();
        await setSyntheticAudioLevel(stations[2].page, 0);
        await setSyntheticAudioLevel(stations[1].page, 0.9);
        await expect(stations[1].page.locator('.vc-focus-stage .vc-mic')).toContainText('Speaking');

        await command.page.getByRole('button', { name: 'Share screen' }).click();
        await expect(stations[0].page.locator('.vc-focus-bar')).toContainText('Screen share');
        await command.page.getByRole('button', { name: 'Stop sharing' }).click();
        await expect(stations[0].page.locator('.vc-focus-bar')).toContainText('Auto speaker');

        await command.page.getByRole('button', { name: 'End Morning Lineup' }).click();
        await expect(command.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'ready');
        await Promise.all(stations.map(({ page }) => expect(page.locator('.vc-shell')).toHaveAttribute('data-phase', 'standing_by')));
    } finally {
        await Promise.all([command.context.close(), ...stations.map(({ context }) => context.close())]);
    }
});

test('300 can place and end a direct Station 1 call', async ({ browser, baseURL }) => {
    const station = await prepareStation(browser, baseURL!, 1);
    const command = await prepareCommand(browser, baseURL!);
    try {
        const stationOne = command.page.locator('.vc-ready-list > div').filter({ hasText: 'Station 1' });
        await stationOne.getByRole('button', { name: 'Direct call' }).click();
        await expect(command.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await expect(station.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await expect(command.page.locator('.vc-tile')).toHaveCount(2);
        await setSyntheticAudioLevel(command.page, 0.45);
        await expect(station.page.locator('.vc-focus-stage .vc-tile')).toHaveAttribute('aria-label', /300 video/);
        const stationOneControl = command.page.locator('.vc-command__stations > div').filter({ hasText: 'Station 1' });
        await stationOneControl.getByRole('button', { name: 'Give Floor' }).click();
        await setSyntheticAudioLevel(station.page, 0.9);
        await command.page.waitForTimeout(2_500);
        await setSyntheticAudioLevel(command.page, 0);
        await expect(command.page.locator('.vc-focus-stage .vc-tile')).toHaveAttribute('aria-label', /Station 1 video/);
        await command.page.getByRole('button', { name: 'End Direct Call' }).click();
        await expect(command.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'ready');
        await expect(station.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'standing_by');
    } finally {
        await Promise.all([command.context.close(), station.context.close()]);
    }
});
