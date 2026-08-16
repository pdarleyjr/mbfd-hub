import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8');

test('Daily routes use one canonical station request entry and replace legacy history entries', () => {
  const app = read('resources/js/daily-checkout/src/App.tsx');
  const redirect = read('resources/js/daily-checkout/src/components/LegacyStationRequestRedirect.tsx');
  const formsHub = read('resources/js/daily-checkout/src/components/FormsHub.tsx');
  const station = read('resources/js/daily-checkout/src/components/StationDetailPage.tsx');

  assert.match(app, /path="\/forms-hub\/station-request"/);
  assert.match(app, /LegacyStationRequestRedirect type="repair_service"/);
  assert.match(app, /LegacyStationRequestRedirect type="equipment"/);
  assert.match(redirect, /<Navigate[^>]+replace/);
  assert.equal((formsHub.match(/to="\/forms-hub\/station-request"/g) || []).length, 1);
  assert.doesNotMatch(station, /to=\{`\/forms-hub\/(?:big-ticket-request|equipment-request)/);
  assert.match(station, /station_id=\$\{station\.id\}/);
});

test('legacy navigation only carries numeric station context and allowlisted internal return paths', () => {
  const navigation = read('resources/js/daily-checkout/src/utils/stationRequestNavigation.ts');

  assert.match(navigation, /\^\\\/\(stations/);
  assert.match(navigation, /value\.startsWith\('\/\/'\)/);
  assert.match(navigation, /\^\\d\+\$/);
  assert.match(navigation, /stationReturnPath = `\/stations\/\$\{rawStationId\}`/);
  assert.match(navigation, /target\.set\('return_to', safeReturnTo\(returnTo, stationReturnPath\)\)/);
});

test('offline station requests keep a durable idempotency key and permanent 4xx errors are not queued for retry', () => {
  const sync = read('resources/js/daily-checkout/src/lib/sync.ts');
  const wizard = read('resources/js/daily-checkout/src/components/forms/StationRequestWizard.tsx');

  assert.match(sync, /type !== 'station_request'/);
  assert.match(sync, /client_submission_id: createClientSubmissionId\(\)/);
  assert.match(sync, /response\.status < 500 && response\.status !== 429/);
  assert.match(wizard, /submitOrQueueWithResponse\('station_request', payload, '\/api\/public'\)/);
  assert.match(wizard, /client_submission_id: clientSubmissionId\.current/);
});

test('station and room APIs are network first so staffing and blueprints cannot remain stale', () => {
  const serviceWorker = read('resources/js/daily-checkout/public/service-worker.js');
  const api = read('resources/js/daily-checkout/src/utils/api.ts');

  assert.match(serviceWorker, /mbfd-checkout-v5/);
  assert.match(serviceWorker, /url\.pathname\.startsWith\('\/api\/public\/stations'\)/);
  assert.match(serviceWorker, /if \(isStationApiRequest \|\| isApparatusApiRequest\)/);
  assert.match(api, /fetch\(`\$\{API_BASE\}\/public\/stations`, \{[\s\S]*cache: 'no-store'/);
  assert.match(api, /fetch\(`\$\{API_BASE\}\/public\/stations\/\$\{id\}`, \{[\s\S]*cache: 'no-store'/);
});

test('station request uses area then station-specific room detail and the station page groups blueprint rooms', () => {
  const wizard = read('resources/js/daily-checkout/src/components/forms/StationRequestWizard.tsx');
  const station = read('resources/js/daily-checkout/src/components/StationDetailPage.tsx');

  assert.match(wizard, /Room area/);
  assert.match(wizard, /Specific room \/ area/);
  assert.match(wizard, /Station-wide \/ no single room/);
  assert.match(wizard, /Room not listed \/ Other/);
  assert.match(station, /groupRoomsByArea/);
  assert.match(station, /Dorm positions/);
});
