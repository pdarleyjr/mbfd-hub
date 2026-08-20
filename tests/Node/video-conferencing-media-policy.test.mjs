import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { mediaFailureMessage } from '../../resources/js/video-conferencing/media-policy.ts';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const app = readFileSync(resolve(root, 'resources/js/video-conferencing/ConferenceApp.tsx'), 'utf8');

test('media permission denial is actionable and does not trigger automatic retry prompts', () => {
  assert.match(mediaFailureMessage('permission_denied'), /Allow access in browser settings, then try again/);
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

test('station microphone RPC reflects floor state before WebRTC renegotiation completes', () => {
  const start = app.indexOf("registerRpcMethod('mbfd.stationMic'");
  const end = app.indexOf('return JSON.stringify({ enabled });', start);
  const handler = app.slice(start, end);

  assert.ok(start >= 0 && end > start, 'station microphone RPC handler is present');
  assert.ok(
    handler.indexOf('setMicrophoneEnabled(enabled);')
      < handler.indexOf('await nextRoom!.localParticipant.setMicrophoneEnabled(enabled);'),
    'station state is updated before the potentially slow WebRTC operation',
  );
  assert.match(handler, /if \(enabled\) \{[\s\S]*?setMicrophoneEnabled\(false\);[\s\S]*?setForcedStationMic\(true\);/);
});
