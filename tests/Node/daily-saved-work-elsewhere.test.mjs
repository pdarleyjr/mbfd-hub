import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import { SAVED_WORK_MESSAGE, countSavedWorkElsewhere, otherPrimaryOrigin } from '../../resources/js/daily-checkout/src/pwa/savedWorkElsewhere.ts';

const root = resolve(import.meta.dirname, '..', '..');
const PRIMARY = 'https://www.mbfdhub.com';

function fakeDocument() {
  const listeners = new Set();
  const view = {
    addEventListener: (_type, listener) => listeners.add(listener),
    removeEventListener: (_type, listener) => listeners.delete(listener),
  };
  const frame = {
    contentWindow: { frame: true },
    removed: false,
    setAttribute() {},
    remove() { this.removed = true; },
  };
  return {
    defaultView: view,
    createElement: () => frame,
    body: { appendChild() {} },
    frame,
    listeners,
    post: (event) => [...listeners].forEach((listener) => listener(event)),
  };
}

test('only a page on the other host looks for saved work', () => {
  assert.equal(otherPrimaryOrigin({ __MBFD_PRIMARY_ORIGIN__: PRIMARY, location: { origin: 'https://mbfdhub.com' } }), PRIMARY);
  assert.equal(otherPrimaryOrigin({ __MBFD_PRIMARY_ORIGIN__: PRIMARY, location: { origin: PRIMARY } }), null);
  assert.equal(otherPrimaryOrigin({ location: { origin: 'https://mbfdhub.com' } }), null);
});

test('the count comes from the primary host frame, and the frame is removed afterwards', async () => {
  const doc = fakeDocument();
  const answer = countSavedWorkElsewhere(PRIMARY, doc, 5_000);
  assert.equal(doc.frame.src, `${PRIMARY}/daily-saved-work-probe`);
  assert.equal(doc.frame.hidden, true);
  doc.post({ origin: PRIMARY, source: doc.frame.contentWindow, data: { type: SAVED_WORK_MESSAGE, pending: 3 } });
  assert.equal(await answer, 3);
  assert.equal(doc.frame.removed, true);
  assert.equal(doc.listeners.size, 0);
});

test('a message from anywhere else is ignored', async () => {
  const doc = fakeDocument();
  const answer = countSavedWorkElsewhere(PRIMARY, doc, 20);
  doc.post({ origin: 'https://evil.example', source: doc.frame.contentWindow, data: { type: SAVED_WORK_MESSAGE, pending: 9 } });
  doc.post({ origin: PRIMARY, source: { other: true }, data: { type: SAVED_WORK_MESSAGE, pending: 9 } });
  doc.post({ origin: PRIMARY, source: doc.frame.contentWindow, data: { type: 'OTHER', pending: 9 } });
  assert.equal(await answer, 0, 'falls through to the timeout');
});

test('a frame that never answers, or answers nonsense, reads as nothing waiting', async () => {
  assert.equal(await countSavedWorkElsewhere(PRIMARY, fakeDocument(), 5), 0);
  for (const pending of ['x', -1, null, undefined]) {
    const doc = fakeDocument();
    const answer = countSavedWorkElsewhere(PRIMARY, doc, 5_000);
    doc.post({ origin: PRIMARY, source: doc.frame.contentWindow, data: { type: SAVED_WORK_MESSAGE, pending } });
    assert.equal(await answer, 0, String(pending));
  }
});

test('the probe is read-only: it cannot create, change or delete storage, and sends only a number', () => {
  const probe = readFileSync(resolve(root, 'resources/views/pwa/daily-saved-work-probe.blade.php'), 'utf8');
  assert.match(probe, /indexedDB\.databases\(\)/, 'looks the database up before opening, so it never creates one');
  assert.match(probe, /transaction\(names, 'readonly'\)/);
  assert.match(probe, /openKeyCursor/, 'keys only, so photos and signatures are never loaded');
  assert.match(probe, /postMessage\(\{ type: 'MBFD_DAILY_SAVED_WORK', pending: pending \}, parentOrigin\)/);
  assert.doesNotMatch(probe, /deleteDatabase|\.delete\(|\.put\(|\.add\(|\.clear\(|readwrite|unregister|localStorage/);
});
