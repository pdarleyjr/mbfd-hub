import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

/**
 * Screen share (ConferenceApp.tsx): the button must always say what is true.
 * - The browser's own "Stop sharing" bar unpublishes the track without our button, so the
 *   published-track event has to clear the button state.
 * - Closing the browser's screen picker is a choice, not an error to show a member.
 */

const app = readFileSync(resolve(import.meta.dirname, '..', '..', 'resources/js/video-conferencing/ConferenceApp.tsx'), 'utf8');

test('a screen-share track ending outside our button resets the Share screen control', () => {
  const handler = app.match(/nextRoom\.on\(RoomEvent\.LocalTrackUnpublished, \(publication\) => \{([\s\S]*?)\n        \}\);/);
  assert.ok(handler, 'LocalTrackUnpublished has its own handler');
  assert.match(handler[1], /publication\.source === Track\.Source\.ScreenShare\) setScreenShareEnabled\(false\)/);
  assert.match(handler[1], /refresh\(\)/, 'the gallery still refreshes');
});

test('cancelling the screen picker shows no error, a real failure still does', () => {
  const toggle = app.slice(app.indexOf('const toggleScreenShare'), app.indexOf('const stationMicrophone'));
  assert.match(toggle, /enabled && shareError instanceof DOMException && \(shareError\.name === 'AbortError' \|\| \(shareError\.name === 'NotAllowedError' && !\/by system\/i\.test\(shareError\.message\)\)\)\) return;/);
  assert.match(toggle, /setError\(shareError instanceof Error \? shareError\.message : 'Screen sharing could not be started\.'\)/);
  assert.ok(
    toggle.indexOf("'NotAllowedError'") < toggle.indexOf('setError('),
    'the picker-cancel check comes before the error is shown',
  );
});

test('the Permissions-Policy leaves display-capture at its same-origin default so sharing is not blocked by the Hub', () => {
  const headers = readFileSync(resolve(import.meta.dirname, '..', '..', 'app/Http/Middleware/SecurityHeaders.php'), 'utf8');
  assert.doesNotMatch(headers, /display-capture\s*=\s*\(\s*\)/);
});
