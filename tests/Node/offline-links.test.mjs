import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { OFFLINE_LINK_MESSAGE, isBlockedOfflineLink, isSamePageAnchor } from '../../resources/js/pwa/offline-links.ts';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const origin = 'https://hub.test';

const tap = (overrides = {}) => ({ defaultPrevented: false, button: 0, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, ...overrides });
const link = (href, attributes = {}) => ({
  href,
  getAttribute: name => (name in attributes ? attributes[name] : null),
  hasAttribute: name => name in attributes,
});

test('an offline tap on a same-origin Hub link is held', () => {
  for (const href of [`${origin}/admin/stations`, `${origin}/account`, `${origin}/take-home-vehicle-inspections/new?x=1`, `${origin}/`]) {
    assert.equal(isBlockedOfflineLink(tap(), link(href), origin, true), true, href);
  }
  assert.equal(isBlockedOfflineLink(tap(), link(`${origin}/x`, { target: '_self' }), origin, true), true);
});

test('a link is never blocked while the browser says it is online', () => {
  assert.equal(isBlockedOfflineLink(tap(), link(`${origin}/admin`), origin, false), false);
});

test('Daily keeps its own offline shell', () => {
  for (const path of ['/daily', '/daily/', '/daily/stations', '/daily/forms-hub/station-request?x=1']) {
    assert.equal(isBlockedOfflineLink(tap(), link(origin + path), origin, true), false, path);
  }
  // Only the Daily prefix is exempt: a path that merely starts with the same letters is a Blade page.
  assert.equal(isBlockedOfflineLink(tap(), link(`${origin}/daily-roster`), origin, true), true);
});

test('modified, already handled and non-primary clicks are left alone', () => {
  const a = link(`${origin}/admin`);
  for (const event of [{ defaultPrevented: true }, { button: 1 }, { button: 2 }, { metaKey: true }, { ctrlKey: true }, { shiftKey: true }, { altKey: true }]) {
    assert.equal(isBlockedOfflineLink(tap(event), a, origin, true), false, JSON.stringify(event));
  }
});

test('downloads, viewer links, other targets, other origins and non-web schemes are left alone', () => {
  assert.equal(isBlockedOfflineLink(tap(), link(`${origin}/f.pdf`, { download: '' }), origin, true), false);
  assert.equal(isBlockedOfflineLink(tap(), link(`${origin}/f.pdf`, { 'data-hub-viewer': '' }), origin, true), false);
  assert.equal(isBlockedOfflineLink(tap(), link(`${origin}/x`, { target: '_blank' }), origin, true), false);
  assert.equal(isBlockedOfflineLink(tap(), link(`${origin}/x`, { target: '_top' }), origin, true), false);
  assert.equal(isBlockedOfflineLink(tap(), link('https://bid.mbfdhub.com/api/auth/start'), origin, true), false);
  assert.equal(isBlockedOfflineLink(tap(), link('mailto:a@b.test'), origin, true), false);
  assert.equal(isBlockedOfflineLink(tap(), link('tel:+13055550100'), origin, true), false);
  assert.equal(isBlockedOfflineLink(tap(), link('not a url'), origin, true), false);
  assert.equal(isBlockedOfflineLink(tap(), null, origin, true), false);
});

test('a jump to an anchor on the current page needs no network', () => {
  const here = { pathname: '/support/issues', search: '?page=2' };
  assert.equal(isSamePageAnchor(`${origin}/support/issues?page=2#top`, here), true);
  assert.equal(isSamePageAnchor(`${origin}/support/issues?page=3#top`, here), false);
  assert.equal(isSamePageAnchor(`${origin}/support/issues?page=2`, here), false);
  assert.equal(isSamePageAnchor(`${origin}/account#top`, here), false);
});

test('the message is plain and has no em dash', () => {
  assert.equal(OFFLINE_LINK_MESSAGE, "You're offline. This page needs a connection.");
  assert.ok(!OFFLINE_LINK_MESSAGE.includes('—'));
});

test('hub-shell.js starts the guard once and it stores nothing', () => {
  const shell = readFileSync(resolve(root, 'resources/js/hub-shell.js'), 'utf8');
  assert.equal(shell.match(/from '\.\/pwa\/offline-links'/g)?.length, 1);
  assert.equal(shell.match(/startOfflineLinks\(\)/g)?.length, 1);
  const source = readFileSync(resolve(root, 'resources/js/pwa/offline-links.ts'), 'utf8');
  assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB|caches\.|serviceWorker|unregister/);
});
