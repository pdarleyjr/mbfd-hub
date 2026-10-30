import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';
import test from 'node:test';

const directory = 'public/images/apparatus/roster';
const cutouts = ['engine', 'ladder-1', 'ladder-3', 'rescue', 'fireboat'];
const photos = ['command-300', 'captain-5'];
const SMALL_BUDGET = 4 * 1024;
const LARGE_BUDGET = 10 * 1024;
const TOTAL_BUDGET = 62 * 1024;

const files = [
  ...cutouts.flatMap((name) => [[`${name}-96.webp`, SMALL_BUDGET], [`${name}-192.webp`, LARGE_BUDGET]]),
  ...photos.flatMap((name) => [[`${name}-120.webp`, SMALL_BUDGET], [`${name}-240.webp`, LARGE_BUDGET]]),
];

test('every roster unit image exists and stays inside its budget', () => {
  let total = 0;
  for (const [file, budget] of files) {
    const { size } = statSync(`${directory}/${file}`);
    assert.ok(size <= budget, `${file} is ${size} bytes, budget ${budget}`);
    total += size;
  }
  assert.ok(total <= TOTAL_BUDGET, `total is ${total} bytes, budget ${TOTAL_BUDGET}`);
});

/** A WebP with transparency is VP8X with the alpha flag set, or VP8L with its alpha bit set. */
function webpHasAlpha(bytes) {
  const format = bytes.toString('latin1', 12, 16);
  if (format === 'VP8X') {
    return (bytes[20] & 0x10) !== 0;
  }
  if (format === 'VP8L') {
    return (bytes[24] & 0x10) !== 0;
  }
  return false;
}

test('apparatus cutouts keep their transparency; the photographs are opaque', () => {
  for (const name of cutouts) {
    for (const width of [96, 192]) {
      const bytes = readFileSync(`${directory}/${name}-${width}.webp`);
      assert.equal(bytes.toString('latin1', 0, 4), 'RIFF');
      assert.equal(bytes.toString('latin1', 8, 12), 'WEBP');
      assert.ok(webpHasAlpha(bytes), `${name}-${width}.webp must carry alpha`);
    }
  }
  for (const name of photos) {
    assert.equal(webpHasAlpha(readFileSync(`${directory}/${name}-120.webp`)), false, `${name} is not a cutout`);
  }
});

function luminance([r, g, b]) {
  const [lr, lg, lb] = [r, g, b].map((channel) => {
    const value = channel / 255;
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * lr + 0.7152 * lg + 0.0722 * lb;
}

function contrast(first, second) {
  const [light, dark] = [luminance(first), luminance(second)].sort((a, b) => b - a);
  return (light + 0.05) / (dark + 0.05);
}

test('every status pill meets WCAG AA for its text', () => {
  const theme = readFileSync('resources/css/mbfd-theme.css', 'utf8');
  const channels = (name) => {
    const match = new RegExp(`--hub-roster-${name}:\\s*(\\d+) (\\d+) (\\d+);`).exec(theme);
    assert.ok(match, `--hub-roster-${name} is declared`);
    return match.slice(1).map(Number);
  };
  for (const tone of ['sp', 'ot', 'aday', 'vaca', 'leave', 'sick', 'exchange', 'ooc', 'pms', 'other', 'unknown']) {
    const ratio = contrast(channels(`${tone}-ink`), channels(`${tone}-bg`));
    assert.ok(ratio >= 4.5, `${tone} contrast is ${ratio.toFixed(2)}`);
  }
});

test('the roster script never reloads the page, keeps its version in memory and touches no worker', () => {
  const script = readFileSync('resources/js/daily-roster.js', 'utf8');
  assert.doesNotMatch(script, /location\.reload|serviceWorker|localStorage|sessionStorage|indexedDB|registerBusy|setBusy/);
  assert.match(script, /If-None-Match/);
});
