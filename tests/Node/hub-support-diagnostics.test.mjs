import assert from 'node:assert/strict';
import test from 'node:test';

import {
    DiagnosticRingBuffer,
    installHubIssueDiagnosticsCollector,
    normalizeSameOriginPath,
    sanitizeDiagnosticValue,
} from '../../resources/js/hub-support/diagnostics.js';

function diagnosticTarget() {
    const listeners = new Map();
    let tick = 0;
    class FakeXmlHttpRequest {
        constructor() {
            this.listeners = new Map();
            this.status = 500;
        }

        open() {}

        send() {
            for (const listener of this.listeners.get('loadend') || []) listener();
        }

        addEventListener(name, listener) {
            this.listeners.set(name, [...(this.listeners.get(name) || []), listener]);
        }
    }

    return {
        location: { origin: 'https://www.mbfdhub.com' },
        performance: { now: () => ++tick },
        XMLHttpRequest: FakeXmlHttpRequest,
        fetch: async (url) => ({ ok: !String(url).includes('fail'), status: String(url).includes('fail') ? 500 : 200 }),
        addEventListener(name, listener) {
            listeners.set(name, [...(listeners.get(name) || []), listener]);
        },
        dispatch(name, event) {
            for (const listener of listeners.get(name) || []) listener(event);
        },
    };
}

test('ring buffer drops oldest events and enforces the serialized payload limit', () => {
    const buffer = new DiagnosticRingBuffer({ maxEvents: 3, maxBytes: 1024 });

    buffer.push({ type: 'error', message: 'first' });
    buffer.push({ type: 'error', message: 'second' });
    buffer.push({ type: 'error', message: 'third' });
    buffer.push({ type: 'error', message: 'fourth' });

    assert.deepEqual(buffer.snapshot().events.map((event) => event.message), ['second', 'third', 'fourth']);
    assert.ok(JSON.stringify(buffer.snapshot()).length <= 1024);
});

test('client sanitizer strips secrets, bodies, query strings, fragments, and unnecessary emails', () => {
    const jwt = ['eyJhbGciOiJIUzI1NiJ9', 'eyJzdWIiOiIxMjM0NTY3ODkwIn0', 'synthetic-signature'].join('.');
    const result = sanitizeDiagnosticValue({
        path: 'https://www.mbfdhub.com/forms/123?token=secret#section',
        message: `Authorization: Bearer top-secret password=hunter2 ${jwt} member@example.test`,
        requestBody: 'private form contents',
        responseBody: 'private server response',
        cookie: 'session=private',
    }, 'https://www.mbfdhub.com');
    const encoded = JSON.stringify(result);

    assert.equal(result.path, '/forms/123');
    for (const secret of ['top-secret', 'hunter2', jwt, 'member@example.test', 'private form contents', 'private server response', 'session=private']) {
        assert.equal(encoded.includes(secret), false);
    }
});

test('same-origin path normalization rejects cross-origin traffic', () => {
    assert.equal(normalizeSameOriginPath('/employee/forms?x=1#two', 'https://www.mbfdhub.com'), '/employee/forms');
    assert.equal(normalizeSameOriginPath('https://www.mbfdhub.com/api/problem?token=x', 'https://www.mbfdhub.com'), '/api/problem');
    assert.equal(normalizeSameOriginPath('https://third-party.example/api', 'https://www.mbfdhub.com'), null);
});

test('client sanitizer removes relative URL query values, fragments, and header credentials', () => {
    const result = sanitizeDiagnosticValue({
        type: 'error',
        message: 'GET /employee/search?term=John+Smith&station=1 failed Authorization: Basic dXNlcjpwYXNz',
        stack: 'at request (/api/items?name=PrivateValue#result:1:1) Cookie: session=abc123; other=xyz',
    }, 'https://www.mbfdhub.com');
    const encoded = JSON.stringify(result);

    for (const secret of ['John+Smith', 'station=1', 'PrivateValue', '#result', 'dXNlcjpwYXNz', 'abc123', 'other=xyz']) {
        assert.equal(encoded.includes(secret), false);
    }
    assert.match(result.message, /\/employee\/search/);
    assert.match(result.stack, /\/api\/items/);
});

test('ring buffer enforces its payload cap in serialized UTF-8 bytes', () => {
    const buffer = new DiagnosticRingBuffer({ maxEvents: 25, maxBytes: 65_536 });
    for (let index = 0; index < 25; index++) {
        buffer.push({ type: 'error', stack: '🔥'.repeat(2_048) });
    }

    assert.ok(new TextEncoder().encode(JSON.stringify(buffer.snapshot())).byteLength <= 65_536);
});

test('collector captures browser failures without retaining successful or cross-origin requests', async () => {
    const target = diagnosticTarget();
    const collector = installHubIssueDiagnosticsCollector(target);

    target.dispatch('error', { message: 'Window failure', error: { name: 'TypeError', message: 'Window failure', stack: 'at /app.js' } });
    target.dispatch('unhandledrejection', { reason: { name: 'Error', message: 'Promise failure', stack: 'at /promise.js' } });
    await target.fetch('/api/success');
    await target.fetch('/api/fail', { method: 'POST' });
    await target.fetch('https://third-party.example/fail');
    const xhr = new target.XMLHttpRequest();
    xhr.open('PATCH', '/api/xhr-fail');
    xhr.send();

    const events = collector.snapshot().events;
    assert.deepEqual(events.map((event) => event.type), ['error', 'rejection', 'request', 'request']);
    assert.deepEqual(events.filter((event) => event.type === 'request').map((event) => [event.method, event.path, event.status]), [
        ['POST', '/api/fail', 500],
        ['PATCH', '/api/xhr-fail', 500],
    ]);
});

test('collector installation failure leaves the host and the returned buffer usable', () => {
    const collector = installHubIssueDiagnosticsCollector({
        addEventListener() { throw new Error('instrumentation unavailable'); },
    });

    collector.push({ type: 'error', message: 'still usable' });
    assert.deepEqual(collector.snapshot().events.map((event) => event.message), ['still usable']);
});
