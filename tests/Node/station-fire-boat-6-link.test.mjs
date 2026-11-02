import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const links = await import('../../resources/js/daily-checkout/src/components/station/stationFormLinks.ts');
const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8');

test('Station 6 gets the Fire Boat 6 form link, aimed at the Employee portal form', () => {
  assert.deepEqual(links.fireBoat6FormLink(6), {
    label: 'Fire Boat 6 Medical Inventory Form',
    href: '/employee/forms?form=fire-boat-6-medical-inventory',
  });
});

test('no other station gets it', () => {
  for (const other of [1, 2, 3, 4, 5, 7, 16, 60, 0, -6, 6.5, Number.NaN]) {
    assert.equal(links.fireBoat6FormLink(other), null, `station ${other}`);
  }
});

test('the station page asks by station number and renders the link as a plain href, not a router link', () => {
  const page = read('resources/js/daily-checkout/src/components/StationDetailPage.tsx');
  assert.match(page, /fireBoat6FormLink\(stationNumber\)/);
  assert.match(page, /\{ label: fireBoat6Link\.label, href: fireBoat6Link\.href,/);
  assert.doesNotMatch(page, /fireBoat6FormLink\(station\.id\)/);
  assert.doesNotMatch(page, /to: fireBoat6Link/);
});
