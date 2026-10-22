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

test('300 Command access comes from the server policy and no PIN UI or endpoint remains', () => {
  assert.doesNotMatch(app, /commandPin|command_pin|authorizeCommand|command_authorize|Continue as 300/);
  assert.match(app, /useState\(bootstrap\.command_access === true\)/);
});

test('a 403 from any command endpoint shows the access-denied panel instead of a PIN form', () => {
  const start = app.indexOf('const denyCommandAccess = useCallback');
  const end = app.indexOf('const refreshCommandStatus = useCallback', start);
  const handler = app.slice(start, end);

  assert.ok(start >= 0 && end > start, 'command access denial handler is present');
  assert.match(handler, /status !== 403\) return false;/);
  assert.match(handler, /setCommandAuthorized\(false\)/);
  assert.match(app, /className="vc-command-denied" role="alert"/);
  assert.match(app, /href=\{bootstrap\.navigation\.back\}/);
  assert.doesNotMatch(app, /className="vc-command-login"/);
  for (const handlerName of ['startError', 'directError', 'statusError']) {
    assert.ok(app.includes("denyCommandAccess(" + handlerName + ")"), handlerName + " is routed through the access-denied handler");
  }
});

test('an account without 300 access is never prompted for camera or microphone on page open', () => {
  assert.match(app, /bootstrap\.entry_mode === 'command' && !bootstrap\.command_access\) return;[\s\S]*?void prepareMedia\(\)/);
});

test('presentation preference preserves adaptive delivery at actual gallery dimensions', () => {
  assert.match(app, /adaptiveStream: true/);
  assert.match(app, /dynacast: true/);
  assert.match(app, /simulcast: true/);
  assert.match(app, /VideoPresets\.h720/);
  assert.match(app, /if \(high\) publication.setVideoQuality\(VideoQuality.HIGH\)/);
  assert.doesNotMatch(app, /VideoQuality.LOW/);
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
