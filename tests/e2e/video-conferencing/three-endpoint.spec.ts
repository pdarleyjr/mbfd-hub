import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';

const employeeId = process.env.VIDEO_CONFERENCING_E2E_EMPLOYEE_ID;
const password = process.env.VIDEO_CONFERENCING_E2E_PASSWORD;
const commandPin = process.env.VIDEO_CONFERENCING_E2E_COMMAND_PIN;
const forceRelay = process.env.VIDEO_CONFERENCING_E2E_FORCE_RELAY === 'true';

test.skip(!employeeId || !password || !commandPin, 'Set explicit disposable conference E2E credentials and the 300 command PIN.');

type Endpoint = { context: BrowserContext; page: Page; sessionId?: string; errors: string[]; navigations: string[] };

async function endpointContext(browser: Browser, baseURL: string): Promise<Endpoint> {
    const context = await browser.newContext({
        baseURL,
        viewport: { width: 1280, height: 900 },
        permissions: ['camera', 'microphone'],
    });
    await context.addInitScript(() => {
        const nativePlay = HTMLMediaElement.prototype.play;
        Object.assign(window, { __mbfdAlerts: 0, __mbfdBlockAlert: false, __mbfdUnhandled: [] });
        window.addEventListener('unhandledrejection', () => (window as any).__mbfdUnhandled.push('unhandled rejection'));
        HTMLMediaElement.prototype.play = function () {
            if (this.src.includes('lineup-start')) {
                (window as any).__mbfdAlerts++;
                if ((window as any).__mbfdBlockAlert) return Promise.reject(new DOMException('Blocked', 'NotAllowedError'));
            }
            return nativePlay.call(this);
        };
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
        navigator.mediaDevices.getDisplayMedia = async () => nativeGetUserMedia({ video: true, audio: false });
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

    const endpoint: Endpoint = { context, page: await context.newPage(), errors: [], navigations: [] };
    endpoint.page.on('request', request => { if (request.isNavigationRequest()) endpoint.navigations.push(new URL(request.url()).pathname); });
    endpoint.page.on('pageerror', () => endpoint.errors.push('Uncaught JavaScript error'));
    endpoint.page.on('console', message => {
        if (/(?:access_token=|eyJ[a-zA-Z0-9_-]+\.eyJ)/.test(message.text())) endpoint.errors.push('Credential-bearing console output');
    });
    endpoint.page.on('response', async response => {
        if (/api\/(lineup|direct)\/start$/.test(response.url()) && response.ok()) {
            endpoint.sessionId = (await response.json()).session?.id;
        }
    });
    return endpoint;
}

async function signIn(page: Page) {
    await page.goto('/login');
    await page.getByLabel('Employee ID').fill(employeeId!);
    await page.getByLabel('Password', {exact:true}).fill(password!);
    await page.getByRole('button', {name:/sign in/i}).click();
    await expect(page).not.toHaveURL(/\/login$/);
}

async function cleanupTestConference(endpoint: Endpoint) {
    if (!endpoint.sessionId) return;
    await endpoint.page.evaluate(async sessionId => {
        const root = document.getElementById('video-conferencing-root');
        if (!root) return;
        const bootstrap = JSON.parse(root.dataset.bootstrap!);
        await fetch(bootstrap.endpoints.api_base + '/sessions/' + sessionId + '/end', {
            method: 'POST', credentials: 'same-origin',
            headers: {Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':bootstrap.csrf_token}, body:'{}',
        });
    }, endpoint.sessionId).catch(() => undefined);
}

async function prepareStation(browser: Browser, baseURL: string, station: 1 | 2 | 3 | 4 | 6, authenticated = false): Promise<Endpoint> {
    const endpoint = await endpointContext(browser, baseURL);
    if (authenticated) await signIn(endpoint.page);
    await endpoint.page.goto(`/video-conferencing/stations/${station}${forceRelay ? '?force_relay=1' : ''}`);
    await expect(endpoint.page.locator('.vc-shell')).toHaveAttribute('data-entry-mode', 'station');
    await expect(endpoint.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'standing_by');
    await expect(endpoint.page.getByText(`Station ${station}`, { exact: true }).first()).toBeVisible();
    await expect(endpoint.page.getByText('READY — STANDING BY', { exact: true })).toBeVisible();

    return endpoint;
}

async function prepareCommand(browser: Browser, baseURL: string): Promise<Endpoint> {
    const endpoint = await endpointContext(browser, baseURL);
    await endpoint.page.goto(`/employee/video-conferencing/command?return_to=%2Fdaily%2Fstations%2F2${forceRelay ? '&force_relay=1' : ''}`);
    if (endpoint.page.url().includes('/login')) {
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

async function selectedCandidatePairDiagnostics(page: Page): Promise<{
    valid: boolean;
    transports: Array<{
        connectionState: RTCPeerConnectionState;
        iceConnectionState: RTCIceConnectionState;
        policy: RTCIceTransportPolicy | undefined;
        selectedPairs: Array<{ localType?: string; remoteType?: string; state?: string }>;
    }>;
}> {
    return page.evaluate(async () => {
        const peerConnections = ((window as Window & { __mbfdPeerConnections?: RTCPeerConnection[] })
            .__mbfdPeerConnections ?? []).filter((peerConnection) => (
            peerConnection.iceConnectionState === 'connected'
                || peerConnection.iceConnectionState === 'completed'
        ));
        const transports = [];

        for (const peerConnection of peerConnections) {
            const stats = await peerConnection.getStats();
            const transportReports = [...stats.values()].filter((report) => report.type === 'transport');
            const selectedPairIds = transportReports
                .map((transport) => transport.selectedCandidatePairId as string | undefined)
                .filter((id): id is string => Boolean(id));
            const selectedPairs = selectedPairIds.length > 0
                ? selectedPairIds.map((id) => stats.get(id)).filter(Boolean)
                : [...stats.values()].filter((report) => report.type === 'candidate-pair'
                    && report.state === 'succeeded'
                    && (report.selected === true || report.nominated === true));
            transports.push({
                connectionState: peerConnection.connectionState,
                iceConnectionState: peerConnection.iceConnectionState,
                policy: peerConnection.getConfiguration().iceTransportPolicy,
                selectedPairs: selectedPairs.map((pair) => {
                const localCandidate = stats.get(pair.localCandidateId as string);
                    const remoteCandidate = stats.get(pair.remoteCandidateId as string);

                    return {
                        localType: localCandidate?.candidateType as string | undefined,
                        remoteType: remoteCandidate?.candidateType as string | undefined,
                        state: pair.state as string | undefined,
                    };
                }),
            });
        }

        return {
            valid: transports.length > 0 && transports.every((transport) => (
                transport.policy === 'relay'
                    && transport.selectedPairs.length > 0
                    && transport.selectedPairs.every((pair) => pair.localType === 'relay')
            )),
            transports,
        };
    });
}

async function expectRelayOnly(page: Page): Promise<void> {
    await expect.poll(async () => JSON.stringify(await selectedCandidatePairDiagnostics(page)))
        .toContain('"valid":true');
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

        await stations[0].page.evaluate(() => { (window as any).__mbfdBlockAlert = true; });
        await command.page.getByRole('button', { name: 'Start Morning Lineup' }).click();
        await expect(command.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await Promise.all(stations.map(({ page }) => expect(page.locator('.vc-shell')).toHaveAttribute(
            'data-phase',
            'connected',
            { timeout: 45_000 },
        )));
        await expect(command.page.locator('.vc-tile')).toHaveCount(6);
        for (const viewport of [{width:1920,height:1080}, {width:1366,height:768}, {width:1024,height:768}, {width:390,height:844}]) {
            await command.page.setViewportSize(viewport);
            await expect.poll(() => conferenceFitsWithoutPageScroll(command.page)).toBe(true);
            await expect(command.page.getByRole('button', {name:'End Conference', exact:true})).toBeInViewport();
            await expect(command.page.locator('.vc-gallery')).toHaveAttribute('aria-label', 'Participant gallery');
            const tiles = await command.page.locator('.vc-gallery .vc-tile').evaluateAll(elements => elements.map(el => {
                const bounds = el.getBoundingClientRect();
                const video = el.querySelector('video');
                return { width: bounds.width, height: bounds.height, fit: video && getComputedStyle(video).objectFit };
            }));
            expect(tiles.every(tile => tile.width > 130 && tile.height > 90 && tile.fit === 'contain')).toBe(true);
            await expect.poll(() => command.page.locator('.vc-gallery video').evaluateAll(videos => videos.every(video => (video as HTMLVideoElement).readyState >= 2 && (video as HTMLVideoElement).videoWidth > 0))).toBe(true);
            await command.page.screenshot({path: 'test-results/video-conferencing/gallery-' + viewport.width + '.png'});
        }
        await command.page.setViewportSize({width:1366,height:768});
        for (const station of stations) {
            await expect(station.page.locator('.vc-station-mic')).toContainText('MIC MUTED');
            expect(await station.page.evaluate(() => (window as any).__mbfdAlerts)).toBe(1);
        }
        await command.page.getByRole('button', {name:'Pin Station 2', exact:true}).click();
        await expect(command.page.locator('.vc-focus-bar')).toContainText('Pinned');
        await command.page.getByRole('button', {name:'Return to Gallery'}).click();
        await expect(command.page.locator('.vc-focus-bar')).toContainText('Gallery');
        await command.page.getByRole('button', {name:'300 Controls', exact:true}).click();
        await expect(command.page.getByRole('dialog', {name:'300 Controls'})).toBeVisible();
        const stationOne = command.page.locator('.vc-command__stations > div').filter({hasText:'Station 1'});
        await stationOne.getByRole('button', {name:'Give Floor'}).click();
        await expect(stations[0].page.locator('.vc-station-mic')).toContainText('MIC LIVE');
        await setSyntheticAudioLevel(stations[0].page, 0.8);
        await expect(command.page.locator('.vc-tile--speaking')).not.toHaveCount(0);
        await expect(command.page.locator('.vc-focus-bar')).toContainText('Gallery');
        await command.page.getByRole('button', {name:'Mute all stations'}).click();
        await expect(stations[0].page.locator('.vc-station-mic')).toContainText('MIC MUTED');
        await command.page.getByRole('button', {name:'Close 300 Controls'}).click();
        await command.page.getByRole('button', {name:'Share screen'}).click();
        await expect(command.page.locator('.vc-focus-bar')).toContainText('Screen share');
        await expect(stations[0].page.locator('.vc-focus-bar')).toContainText('Screen share');
        await command.page.getByRole('button', {name:'Stop sharing'}).click();
        await expect(command.page.locator('.vc-focus-bar')).toContainText('Gallery');
        await expect(stations[0].page.locator('.vc-focus-bar')).toContainText('Gallery');
        await command.page.getByRole('button', {name:'End Conference', exact:true}).click();
        await command.page.getByRole('dialog', {name:'End Morning Lineup?'}).getByRole('button', {name:'Cancel', exact:true}).click();
        await expect(command.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await stations[4].page.getByRole('button', {name:'Back', exact:true}).click();
        await expect.poll(() => stations[4].navigations).toContain('/daily/stations/6');
        await expect(command.page.locator('.vc-tile')).toHaveCount(5);
        if (forceRelay) {
            await expectRelayOnly(command.page);
            for (const station of stations.slice(0,4)) await expectRelayOnly(station.page);
        }
        for (const endpoint of [command, ...stations.slice(0,4)]) {
            expect(endpoint.errors).toEqual([]);
            expect(await endpoint.page.evaluate(() => (window as any).__mbfdUnhandled)).toEqual([]);
        }
        await command.page.getByRole('button', {name:'End Conference', exact:true}).click();
        await command.page.getByRole('dialog', {name:'End Morning Lineup?'}).getByRole('button', {name:'End Conference', exact:true}).click();
        await expect(command.page).toHaveURL(/\/daily\/stations\/2$/);
        for (const station of stations) await expect.poll(() => station.navigations).toContain('/daily/stations/' + station.station);
    } finally {
        await cleanupTestConference(command);
        // Close sequentially so Playwright finalizes each context's trace archive.
        for (const endpoint of [command, ...stations]) await endpoint.context.close();
    }
});

test('300 can place and end a direct Station 1 call', async ({ browser, baseURL }) => {
    const station = await prepareStation(browser, baseURL!, 1, true);
    const command = await prepareCommand(browser, baseURL!);
    try {
        const stationOne = command.page.locator('.vc-ready-list > div').filter({ hasText: 'Station 1' });
        await stationOne.getByRole('button', { name: 'Direct call' }).click();
        await expect(command.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await expect(station.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await expect(command.page.locator('.vc-tile')).toHaveCount(2);
        if (forceRelay) {
            await expectRelayOnly(command.page);
            await expectRelayOnly(station.page);
        }
        await command.page.getByRole('button', {name:'300 Controls', exact:true}).click();
        const stationOneControl = command.page.locator('.vc-command__stations > div').filter({hasText:'Station 1'});
        await stationOneControl.getByRole('button', {name:'Give Floor'}).click();
        await expect(station.page.locator('.vc-station-mic')).toContainText('MIC LIVE');
        await command.page.getByRole('button', {name:'Close 300 Controls'}).click();
        expect(command.errors).toEqual([]);
        expect(station.errors).toEqual([]);
        await command.page.getByRole('button', {name:'End Direct Call', exact:true}).click();
        await command.page.getByRole('dialog', {name:'End Direct Call?'}).getByRole('button', {name:'End Direct Call', exact:true}).click();
        await expect(command.page).toHaveURL(/\/daily\/stations\/2$/);
        await expect(station.page).toHaveURL(/\/daily\/stations\/1$/);
    } finally {
        await cleanupTestConference(command);
        await command.context.close();
        await station.context.close();
    }
});

test('self join keeps the exact session for status fallback and returns safely', async ({browser, baseURL}) => {
    const command = await prepareCommand(browser, baseURL!);
    const self = await endpointContext(browser, baseURL!);
    try {
        await command.page.getByRole('button', {name:'Start Morning Lineup'}).click();
        await expect(command.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await signIn(self.page);
        await self.page.goto('/employee/video-conferencing?return_to=%2Femployee');
        await expect(self.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'ready');
        await self.page.getByRole('button', {name:'Join active Morning Lineup'}).click();
        await expect(self.page.locator('.vc-shell')).toHaveAttribute('data-phase', 'connected');
        await expect(command.page.locator('.vc-tile')).toHaveCount(2);
        // Simulate a missed LiveKit closure reason: server status remains the
        // fallback authority even while the transport itself has not notified us.
        await self.page.route('**/api/sessions/*/status', route => route.fulfill({json:{active:false}}));
        await expect(self.page).toHaveURL(/\/employee\/dashboard$/);
        expect(self.navigations).toContain('/employee');
        expect(self.errors).toEqual([]);
        await command.page.getByRole('button', {name:'End Conference', exact:true}).click();
        await command.page.getByRole('dialog', {name:'End Morning Lineup?'}).getByRole('button', {name:'End Conference', exact:true}).click();
        await expect(command.page).toHaveURL(/\/daily\/stations\/2$/);
    } finally {
        await cleanupTestConference(command);
        await command.context.close();
        await self.context.close();
    }
});
