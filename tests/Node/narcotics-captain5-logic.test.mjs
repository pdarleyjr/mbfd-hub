import assert from 'node:assert/strict';
import test from 'node:test';

// The helpers are plain TypeScript with type-only imports, so Node loads them directly.
const logic = await import('../../resources/js/daily-checkout/src/components/captain5/narcotics/logic.ts');

const check = (overrides = {}) => ({
  id: '3f2b8c1e-5a47-4d0e-9b6f-0c1d2e3f4a5b', status: 'pending', status_label: 'Pending review', archived: false, archived_at: null,
  submitted_at: '2026-10-30T13:00:00+00:00', decided_at: null, submitter_name: 'Pat Member', submitter_rank: 'Firefighter',
  has_note: false, member_note: null, rescue_unit: 'R22', seal_number: 'ZX-4471', decision_note: null, decided_by_name: null, decided_by_rank: null,
  resubmission_of: null, resubmitted_as: null, image: { available: true }, ...overrides,
});

test('the inbox query asks for the inbox, oldest first, one page', () => {
  const params = new URLSearchParams(logic.checksQuery(logic.EMPTY_FILTERS));
  assert.equal(params.get('scope'), 'inbox');
  assert.equal(params.get('page'), '1');
  assert.equal(params.get('per_page'), '25');
  assert.equal(params.has('status'), false);
  assert.equal(params.has('q'), false);
});

test('the archive query carries the outcome, search and Eastern dates; the inbox ignores the outcome', () => {
  const archive = new URLSearchParams(logic.checksQuery({ scope: 'archive', outcome: 'rejected', q: '  tamper seal ', dateFrom: '2026-10-01', dateTo: '2026-10-30', page: 3 }));
  assert.equal(archive.get('scope'), 'archive');
  assert.equal(archive.get('status'), 'rejected');
  assert.equal(archive.get('q'), 'tamper seal');
  assert.equal(archive.get('date_from'), '2026-10-01');
  assert.equal(archive.get('date_to'), '2026-10-30');
  assert.equal(archive.get('page'), '3');

  const inbox = new URLSearchParams(logic.checksQuery({ scope: 'inbox', outcome: 'approved', q: '', dateFrom: '', dateTo: '', page: 0 }));
  assert.equal(inbox.has('status'), false, 'the inbox is only ever pending');
  assert.equal(inbox.get('page'), '1', 'page never drops below 1');
  assert.equal(new URLSearchParams(logic.checksQuery({ ...logic.EMPTY_FILTERS, q: 'x'.repeat(300) })).get('q').length, 100);
});

test('a date range that ends before it starts is reported', () => {
  assert.equal(logic.dateRangeError('2026-10-30', '2026-10-01'), 'The end date cannot be before the start date.');
  assert.equal(logic.dateRangeError('2026-10-01', '2026-10-30'), null);
  assert.equal(logic.dateRangeError('', '2026-10-30'), null);
});

test('rows say what matters: the note while pending, the decision after', () => {
  assert.equal(logic.rowNote(check()), null);
  assert.equal(logic.rowNote(check({ has_note: true, member_note: 'Box 2 sealed' })), 'Note: Box 2 sealed');
  assert.equal(logic.rowNote(check({ status: 'approved', decided_by_name: 'Cap Five', decided_by_rank: 'Captain' })), 'Approved by Captain Cap Five');
  assert.equal(logic.rowNote(check({ status: 'rejected', decision_note: 'Seal number not visible' })), 'Rejected: Seal number not visible');
  assert.equal(logic.rowNote(check({ status: 'rejected' })), 'Rejected');
});

test('pending is never worded like a decision', () => {
  assert.equal(logic.decisionText(check()), null);
  const approved = logic.decisionText(check({ status: 'approved', decided_at: '2026-10-30T15:00:00+00:00', decided_by_name: 'Cap Five', decided_by_rank: 'Captain' }));
  assert.match(approved, /^Approved and archived by Captain Cap Five on /);
  const rejected = logic.decisionText(check({ status: 'rejected', decided_at: '2026-10-30T15:00:00+00:00', decided_by_name: 'Cap Five' }));
  assert.match(rejected, /^Rejected and archived by Cap Five on /);
  assert.equal(logic.deciderText({ decided_by_name: null, decided_by_rank: null }), 'Captain 5');
});

test('the rescue unit reads "Rescue unit: R22", the typed text for Other, and "Not recorded" for old checks', () => {
  assert.equal(logic.rescueUnitText(check()), 'R22');
  assert.equal(logic.rescueUnitLine(check()), 'Rescue unit: R22');
  assert.equal(logic.rescueUnitText(check({ rescue_unit: 'Rescue 9' })), 'Rescue 9');
  assert.equal(logic.rescueUnitLine(check({ rescue_unit: 'Rescue 9' })), 'Rescue unit: Rescue 9');
  // A check sent before units were asked for: the sheet says so, the list row stays quiet.
  assert.equal(logic.rescueUnitText(check({ rescue_unit: null })), 'Not recorded');
  assert.equal(logic.rescueUnitText(check({ rescue_unit: '  ' })), 'Not recorded');
  assert.equal(logic.rescueUnitLine(check({ rescue_unit: null })), null);
  assert.equal(logic.rescueUnitLine(check({ rescue_unit: '' })), null);
});

test('the seal number reads "Seal number: ZX-4471", and "Not recorded" for old checks', () => {
  assert.equal(logic.sealNumberText(check()), 'ZX-4471');
  assert.equal(logic.sealNumberLine(check()), 'Seal number: ZX-4471');
  assert.equal(logic.sealNumberText(check({ seal_number: ' 00481726 ' })), '00481726');
  assert.equal(logic.sealNumberText(check({ seal_number: null })), 'Not recorded');
  assert.equal(logic.sealNumberText(check({ seal_number: '  ' })), 'Not recorded');
  // The list row stays quiet on a check sent before seal numbers were asked for; the sheet says so.
  assert.equal(logic.sealNumberLine(check({ seal_number: null })), null);
  assert.equal(logic.sealNumberLine(check({ seal_number: '' })), null);
});

test('times are shown in Eastern time', () => {
  assert.match(logic.etDateTime('2026-10-30T13:00:00+00:00'), /9:00 AM EDT/);
  assert.equal(logic.etDateTime(null), 'Unknown time');
  assert.equal(logic.etDateTime('garbage'), 'Unknown time');
});

test('the explanation is optional and capped', () => {
  assert.equal(logic.rejectError(''), null);
  assert.equal(logic.rejectError('x'.repeat(logic.REJECT_NOTE_MAX)), null);
  assert.match(logic.rejectError('x'.repeat(logic.REJECT_NOTE_MAX + 1)), /500 characters/);
});

test('each status has its own tone and the member is named with rank', () => {
  assert.deepEqual([logic.STATUS_TONE.pending, logic.STATUS_TONE.approved, logic.STATUS_TONE.rejected], ['info', 'success', 'danger']);
  assert.equal(logic.memberText({ submitter_name: 'Pat Member', submitter_rank: 'Firefighter' }), 'Firefighter Pat Member');
  assert.equal(logic.memberText({ submitter_name: 'Pat Member', submitter_rank: null }), 'Pat Member');
  assert.equal(logic.ZOOM_LEVELS[0], 1);
});
