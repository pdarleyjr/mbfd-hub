import assert from 'node:assert/strict';
import test from 'node:test';
import { formatHubDate, hubDateInput, HUB_TIMEZONE } from '../../resources/js/shared/timezone.ts';
import { dateTimeText } from '../../resources/js/daily-checkout/src/components/acting/format.ts';
import { formatAbsolute, formatRelative } from '../../resources/js/daily-checkout/src/components/command-communications/format.ts';
import { ServerClock } from '../../resources/js/shared/server-clock.ts';

test('timestamp labels keep Eastern dates and times when the device is in another timezone', () => {
  const prior = process.env.TZ;
  process.env.TZ = 'Pacific/Honolulu';
  try {
    assert.equal(HUB_TIMEZONE, 'America/New_York');
    assert.equal(formatHubDate('2026-10-26T03:59:59Z'), 'Oct 25, 2026');
    assert.equal(formatHubDate('2026-10-26T04:00:00Z'), 'Oct 26, 2026');
    assert.equal(formatHubDate('2026-10-26'), 'Oct 26, 2026');
    assert.equal(hubDateInput(new Date('2026-10-26T03:59:59Z')), '2026-10-25');
    assert.equal(hubDateInput(new Date('2026-10-26T04:00:00Z')), '2026-10-26');
    assert.equal(hubDateInput(new Date('2026-10-26T04:00:00Z'), true), '2026-10-26T00:00');
    assert.equal(hubDateInput(new Date('2026-03-08T07:00:00Z'), true), '2026-03-08T03:00');
    assert.equal(dateTimeText('2026-10-26T04:00:00Z'), 'Oct 26, 12:00 AM');
    assert.match(formatAbsolute('2026-03-08T06:59:59Z'), /Mar 8, 2026, 1:59 AM/);
    assert.match(formatAbsolute('2026-03-08T07:00:00Z'), /Mar 8, 2026, 3:00 AM/);
    assert.equal(formatRelative('2026-10-26T04:00:00Z', Date.parse('2026-10-28T04:00:00Z')), 'Oct 26, 12:00 AM');
  } finally {
    if (prior === undefined) delete process.env.TZ;
    else process.env.TZ = prior;
  }
});

test('custom acting bounds use the server instant and Eastern input fields', () => {
  const original = Date.now;
  Date.now = () => Date.parse('2099-01-01T00:00:00Z');
  try {
    const clock = new ServerClock('2026-10-26T00:00:00-04:00', 100);
    assert.equal(hubDateInput(new Date(clock.now(100) + 15 * 60_000), true), '2026-10-26T00:15');
    assert.equal(hubDateInput(new Date(clock.now(100) + 72 * 3_600_000), true), '2026-10-29T00:00');
  } finally {
    Date.now = original;
  }
});
