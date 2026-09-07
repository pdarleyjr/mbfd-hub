import test from 'node:test';
import assert from 'node:assert/strict';

import {
  formatDateOnly,
  formatTimestampDate,
  todayDateOnly,
} from '../../resources/js/daily-checkout/src/utils/dateTime.ts';

test('date-only formatting preserves the calendar date in every browser timezone', () => {
  const originalTimezone = process.env.TZ;

  try {
    for (const timezone of ['America/New_York', 'UTC', 'America/Los_Angeles']) {
      process.env.TZ = timezone;
      assert.equal(formatDateOnly('2026-09-06'), 'Sep 6, 2026');
      assert.equal(formatDateOnly('2026-03-08'), 'Mar 8, 2026');
      assert.equal(formatDateOnly('2026-11-01'), 'Nov 1, 2026');
      assert.equal(formatDateOnly('2026-02-14'), 'Feb 14, 2026');
    }
  } finally {
    process.env.TZ = originalTimezone;
  }
});

test('date-only and timestamp contracts remain distinct', () => {
  process.env.TZ = 'America/New_York';

  assert.equal(formatDateOnly('2026-09-06'), 'Sep 6, 2026');
  assert.equal(formatTimestampDate('2026-09-06T00:00:00Z'), 'Sep 5, 2026');
  assert.equal(todayDateOnly(new Date('2026-09-07T03:30:00Z')), '2026-09-06');
});

test('invalid date-only input fails closed', () => {
  assert.throws(() => formatDateOnly('2026-02-30'), /date-only/i);
  assert.throws(() => formatDateOnly('2026-09-06T00:00:00Z'), /date-only/i);
});
