import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { isMediaPermissionDenied, mediaErrorMessage } from '../../resources/js/video-conferencing/media.ts';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const app = readFileSync(resolve(root, 'resources/js/video-conferencing/ConferenceApp.tsx'), 'utf8');

test('media permission denial is actionable and does not trigger automatic retry prompts', () => {
  const denial = new DOMException('denied', 'NotAllowedError');
  assert.equal(isMediaPermissionDenied(denial), true);
  assert.match(mediaErrorMessage(denial), /Allow access in browser settings, then try again/);
  assert.match(app, /if \(!isMediaPermissionDenied\(deviceError\)\)/);
});

test('conference mount prepares media once and exposes an explicit retry and device switching', () => {
  assert.match(app, /if \(autoMediaStartedRef\.current\) return;[\s\S]*?void prepareMedia\(\)/);
  assert.match(app, /Test Camera &amp; Microphone/);
  assert.match(app, /restartTrack\(\{ deviceId: value \}\)/);
  assert.match(app, /createLocalTracks\(\{ video:[\s\S]*?audio: false \}\)/);
  assert.match(app, /createLocalTracks\(\{ audio: true, video: false \}\)/);
});

test('focused videos request HIGH and thumbnails request LOW with adaptive delivery enabled', () => {
  assert.match(app, /adaptiveStream: true/);
  assert.match(app, /dynacast: true/);
  assert.match(app, /simulcast: true/);
  assert.match(app, /VideoPresets\.h720/);
  assert.match(app, /setVideoQuality\(high \? VideoQuality\.HIGH : VideoQuality\.LOW\)/);
});
