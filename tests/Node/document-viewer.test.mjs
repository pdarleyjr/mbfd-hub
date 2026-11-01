import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

/**
 * The document viewer opens PDFs and images in a dialog on the same page, so an installed app
 * never leaves for the browser. There is no DOM library in this repo, so the tests drive the
 * real module against a small fake DOM that implements only what the viewer touches.
 */

const root = resolve(import.meta.dirname, '..', '..');

class FakeElement {
  constructor(tagName) {
    this.tagName = tagName.toUpperCase();
    this.className = '';
    this.children = [];
    this.parent = null;
    this.attrs = new Map();
    this.listeners = new Map();
    this.dataset = {};
    this._text = '';
    this.open = false;
  }
  get textContent() { return this._text + this.children.map((child) => child.textContent).join(''); }
  set textContent(value) { this.children = []; this._text = String(value); }
  get href() { return this.attrs.get('href') ?? ''; }
  set href(value) { this.attrs.set('href', value); }
  get isConnected() {
    for (let node = this; node; node = node.parent) if (node === currentDocument.body) return true;
    return false;
  }
  setAttribute(name, value) { this.attrs.set(name, String(value)); }
  getAttribute(name) { return this.attrs.has(name) ? this.attrs.get(name) : null; }
  hasAttribute(name) { return this.attrs.has(name); }
  append(...nodes) { for (const node of nodes) { node.parent = this; this.children.push(node); } }
  replaceChildren(...nodes) { this.children = []; this._text = ''; this.append(...nodes); }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter((child) => child !== this); this.parent = null; }
  addEventListener(type, listener, options) {
    const list = this.listeners.get(type) ?? [];
    list.push({ listener, once: Boolean(options?.once) });
    this.listeners.set(type, list);
  }
  removeEventListener(type, listener) {
    this.listeners.set(type, (this.listeners.get(type) ?? []).filter((entry) => entry.listener !== listener));
  }
  fire(event) {
    for (const entry of [...(this.listeners.get(event.type) ?? [])]) {
      if (entry.once) this.removeEventListener(event.type, entry.listener);
      entry.listener(event);
    }
  }
  closest(selector) {
    assert.equal(selector, 'a[data-hub-viewer]', 'the fake only implements the one selector the viewer uses');
    for (let node = this; node; node = node.parent) if (node.tagName === 'A' && node.attrs.has('data-hub-viewer')) return node;
    return null;
  }
  focus() { currentDocument.activeElement = this; }
  getBoundingClientRect() { return { left: 100, top: 100, right: 900, bottom: 700 }; }
  showModal() { this.open = true; }
  close() {
    if (!this.open) return;
    this.open = false;
    this.fire({ type: 'close', target: this });
  }
  findAll(predicate, found = []) {
    for (const child of this.children) { if (predicate(child)) found.push(child); child.findAll(predicate, found); }
    return found;
  }
  byClass(name) { return this.findAll((node) => node.className.split(' ').includes(name)); }
}

let currentDocument;

function makeDocument() {
  const document = new FakeElement('document');
  document.body = new FakeElement('body');
  document.activeElement = null;
  document.createElement = (tag) => new FakeElement(tag);
  return document;
}

/** Dispatch a click the way a browser does: target first, then each ancestor, then the document. */
function click(target, init = {}) {
  const event = {
    type: 'click', target, button: 0, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false,
    clientX: 500, clientY: 400, defaultPrevented: false, ...init,
    preventDefault() { this.defaultPrevented = true; },
  };
  for (let node = target; node; node = node.parent) node.fire(event);
  currentDocument.fire(event);
  return event;
}

function makeLink(attrs, text = 'Open it') {
  const link = new FakeElement('a');
  for (const [name, value] of Object.entries(attrs)) {
    link.setAttribute(name, value);
    if (name.startsWith('data-')) {
      link.dataset[name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] = value;
    }
  }
  link.textContent = text;
  currentDocument.body.append(link);
  return link;
}

const settle = () => new Promise((resolveDone) => setTimeout(resolveDone, 0));

function response(body, { status = 200, type = 'application/octet-stream' } = {}) {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: { get: (name) => (name.toLowerCase() === 'content-type' ? type : null) },
    blob: async () => new Blob([body], { type }),
    arrayBuffer: async () => new TextEncoder().encode(body).buffer,
  };
}

const revoked = [];
const realCreate = URL.createObjectURL;
const realRevoke = URL.revokeObjectURL;
let fetchCalls;
let stopViewer;

async function setup({ fetchImpl, renderer } = {}) {
  currentDocument = makeDocument();
  globalThis.document = currentDocument;
  globalThis.window = new EventTarget();
  globalThis.location = { href: 'https://www.mbfdhub.com/employee/files', origin: 'https://www.mbfdhub.com' };
  fetchCalls = [];
  globalThis.fetch = async (url, init) => {
    fetchCalls.push({ url, init });
    return fetchImpl ? fetchImpl(url, init) : response('bytes', { type: 'image/png' });
  };
  revoked.length = 0;
  URL.createObjectURL = (blob) => realCreate.call(URL, blob);
  URL.revokeObjectURL = (url) => { revoked.push(url); realRevoke.call(URL, url); };

  const { startDocumentViewer } = await import('../../resources/js/pwa/document-viewer.ts');
  stopViewer = startDocumentViewer(renderer ? { loadPdfRenderer: async () => renderer } : {});
  return startDocumentViewer;
}

function teardown() {
  stopViewer?.();
  URL.createObjectURL = realCreate;
  URL.revokeObjectURL = realRevoke;
}

const dialogOf = () => currentDocument.body.findAll((node) => node.tagName === 'DIALOG')[0];

test('startDocumentViewer is an idempotent singleton: one listener, one dialog per click', async (t) => {
  const start = await setup();
  t.after(teardown);

  assert.equal(start(), stopViewer, 'a second start returns the running instance');
  assert.equal(currentDocument.listeners.get('click').length, 1);

  click(makeLink({ href: '/files/a.png', 'data-hub-viewer': 'image' }));
  assert.equal(currentDocument.body.findAll((node) => node.tagName === 'DIALOG').length, 1);
});

test('an image link opens a titled modal dialog, loads the file with the session, and cleans up on Close', async (t) => {
  await setup();
  t.after(teardown);
  const { busyReasons } = await import('../../resources/js/pwa/busy.ts');

  const link = makeLink({ href: '/files/a.png', 'data-hub-viewer': 'image', 'data-hub-viewer-title': 'Left side photo' }, 'ignored text');
  const event = click(link);
  assert.equal(event.defaultPrevented, true, 'the link does not navigate');

  const dialog = dialogOf();
  assert.equal(dialog.open, true);
  assert.equal(dialog.className, 'hub-viewer');
  assert.equal(dialog.byClass('hub-viewer__title')[0].textContent, 'Left side photo');
  assert.equal(dialog.getAttribute('aria-labelledby'), dialog.byClass('hub-viewer__title')[0].id);
  assert.deepEqual(busyReasons(), ['document-viewer'], 'an automatic reload must not close the viewer');
  assert.equal(currentDocument.activeElement, dialog.byClass('hub-viewer__close')[0], 'focus moves into the dialog');

  await settle();
  assert.equal(fetchCalls.length, 1);
  assert.equal(fetchCalls[0].url, 'https://www.mbfdhub.com/files/a.png');
  assert.equal(fetchCalls[0].init.credentials, 'same-origin');
  const image = dialog.findAll((node) => node.tagName === 'IMG')[0];
  assert.equal(image.alt, 'Left side photo');
  assert.match(image.src, /^blob:/);

  const close = dialog.byClass('hub-viewer__close')[0];
  assert.equal(close.getAttribute('aria-label'), 'Close');
  click(close);

  assert.equal(dialog.open, false);
  assert.equal(dialog.parent, null, 'the dialog is removed from the page');
  assert.deepEqual(revoked, [image.src], 'the blob is revoked');
  assert.equal(currentDocument.activeElement, link, 'focus returns to the link');
  assert.deepEqual(busyReasons(), []);
});

test('the title falls back to the link text', async (t) => {
  await setup();
  t.after(teardown);

  click(makeLink({ href: '/files/a.png', 'data-hub-viewer': 'image' }, '  Front   photo '));
  assert.equal(dialogOf().byClass('hub-viewer__title')[0].textContent, 'Front photo');
});

test('clicks the viewer must not handle fall through to the normal link', async (t) => {
  await setup();
  t.after(teardown);

  const image = { href: '/files/a.png', 'data-hub-viewer': 'image' };
  const cases = {
    'ctrl click': [makeLink(image), { ctrlKey: true }],
    'meta click': [makeLink(image), { metaKey: true }],
    'shift click': [makeLink(image), { shiftKey: true }],
    'alt click': [makeLink(image), { altKey: true }],
    'middle button': [makeLink(image), { button: 1 }],
    'already handled': [makeLink(image), { defaultPrevented: true }],
    'another origin': [makeLink({ href: 'https://example.com/a.png', 'data-hub-viewer': 'image' }), {}],
    'the sibling host': [makeLink({ href: 'https://mbfdhub.com/a.png', 'data-hub-viewer': 'image' }), {}],
    'mailto': [makeLink({ href: 'mailto:a@example.com', 'data-hub-viewer': 'image' }), {}],
    'javascript url': [makeLink({ href: 'javascript:void(0)', 'data-hub-viewer': 'image' }), {}],
    'a download link': [makeLink({ ...image, download: '' }), {}],
    'a link without the attribute': [makeLink({ href: '/files/a.png' }), {}],
  };

  for (const [name, [link, init]] of Object.entries(cases)) {
    const event = click(link, init);
    assert.equal(dialogOf(), undefined, `${name}: no dialog`);
    if (!init.defaultPrevented) assert.equal(event.defaultPrevented, false, `${name}: the browser keeps the click`);
  }
  assert.equal(fetchCalls.length, 0);
});

test('a click inside the link (a child element) still opens the viewer', async (t) => {
  await setup();
  t.after(teardown);

  const link = makeLink({ href: '/files/a.png', 'data-hub-viewer': 'image' });
  const icon = new FakeElement('span');
  link.append(icon);
  click(icon);
  assert.equal(dialogOf().open, true);
});

test('a PDF renders through the lazy renderer; the #page fragment is scrolled to, never fetched', async (t) => {
  const renders = [];
  const renderer = {
    async renderPdf(request) {
      renders.push(request);
      const entry = request;
      return { destroy() { entry.destroyed = true; } };
    },
  };
  await setup({ fetchImpl: () => response('%PDF-1.7', { type: 'application/pdf' }), renderer });
  t.after(teardown);

  const link = makeLink({ href: '/training/paper.pdf#page=7', 'data-hub-viewer': 'pdf', 'data-hub-viewer-title': 'Paper' });
  click(link);
  await settle();

  assert.equal(fetchCalls[0].url, 'https://www.mbfdhub.com/training/paper.pdf');
  assert.equal(renders.length, 1);
  assert.equal(renders[0].page, 7);
  assert.equal(new TextDecoder().decode(renders[0].data), '%PDF-1.7');
  assert.ok(renders[0].container.className.includes('hub-viewer__pages'));
  assert.equal(dialogOf().findAll((node) => node.getAttribute('aria-busy') === 'false').length, 1);

  click(dialogOf().byClass('hub-viewer__close')[0]);
  assert.equal(renders[0].destroyed, true, 'closing destroys the renderer');
  assert.equal(renders[0].signal.aborted, true, 'closing cancels in-flight work');
});

test('auto decides from the extension, else from the response content type', async (t) => {
  const kinds = [];
  const renderer = { async renderPdf() { kinds.push('pdf'); return { destroy() {} }; } };
  let type = 'application/pdf';
  await setup({ fetchImpl: () => response('x', { type }), renderer });
  t.after(teardown);

  click(makeLink({ href: '/library/guide', 'data-hub-viewer': 'auto' }));
  await settle();
  assert.deepEqual(kinds, ['pdf'], 'no extension: the content type says PDF');
  click(dialogOf().byClass('hub-viewer__close')[0]);

  type = 'image/jpeg';
  click(makeLink({ href: '/library/photo', 'data-hub-viewer': 'auto' }));
  await settle();
  assert.equal(dialogOf().findAll((node) => node.tagName === 'IMG').length, 1, 'no extension: the content type says image');
  click(dialogOf().byClass('hub-viewer__close')[0]);

  type = 'application/pdf';
  click(makeLink({ href: '/library/picture.JPG', 'data-hub-viewer': 'auto' }));
  await settle();
  assert.equal(dialogOf().findAll((node) => node.tagName === 'IMG').length, 1, 'the extension wins over the content type');
});

test('an unsupported type says so and offers the file in the same window', async (t) => {
  await setup({ fetchImpl: () => response('x', { type: 'application/zip' }) });
  t.after(teardown);

  click(makeLink({ href: '/library/archive', 'data-hub-viewer': 'auto' }));
  await settle();

  const dialog = dialogOf();
  assert.match(dialog.byClass('hub-viewer__status')[0].textContent, /cannot be shown here/);
  const open = dialog.byClass('hub-viewer__open')[0];
  assert.equal(open.textContent, 'Open file');
  assert.equal(open.href, 'https://www.mbfdhub.com/library/archive');
  assert.equal(open.hasAttribute('target'), false, 'the fallback never opens a new tab');
});

test('a PDF with no renderer on this page degrades to a message and an Open file link', async (t) => {
  await setup({ fetchImpl: () => response('%PDF', { type: 'application/pdf' }) });
  t.after(teardown);

  click(makeLink({ href: '/files/a.pdf', 'data-hub-viewer': 'pdf' }));
  await settle();

  assert.match(dialogOf().byClass('hub-viewer__status--error')[0].textContent, /separate file/);
  assert.equal(dialogOf().byClass('hub-viewer__open')[0].href, 'https://www.mbfdhub.com/files/a.pdf');
});

test('a renderer that fails to draw explains it and offers the file', async (t) => {
  const failing = { async renderPdf() { throw new Error('bad pdf'); } };
  await setup({ fetchImpl: () => response('%PDF', { type: 'application/pdf' }), renderer: failing });
  t.after(teardown);

  click(makeLink({ href: '/files/a.pdf', 'data-hub-viewer': 'pdf' }));
  await settle();
  assert.equal(dialogOf().byClass('hub-viewer__status--error')[0].textContent, 'This PDF could not be displayed.');
  assert.equal(dialogOf().byClass('hub-viewer__open').length, 1);
});

test('403, 404 and a network failure each get a clear message and an Open file link', async (t) => {
  let behavior = () => response('', { status: 403 });
  await setup({ fetchImpl: () => behavior() });
  t.after(teardown);

  const expectations = [
    [() => response('', { status: 403 }), /do not have access/],
    [() => response('', { status: 404 }), /no longer available/],
    [() => { throw new TypeError('Failed to fetch'); }, /Check your connection/],
  ];
  for (const [impl, message] of expectations) {
    behavior = impl;
    click(makeLink({ href: '/files/a.png', 'data-hub-viewer': 'image' }));
    await settle();
    const dialog = dialogOf();
    assert.match(dialog.byClass('hub-viewer__status--error')[0].textContent, message);
    assert.equal(dialog.byClass('hub-viewer__status--error')[0].getAttribute('role'), 'alert');
    assert.equal(dialog.byClass('hub-viewer__open')[0].href, 'https://www.mbfdhub.com/files/a.png');
    click(dialog.byClass('hub-viewer__close')[0]);
  }
});

test('the backdrop closes the viewer; a click inside the panel does not', async (t) => {
  await setup();
  t.after(teardown);

  click(makeLink({ href: '/files/a.png', 'data-hub-viewer': 'image' }));
  await settle();
  const dialog = dialogOf();

  click(dialog, { clientX: 500, clientY: 400 });
  assert.equal(dialog.open, true, 'inside the panel box');
  click(dialog.byClass('hub-viewer__title')[0]);
  assert.equal(dialog.open, true, 'a click on content is not the backdrop');

  click(dialog, { clientX: 20, clientY: 20 });
  assert.equal(dialog.open, false, 'outside the panel box');
});

test('Escape closes natively: the dialog close event cancels fetches and releases the page', async (t) => {
  await setup({
    fetchImpl: (url, init) => new Promise((_, reject) => {
      init.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    }),
  });
  t.after(teardown);
  const { busyReasons } = await import('../../resources/js/pwa/busy.ts');

  const link = makeLink({ href: '/files/slow.png', 'data-hub-viewer': 'image' });
  click(link);
  const dialog = dialogOf();
  const signal = fetchCalls[0].init.signal;
  assert.equal(signal.aborted, false);

  dialog.close(); // what the browser does on Escape or the Android back gesture
  await settle();

  assert.equal(signal.aborted, true);
  assert.equal(dialog.parent, null);
  assert.equal(dialog.byClass('hub-viewer__status--error').length, 0, 'an aborted load shows no error');
  assert.equal(currentDocument.activeElement, link);
  assert.deepEqual(busyReasons(), []);
});

test('opening a second file closes the first; only one dialog exists', async (t) => {
  await setup();
  t.after(teardown);

  click(makeLink({ href: '/files/a.png', 'data-hub-viewer': 'image' }));
  const first = dialogOf();
  click(makeLink({ href: '/files/b.png', 'data-hub-viewer': 'image' }));

  assert.equal(first.open, false);
  assert.equal(currentDocument.body.findAll((node) => node.tagName === 'DIALOG').length, 1);
});

test('a Download link appears only for a same-origin data-hub-viewer-download URL', async (t) => {
  await setup();
  t.after(teardown);

  click(makeLink({ href: '/files/a.png', 'data-hub-viewer': 'image', 'data-hub-viewer-download': '/files/a.png?download=1' }));
  const action = dialogOf().byClass('hub-viewer__action')[0];
  assert.equal(action.textContent, 'Download');
  assert.equal(action.href, 'https://www.mbfdhub.com/files/a.png?download=1');
  assert.equal(action.hasAttribute('download'), true);
  assert.equal(action.hasAttribute('target'), false);
  click(dialogOf().byClass('hub-viewer__close')[0]);

  click(makeLink({ href: '/files/a.png', 'data-hub-viewer': 'image', 'data-hub-viewer-download': 'https://example.com/a.png' }));
  assert.equal(dialogOf().byClass('hub-viewer__action').length, 0);
});

test('the viewer never touches history', async (t) => {
  await setup();
  t.after(teardown);
  globalThis.history = new Proxy({}, { get() { throw new Error('history must not be used'); } });
  t.after(() => { delete globalThis.history; });

  click(makeLink({ href: '/files/a.png', 'data-hub-viewer': 'image' }));
  await settle();
  click(dialogOf().byClass('hub-viewer__close')[0]);
});

test('helpers read a page from the fragment and a kind from the extension or content type', async () => {
  const { pageFromHash, kindFromExtension, kindFromContentType } = await import('../../resources/js/pwa/document-viewer.ts');

  assert.equal(pageFromHash('#page=12'), 12);
  assert.equal(pageFromHash('#zoom=2&page=3'), 3);
  assert.equal(pageFromHash('#page=0'), undefined);
  assert.equal(pageFromHash('#section-2'), undefined);
  assert.equal(pageFromHash(''), undefined);

  assert.equal(kindFromExtension('/a/b.PDF'), 'pdf');
  assert.equal(kindFromExtension('/a/b.webp'), 'image');
  assert.equal(kindFromExtension('/a/b.docx'), null);
  assert.equal(kindFromExtension('/a/b'), null);

  assert.equal(kindFromContentType('application/pdf; charset=binary'), 'pdf');
  assert.equal(kindFromContentType('image/png'), 'image');
  assert.equal(kindFromContentType('text/html'), null);
  assert.equal(kindFromContentType(null), null);
});

test('wiring: Home and the panels load pdf.js lazily; Daily shows images only; the CSS reaches all three', () => {
  const shell = readFileSync(resolve(root, 'resources/js/hub-shell.js'), 'utf8');
  assert.match(shell, /import \{ startDocumentViewer \} from '\.\/pwa\/document-viewer';/);
  assert.match(shell, /startDocumentViewer\(\{ loadPdfRenderer: \(\) => import\('\.\/pwa\/document-viewer-pdf'\) \}\);/);
  assert.doesNotMatch(shell, /^import .*document-viewer-pdf/m, 'pdf.js is only reached by dynamic import');

  const daily = readFileSync(resolve(root, 'resources/js/daily-checkout/src/main.tsx'), 'utf8');
  assert.match(daily, /startDocumentViewer\(\)/);
  assert.doesNotMatch(daily, /document-viewer-pdf/);

  assert.match(readFileSync(resolve(root, 'resources/css/hub-components.css'), 'utf8'), /^\.hub-viewer \{/m);
  for (const entry of ['resources/css/app.css', 'resources/css/filament/admin/theme.css', 'resources/js/daily-checkout/src/index.css']) {
    assert.match(readFileSync(resolve(root, entry), 'utf8'), /hub-components\.css/, `${entry} imports hub-components.css`);
  }
});

test('the viewer stylesheet keeps to the design system: tokens only, no gradients, 44px targets, safe area, focus ring', () => {
  const css = readFileSync(resolve(root, 'resources/css/hub-components.css'), 'utf8');
  const section = css.slice(css.indexOf('/* Document viewer'));
  const viewer = section.slice(section.indexOf('.hub-viewer {'));

  assert.doesNotMatch(viewer, /#[0-9a-fA-F]{3,8}\b/, 'no hex colors');
  assert.doesNotMatch(viewer, /gradient/);
  assert.match(viewer, /min-height: 44px/);
  assert.match(viewer, /env\(safe-area-inset-bottom\)/);
  assert.match(viewer, /:focus-visible/);
  assert.doesNotMatch(viewer, /transition|animation/, 'nothing animates, so reduced motion has nothing to disable');
});
