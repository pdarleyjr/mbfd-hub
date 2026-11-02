import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import { otherMembersCheckoutNotice } from '../../resources/js/daily-checkout/src/utils/sharedDeviceNotice.ts';

const root = resolve(import.meta.dirname, '..', '..');
const read = (path) => readFileSync(resolve(root, path), 'utf8');

// 2026-11-02 06:42 Eastern (standard time).
const savedAt = new Date('2026-11-02T11:42:00.000Z');

test('another member\'s unsent checkout is a notice naming who will send it', () => {
  assert.equal(
    otherMembersCheckoutNotice({ id: 'daily_a', operatorName: 'Aurelio Mederos', createdAt: savedAt, status: 'pending', hasOwner: true }, 'R 22'),
    'A checkout for R 22 saved on this device by Aurelio Mederos at Nov 2, 6:42 AM has not been sent yet. It will send when Aurelio Mederos signs in on this device.',
  );
});

test('a rejected checkout of another member and a legacy record say what happens to them instead', () => {
  assert.match(
    otherMembersCheckoutNotice({ id: 'daily_b', operatorName: 'Pat Doe', createdAt: savedAt, status: 'requires_attention', hasOwner: true }, 'R 22'),
    /needs review\. It stays on this device for Pat Doe to review after signing in\.$/,
  );
  assert.match(
    otherMembersCheckoutNotice({ id: 'daily_c', createdAt: savedAt, status: 'requires_attention', hasOwner: false }, 'R 22'),
    /^A checkout for R 22 saved on this device by another member at .* needs review by an officer\. You can still complete your own checkout\.$/,
  );
});

test('the notice copy has no em dash', () => {
  assert.doesNotMatch(read('resources/js/daily-checkout/src/utils/sharedDeviceNotice.ts'), /—/);
});

test('schema 7 makes the duplicate guard per member and keeps every record (no upgrade step)', () => {
  const db = read('resources/js/daily-checkout/src/lib/db.ts');
  const version7 = db.slice(db.indexOf('this.version(7)'));
  assert.match(version7, /'&id, &\[apparatusId\+checklistVersion\+ownerUserId\], \[apparatusId\+checklistVersion\],/);
  assert.doesNotMatch(version7, /\.upgrade\(/, 'version 7 must not rewrite, delete or re-status any record');
});

test('every duplicate lookup in the queue is scoped to the owner', () => {
  const queue = read('resources/js/daily-checkout/src/utils/dailyCheckoutSubmissionQueue.ts');
  // The only remaining device-wide lookup is the legacy import, which matches unowned records only.
  const deviceWide = [...queue.matchAll(/where\('\[apparatusId\+checklistVersion\]'\)[\s\S]{0,200}/g)].map((match) => match[0]);
  assert.equal(deviceWide.length, 1);
  assert.match(deviceWide[0], /!hasProvenOwner\(existing\)/);
  assert.match(queue, /submission\.checklistBaseVersion === checklistBaseVersion && submission\.ownerUserId === ownerUserId/);
});

test('replay leaves another member\'s record untouched instead of quarantining it', () => {
  const queue = read('resources/js/daily-checkout/src/utils/dailyCheckoutSubmissionQueue.ts');
  const generic = read('resources/js/daily-checkout/src/lib/sync.ts');
  assert.doesNotMatch(queue, /ownershipState: 'identity_mismatch'/);
  assert.doesNotMatch(generic, /ownershipState: 'identity_mismatch'/);
});
