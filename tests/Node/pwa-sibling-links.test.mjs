import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import { isInstalledWindow, ownHostHref, siblingHostOf, startSiblingLinks } from '../../resources/js/pwa/sibling-links.ts';

const root = resolve(import.meta.dirname, '..', '..');
const apex = { protocol: 'https:', hostname: 'mbfdhub.com', port: '', origin: 'https://mbfdhub.com' };
const www = { protocol: 'https:', hostname: 'www.mbfdhub.com', port: '', origin: 'https://www.mbfdhub.com' };

test('the sibling of www is the apex and the reverse; addresses and bare names have none', () => {
  assert.equal(siblingHostOf('www.mbfdhub.com'), 'mbfdhub.com');
  assert.equal(siblingHostOf('mbfdhub.com'), 'www.mbfdhub.com');
  assert.equal(siblingHostOf('localhost'), null);
  assert.equal(siblingHostOf('127.0.0.1'), null);
});

test('a link on the other Hub host is followed on this one, with path, query and fragment intact', () => {
  assert.equal(ownHostHref('https://www.mbfdhub.com/admin/x?a=1#b', apex), 'https://mbfdhub.com/admin/x?a=1#b');
  assert.equal(ownHostHref('https://mbfdhub.com/daily/stations', www), 'https://www.mbfdhub.com/daily/stations');
});

test('same-host links, other applications and malformed links are left alone', () => {
  for (const href of ['https://mbfdhub.com/x', '/x', 'https://cmd.mbfdhub.com/', 'https://bid.mbfdhub.com/api/auth/start', 'https://example.com/www.mbfdhub.com', 'mailto:a@b.c', 'http://www.mbfdhub.com/insecure']) {
    assert.equal(ownHostHref(href, apex), null, href);
  }
  assert.equal(ownHostHref('http://[bad', apex), null);
});

function fakeWindow({ standalone = false, media = [] } = {}) {
  const listeners = {};
  const assigned = [];
  return {
    assigned,
    listeners,
    navigator: { standalone },
    matchMedia: (query) => ({ matches: media.includes(query) }),
    location: { ...apex, assign: (url) => assigned.push(url) },
    document: { addEventListener: (type, handler) => { listeners[type] = handler; } },
  };
}

const click = (href, overrides = {}) => {
  let prevented = false;
  return {
    get wasPrevented() { return prevented; },
    defaultPrevented: false,
    button: 0,
    preventDefault() { prevented = true; },
    target: { closest: () => ({ href, hasAttribute: () => false }) },
    ...overrides,
  };
};

test('installed means every mode the install controller treats as installed, and only those', () => {
  assert.equal(isInstalledWindow(fakeWindow()), false);
  assert.equal(isInstalledWindow(fakeWindow({ standalone: true })), true);
  for (const mode of ['standalone', 'window-controls-overlay', 'minimal-ui']) {
    assert.equal(isInstalledWindow(fakeWindow({ media: [`(display-mode: ${mode})`] })), true, mode);
  }
  assert.equal(isInstalledWindow(fakeWindow({ media: ['(display-mode: fullscreen)'] })), false);
});

test('a browser tab is never touched', () => {
  const view = fakeWindow();
  startSiblingLinks(view);
  assert.equal(view.listeners.click, undefined);
});

test('an installed window follows a sibling link on its own host, once per click', () => {
  const view = fakeWindow({ standalone: true });
  startSiblingLinks(view);
  startSiblingLinks(view);
  const event = click('https://www.mbfdhub.com/employee');
  view.listeners.click(event);
  assert.equal(event.wasPrevented, true);
  assert.deepEqual(view.assigned, ['https://mbfdhub.com/employee']);
});

test('modified clicks, handled clicks, downloads and other applications pass through', () => {
  const view = fakeWindow({ standalone: true });
  startSiblingLinks(view);
  const passes = [
    click('https://www.mbfdhub.com/x', { metaKey: true }),
    click('https://www.mbfdhub.com/x', { button: 1 }),
    click('https://www.mbfdhub.com/x', { defaultPrevented: true }),
    click('https://www.mbfdhub.com/x', { target: { closest: () => ({ href: 'https://www.mbfdhub.com/x', hasAttribute: (name) => name === 'download' }) } }),
    click('https://www.mbfdhub.com/daily/stations', { target: { closest: () => ({ href: 'https://www.mbfdhub.com/daily/stations', hasAttribute: (name) => name === 'data-hub-keep-host' }) } }),
    click('https://www.mbfdhub.com/x', { target: { closest: () => null } }),
    click('https://cmd.mbfdhub.com/'),
  ];
  for (const event of passes) {
    view.listeners.click(event);
    assert.equal(event.wasPrevented, false);
  }
  assert.deepEqual(view.assigned, []);
});

test('every page shell sets the installed-host cookie the same way, host-only, and never on www', () => {
  for (const file of ['resources/views/components/hub/head.blade.php', 'resources/js/daily-checkout/index.html']) {
    const source = readFileSync(resolve(root, file), 'utf8');
    assert.match(source, /location\.hostname\.indexOf\('www\.'\) === 0\) return/, file);
    assert.match(source, /'mbfd_installed_host=1; Path=\/; Max-Age=\d+; SameSite=Lax; Secure'/, file);
    assert.doesNotMatch(source, /mbfd_installed_host=1;[^']*Domain/i, `${file}: a Domain attribute would reach www`);
  }
});

test('installing never changes: the frozen manifest identity is untouched', () => {
  const manifest = JSON.parse(readFileSync(resolve(root, 'public/manifest.json'), 'utf8'));
  assert.equal(manifest.id, '/');
  assert.equal(manifest.start_url, '/');
  assert.equal(manifest.scope, '/');
  assert.equal(manifest.scope_extensions, undefined);
});
