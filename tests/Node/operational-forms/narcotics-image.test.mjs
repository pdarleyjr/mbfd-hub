import assert from 'node:assert/strict';
import test from 'node:test';
import {
  BUSY_REASON, FORM_KEY, MAX_SOURCE_BYTES, ORIENTATION_PROBE_JPEG_BASE64, SENT_MESSAGE, TARGET_BYTES,
  browserAppliesExifOrientation, browserDecodesNatively, checkSelection, describeFailure, encodeWithPlan, encodingPlan, fitWithin, formPhase,
  formatBytes, isUnsafeToRefresh, isUuid, newSubmissionId, orientationPlan, photoFileName, readJpegOrientation,
  searchForView, urlFor, viewFromDeepLink, viewFromSearch,
} from '../../../resources/js/operational-forms/narcotics/narcoticsImage.js';

/** A JPEG-shaped byte string with an APP1/Exif segment carrying `orientation`. */
function jpegWithOrientation(orientation, { little = true } = {}) {
  const tiff = little
    ? [0x49, 0x49, 0x2a, 0x00, 8, 0, 0, 0, 1, 0, 0x12, 0x01, 3, 0, 1, 0, 0, 0, orientation, 0, 0, 0, 0, 0, 0, 0]
    : [0x4d, 0x4d, 0x00, 0x2a, 0, 0, 0, 8, 0, 1, 0x01, 0x12, 0, 3, 0, 0, 0, 1, 0, orientation, 0, 0, 0, 0, 0, 0];
  const payload = [0x45, 0x78, 0x69, 0x66, 0, 0, ...tiff];
  const length = payload.length + 2;
  return new Uint8Array([0xff, 0xd8, 0xff, 0xe1, length >> 8, length & 0xff, ...payload, 0xff, 0xda, 0, 2]);
}

test('the EXIF orientation is read in both byte orders', () => {
  for (let value = 1; value <= 8; value++) {
    assert.equal(readJpegOrientation(jpegWithOrientation(value)), value);
    assert.equal(readJpegOrientation(jpegWithOrientation(value, { little: false })), value);
  }
});

test('the real probe image carries orientation 6', () => {
  const bytes = Uint8Array.from(Buffer.from(ORIENTATION_PROBE_JPEG_BASE64, 'base64'));
  assert.equal(bytes.length, 730);
  assert.equal(readJpegOrientation(bytes), 6);
});

test('anything unreadable is upright', () => {
  assert.equal(readJpegOrientation(new Uint8Array()), 1);
  assert.equal(readJpegOrientation(new Uint8Array([1, 2, 3, 4, 5])), 1);
  assert.equal(readJpegOrientation(new Uint8Array([0xff, 0xd8, 0xff, 0xda, 0, 2])), 1);
  assert.equal(readJpegOrientation(jpegWithOrientation(9)), 1, 'out of range');
  assert.equal(readJpegOrientation(jpegWithOrientation(6).slice(0, 14)), 1, 'truncated');
  assert.equal(readJpegOrientation(new Uint8Array([0xff, 0xd8, 0xff, 0xe1, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff])), 1, 'absurd length');
});

test('orientation plans swap the sides for the turned cases and keep them for the others', () => {
  for (const o of [1, 2, 3, 4]) assert.deepEqual([orientationPlan(o, 800, 600).width, orientationPlan(o, 800, 600).height], [800, 600]);
  for (const o of [5, 6, 7, 8]) assert.deepEqual([orientationPlan(o, 800, 600).width, orientationPlan(o, 800, 600).height], [600, 800]);
  assert.deepEqual(orientationPlan(1, 800, 600).matrix, [1, 0, 0, 1, 0, 0]);
  assert.deepEqual(orientationPlan(99, 800, 600).matrix, [1, 0, 0, 1, 0, 0]);
});

// Apply a canvas transform [a,b,c,d,e,f] to a point: x' = a*x + c*y + e ; y' = b*x + d*y + f.
const apply = ([a, b, c, d, e, f], x, y) => [a * x + c * y + e, b * x + d * y + f];

test('every orientation matrix lands the stored corners inside the upright canvas', () => {
  const width = 800;
  const height = 600;
  for (let o = 1; o <= 8; o++) {
    const plan = orientationPlan(o, width, height);
    for (const [x, y] of [[0, 0], [width, 0], [0, height], [width, height]]) {
      const [nx, ny] = apply(plan.matrix, x, y);
      assert.ok(nx >= 0 && nx <= plan.width && ny >= 0 && ny <= plan.height, `o=${o} (${x},${y}) -> (${nx},${ny}) outside ${plan.width}x${plan.height}`);
    }
  }
});

test('orientation 6 puts the stored top-left corner at the upright top-right; 8 puts it at the bottom-left; 3 at the bottom-right', () => {
  // "6" = view it rotated 90 degrees clockwise: the stored top-left becomes the top-right.
  assert.deepEqual(apply(orientationPlan(6, 800, 600).matrix, 0, 0), [600, 0]);
  assert.deepEqual(apply(orientationPlan(8, 800, 600).matrix, 0, 0), [0, 800]);
  assert.deepEqual(apply(orientationPlan(3, 800, 600).matrix, 0, 0), [800, 600]);
  assert.deepEqual(apply(orientationPlan(2, 800, 600).matrix, 0, 0), [800, 0]);
  assert.deepEqual(apply(orientationPlan(4, 800, 600).matrix, 0, 0), [0, 600]);
  assert.deepEqual(apply(orientationPlan(5, 800, 600).matrix, 800, 0), [0, 800], 'transpose swaps x and y');
  assert.deepEqual(apply(orientationPlan(7, 800, 600).matrix, 0, 0), [600, 800]);
});

test('a browser that already stands the probe upright is trusted; one that does not gets the manual rotation', () => {
  assert.equal(browserAppliesExifOrientation(1, 2), true);
  assert.equal(browserAppliesExifOrientation(2, 1), false);
});

test('fitWithin bounds the long edge, keeps the shape and never enlarges', () => {
  assert.deepEqual(fitWithin(4000, 3000, 3200), { width: 3200, height: 2400, scale: 0.8 });
  assert.deepEqual(fitWithin(3000, 4000, 3200), { width: 2400, height: 3200, scale: 0.8 });
  assert.deepEqual(fitWithin(800, 600, 3200), { width: 800, height: 600, scale: 1 });
  assert.deepEqual(fitWithin(3200, 100, 3200), { width: 3200, height: 100, scale: 1 });
  assert.equal(fitWithin(12000, 1, 3200).height, 1, 'a sliver never collapses to zero');
  assert.deepEqual(fitWithin(0, 0, 3200), { width: 0, height: 0, scale: 1 });
});

test('the selection check refuses non-photos and empty files before any work is spent', () => {
  assert.deepEqual(checkSelection({ name: 'a.jpg', type: 'image/jpeg', size: 2_000_000 }), { ok: true });
  assert.deepEqual(checkSelection({ name: 'IMG_0001.HEIC', type: '', size: 2_000_000 }), { ok: true });
  assert.equal(checkSelection({ name: 'a.pdf', type: 'application/pdf', size: 10 }).ok, false);
  assert.equal(checkSelection({ name: 'a.jpg', type: 'image/jpeg', size: 0 }).ok, false);
  assert.equal(checkSelection({ name: 'a.jpg', type: 'image/jpeg', size: MAX_SOURCE_BYTES + 1 }).ok, false);
  assert.equal(checkSelection(null).ok, false);
  assert.match(checkSelection({ name: 'a.exe', type: 'application/x-msdownload', size: 5 }).message, /not a photo/);
});

test('the encoding plan tries WebP only when the server reads it, and always ends in JPEG', () => {
  assert.deepEqual(encodingPlan(['image/jpeg', 'image/png', 'image/webp']).map((s) => s.type), ['image/webp', 'image/jpeg']);
  assert.deepEqual(encodingPlan(['image/jpeg', 'image/png']).map((s) => s.type), ['image/jpeg']);
  assert.deepEqual(encodingPlan([]).map((s) => s.type), ['image/jpeg']);
  assert.deepEqual(encodingPlan(undefined).map((s) => s.type), ['image/jpeg']);
  assert.ok(!encodingPlan(['image/avif', 'image/webp']).some((s) => s.type === 'image/avif'), 'AVIF is never attempted');
});

test('quality never drops below a clear floor', () => {
  for (const step of encodingPlan(['image/webp'])) {
    assert.ok(Math.min(...step.qualities) >= 0.74, step.type);
    assert.deepEqual([...step.qualities], [...step.qualities].sort((a, b) => b - a), 'a descending ladder');
  }
});

const blob = (type, size) => ({ type, size });

test('the first rung is kept when the file is light enough', async () => {
  const calls = [];
  const result = await encodeWithPlan(async (type, quality) => { calls.push([type, quality]); return blob(type, 900_000); }, encodingPlan(['image/webp']));
  assert.deepEqual(result, { blob: blob('image/webp', 900_000), type: 'image/webp', quality: 0.86 });
  assert.equal(calls.length, 1);
});

test('quality steps down only while the file is over the target, and the last rung is accepted', async () => {
  const sizes = { 0.86: TARGET_BYTES + 900, 0.8: TARGET_BYTES + 500, 0.74: TARGET_BYTES + 100 };
  const result = await encodeWithPlan(async (type, quality) => blob(type, sizes[quality]), [{ type: 'image/webp', qualities: [0.86, 0.8, 0.74] }]);
  assert.equal(result.quality, 0.74);
  assert.equal(result.type, 'image/webp');

  const steps = [];
  const light = await encodeWithPlan(async (type, quality) => { steps.push(quality); return blob(type, quality === 0.8 ? 1000 : TARGET_BYTES + 1); }, [{ type: 'image/webp', qualities: [0.86, 0.8, 0.74] }]);
  assert.equal(light.quality, 0.8);
  assert.deepEqual(steps, [0.86, 0.8], 'it stops as soon as the file is light enough');
});

test('a browser that cannot write WebP (it hands back PNG or nothing) falls through to JPEG', async () => {
  const types = [];
  const result = await encodeWithPlan(async (type) => { types.push(type); return type === 'image/webp' ? blob('image/png', 5_000_000) : blob(type, 1_000_000); }, encodingPlan(['image/webp']));
  assert.equal(result.type, 'image/jpeg');
  assert.equal(result.blob.type, 'image/jpeg');
  assert.deepEqual(types, ['image/webp', 'image/jpeg'], 'the unsupported type is tried once, not for every quality');

  assert.equal((await encodeWithPlan(async () => null, encodingPlan(['image/webp']))), null, 'nothing encodes: the caller falls back to the original file');
  assert.equal((await encodeWithPlan(async (type) => blob(type, 0), encodingPlan([]))), null, 'an empty result is not a photo');
});

test('file names match the format of the bytes', () => {
  assert.equal(photoFileName('image/webp'), 'narcotics-check.webp');
  assert.equal(photoFileName('image/jpeg'), 'narcotics-check.jpg');
});

test('the form phase follows the work: dirty, sending, failed, saved', () => {
  const base = { hasPhoto: false, note: '', sending: false, failed: false, saved: false };
  assert.equal(formPhase(base), 'empty');
  assert.equal(formPhase({ ...base, hasPhoto: true }), 'dirty');
  assert.equal(formPhase({ ...base, note: '  ' }), 'empty', 'blank space is not work');
  assert.equal(formPhase({ ...base, note: 'Box 2' }), 'dirty');
  assert.equal(formPhase({ ...base, hasPhoto: true, sending: true }), 'sending');
  assert.equal(formPhase({ ...base, hasPhoto: true, failed: true }), 'failed');
  assert.equal(formPhase({ ...base, failed: true }), 'empty', 'a failure with nothing left to lose is not unsafe');
  assert.equal(formPhase({ ...base, saved: true }), 'saved');
  assert.equal(formPhase({ ...base, hasPhoto: true, saved: true }), 'saved', 'a verified save wins');
});

test('only dirty, sending and failed block a refresh; a failed save stays unsafe until it is saved or discarded', () => {
  assert.equal(isUnsafeToRefresh('empty'), false);
  assert.equal(isUnsafeToRefresh('saved'), false);
  assert.equal(isUnsafeToRefresh('dirty'), true);
  assert.equal(isUnsafeToRefresh('sending'), true);
  assert.equal(isUnsafeToRefresh('failed'), true);
  assert.equal(BUSY_REASON, 'narcotics-check');
});

test('failures tell the member what happened, whether the photo is safe and what to do', () => {
  const lost = describeFailure({ status: 0, code: null, message: '', network: true });
  assert.equal(lost.retry, true);
  assert.match(lost.message, /still here/);

  assert.equal(describeFailure({ status: 500, code: 'server_error', message: 'x', network: false }).retry, true);
  assert.equal(describeFailure({ status: 429, code: 'too_many_pending', message: 'You already have 10 narcotics checks waiting for Captain 5.', network: false }).message, 'You already have 10 narcotics checks waiting for Captain 5.');
  const signedOut = describeFailure({ status: 419, code: null, message: '', network: false });
  assert.equal(signedOut.retry, false);
  assert.match(signedOut.message, /do not close it/);
  const tooBig = describeFailure({ status: 413, code: null, message: '', network: false });
  assert.deepEqual([tooBig.retry, tooBig.newPhoto], [false, true]);
  const bad = describeFailure({ status: 422, code: 'photo_type', message: 'That file is not a supported photo.', network: false });
  assert.deepEqual([bad.retry, bad.newPhoto, bad.message], [false, true, 'That file is not a supported photo.']);
  const field = describeFailure({ status: 422, code: 'validation_failed', message: 'The note can be at most 500 characters.', network: false });
  assert.deepEqual([field.retry, field.newPhoto], [false, false]);
  assert.equal(SENT_MESSAGE, 'Narcotics check sent to Captain 5.');
});

test('submission ids are fresh UUIDs', () => {
  const a = newSubmissionId();
  const b = newSubmissionId();
  assert.ok(isUuid(a) && isUuid(b));
  assert.notEqual(a, b);
  assert.equal(isUuid('nope'), false);
  assert.equal(isUuid(null), false);
});

test('views round-trip through the address bar and the server deep link', () => {
  const id = '3f2b8c1e-5a47-4d0e-9b6f-0c1d2e3f4a5b';
  assert.deepEqual(viewFromSearch(`?form=${FORM_KEY}`), { name: 'form' });
  assert.deepEqual(viewFromSearch(`?form=${FORM_KEY}&view=mine`), { name: 'mine' });
  assert.deepEqual(viewFromSearch(`?form=${FORM_KEY}&check=${id.toUpperCase()}`), { name: 'detail', id });
  assert.deepEqual(viewFromSearch(`?form=${FORM_KEY}&check=not-a-uuid`), { name: 'form' });
  assert.equal(viewFromSearch('?form=medical-inventory-request'), null);
  assert.equal(viewFromSearch(''), null);

  assert.equal(searchForView(null), '');
  assert.equal(searchForView({ name: 'form' }), `?form=${FORM_KEY}`);
  assert.equal(searchForView({ name: 'mine' }), `?form=${FORM_KEY}&view=mine`);
  assert.equal(searchForView({ name: 'detail', id }), `?form=${FORM_KEY}&check=${id}`);
  for (const view of [{ name: 'form' }, { name: 'mine' }, { name: 'detail', id }]) {
    assert.deepEqual(viewFromSearch(searchForView(view)), view);
  }

  assert.deepEqual(viewFromDeepLink({ view: 'detail', check: id }), { name: 'detail', id });
  assert.deepEqual(viewFromDeepLink({ view: 'mine', check: null }), { name: 'mine' });
  assert.deepEqual(viewFromDeepLink({ view: 'form', check: null }), { name: 'form' });
  assert.equal(viewFromDeepLink({ view: 'detail', check: 'nope' }), null);
  assert.equal(viewFromDeepLink({ view: null, check: null }), null);
  assert.equal(viewFromDeepLink(undefined), null);
});

test('endpoint templates are filled with an encoded id', () => {
  assert.equal(urlFor('/api/narcotics-checks/mine/__UUID__', '__UUID__', 'abc'), '/api/narcotics-checks/mine/abc');
  assert.equal(urlFor('/x/__UUID__/y', '__UUID__', 'a/b'), '/x/a%2Fb/y');
});

test('sizes read like a person would say them', () => {
  assert.equal(formatBytes(0), '0 KB');
  assert.equal(formatBytes(512), '1 KB');
  assert.equal(formatBytes(820 * 1024), '820 KB');
  assert.equal(formatBytes(1.4 * 1024 * 1024), '1.4 MB');
});

test('a JPEG, PNG or WebP that will not decode is damaged; HEIC and the unfamiliar are left to the server', () => {
  for (const type of ['image/jpeg', 'IMAGE/PNG', 'image/webp', 'image/gif']) assert.equal(browserDecodesNatively({ type, name: 'x' }), true, type);
  for (const file of [{ type: '', name: 'IMG_0001.HEIC' }, { type: 'image/heic', name: 'a.heic' }, { type: 'image/heif', name: 'a.heif' }, { type: '', name: 'photo' }, null]) {
    assert.equal(browserDecodesNatively(file), false);
  }
});
