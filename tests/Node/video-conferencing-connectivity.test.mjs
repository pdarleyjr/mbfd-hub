import assert from 'node:assert/strict';
import test from 'node:test';
import {
    ConferenceConnectivityError,
    verifyConferenceConnectivity,
} from '../../resources/js/video-conferencing/connectivity.ts';

test('conference connectivity probe succeeds only for a reachable healthy endpoint', async () => {
    let request;

    await verifyConferenceConnectivity('https://video.example.test/', 5000, async (url, init) => {
        request = { url, init };
        return new Response('OK', { status: 200 });
    });

    assert.equal(request.url, 'https://video.example.test/');
    assert.equal(request.init.cache, 'no-store');
    assert.equal(request.init.credentials, 'omit');
    assert.equal(request.init.mode, 'cors');
    assert.equal(request.init.method, 'GET');
    assert.ok(request.init.signal instanceof AbortSignal);
});

test('conference connectivity probe returns a stable error for an HTTP failure', async () => {
    await assert.rejects(
        verifyConferenceConnectivity(
            'https://video.example.test/',
            5000,
            async () => new Response('Unavailable', { status: 503 }),
        ),
        (error) => error instanceof ConferenceConnectivityError
            && error.code === 'conference_endpoint_unavailable',
    );
});

test('conference connectivity probe aborts a stalled private endpoint promptly', async () => {
    const started = Date.now();

    await assert.rejects(
        verifyConferenceConnectivity('https://100.81.154.123/', 20, async (_url, init) => {
            return new Promise((_resolve, reject) => {
                init.signal.addEventListener('abort', () => reject(init.signal.reason), { once: true });
            });
        }),
        (error) => error instanceof ConferenceConnectivityError
            && error.code === 'conference_network_unreachable',
    );

    assert.ok(Date.now() - started < 500, 'probe should fail well before the LiveKit signaling timeout');
});

test('conference connectivity probe rejects non-HTTPS production endpoints', async () => {
    await assert.rejects(
        verifyConferenceConnectivity('http://video.example.test/', 5000, async () => new Response('OK')),
        (error) => error instanceof ConferenceConnectivityError
            && error.code === 'conference_endpoint_misconfigured',
    );
});
