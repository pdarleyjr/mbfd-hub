import assert from 'node:assert/strict';
import test from 'node:test';
import {
  blankDraft, buildPayload, CANNOT_REVISE_MESSAGE, draftFromPayload, draftFromRequest, eventLabel, isCannotRevise, isUuid, lineKey, needsCorrection,
  normalizeQuantity, restoreDraft, retiredCount, retiredEntries, returnInfo, returnReason, reviseFromStored, reviseStart, reviseStoreKey,
  revisesFromSearch, revisionBanner, searchForView, serializeDraft, setQuantity, statusLabel, statusTone, viewFromSearch,
} from '../../../resources/js/operational-forms/ems/emsLogic.js';

const item = (item_key, label, extra = {}) => ({
  item_key, label, min_text: '2 EACH', min_qty: 2, min_unit: null, variant_group: null, variant_label: null,
  response_type: 'quantity', flags: {}, aliases: [], ...extra,
});

const definition = {
  forms: [
    {
      key: 'airway-bag', title: 'AIRWAY BAG INVENTORY', version_label: 'undated', printed_note: null,
      sections: [{ key: 'airway-top', label: 'Top Compartment', parent_key: null, items: [item('airway.top.gloves', 'Gloves'), item('airway.top.bvm', 'BVM')] }],
    },
    {
      key: 'als-med-bag', title: 'ALS MED BAG or BOX INVENTORY', version_label: '3/2023', printed_note: null,
      sections: [
        { key: 'als-trauma', label: 'Trauma', parent_key: null, items: [item('als.trauma.gauze', 'Gauze')] },
        { key: 'als-rescue', label: 'Rescue Only', parent_key: null, items: [item('als.rescue.narcs-box', 'NARCS BOX', { response_type: 'yes_no', min_text: 'Y / N' })] },
      ],
    },
  ],
  units: [
    { id: 7, kind: 'rescue_unit', label: 'Rescue 2', designation: 'R2', status: 'In Service', group: 'Rescue units' },
    { id: 9, kind: 'other_unit', label: 'Engine 1', designation: 'E1', status: 'In Service', group: 'Other units' },
    { id: 11, kind: 'other_unit', label: 'Engine 1', designation: 'E1B', status: 'In Service', group: 'Other units' },
    { id: null, kind: 'detail_event', label: 'Detail / Special Event', designation: null, status: null, group: 'Details' },
  ],
  limits: { max_quantity: 999, note_max: 2000, event_assignment_max: 160 },
  rules: { blank_is_not_zero: true, needs_item_or_note: true, controlled_substance_note: 'x' },
};

const GLOVES = lineKey('airway-bag', 'airway.top.gloves');
const GAUZE = lineKey('als-med-bag', 'als.trauma.gauze');
const NARCS = lineKey('als-med-bag', 'als.rescue.narcs-box');
const OWNER = '214';
const REQUEST_ID = '0c7c1f64-3b0e-4d61-9a4a-6f1d2b9e8a10';
const SENT_ID = '6f1e6b8a-1d2f-4c3a-9b1e-0d8c5a7e2f10';

const line = (extra) => ({
  id: 1, bag_key: 'airway-bag', item_key: 'airway.top.gloves', label: 'Gloves', section_label: null, variant_label: null, min_text: null, unit: null,
  response_type: 'quantity', requested_qty: 3, requested_flag: null, prepared_qty: null, issued_qty: 0, line_status: 'requested', line_status_label: 'Requested',
  not_issued_reason: null, refrigerated: false, controlled_substance: false, ...extra,
});

const returned = (extra = {}) => ({
  id: REQUEST_ID, number: 'MIR-000004', status: 'returned', status_label: 'Returned', submitted_at: '2026-10-30T14:00:00Z', target_kind: 'rescue_unit',
  target_label: 'Rescue 2', apparatus_id: 7, event_assignment: null, line_count: 3, has_note: true, last_activity_at: null, closed_at: null,
  can_revise: true, return_reason: 'Gauze count looks too high for one shift.', returned_at: '2026-10-31T13:00:00Z', returned_by: 'Captain Rivera',
  revision_number: 1, revision_of: null, replaced_by: null,
  member_note: 'Short after the Tuesday call.', lines: [
    line({ id: 1, requested_qty: 3 }),
    line({ id: 2, bag_key: 'als-med-bag', item_key: 'als.trauma.gauze', label: 'Gauze', requested_qty: 40 }),
    line({ id: 3, bag_key: 'als-med-bag', item_key: 'als.rescue.narcs-box', label: 'NARCS BOX', response_type: 'yes_no', requested_qty: null, requested_flag: false }),
  ],
  history: [], messages: [], close_reason: null, cancel_reason: null, files_copy: { status: 'pending' }, can_cancel: true,
  ...extra,
});

test('Fix and resend starts from the stored lines: same keys, same quantities, same Yes/No answers', () => {
  const draft = draftFromRequest(returned(), definition);
  assert.deepEqual(draft.quantities, { [GLOVES]: 3, [GAUZE]: 40 });
  assert.deepEqual(draft.answers, { [NARCS]: false }, 'a No answer comes back as No, not blank');
  assert.equal(draft.targetChoice, 'unit:7');
  assert.equal(draft.note, 'Short after the Tuesday call.');
  assert.equal(draft.revises, REQUEST_ID);
  assert.equal(draft.revisesNumber, 'MIR-000004');
  assert.equal(draft.returnReason, 'Gauze count looks too high for one shift.');
  assert.equal(retiredCount(draft), 0);
});

test('what was asked for is restored, not what was issued', () => {
  const draft = draftFromRequest(returned({ lines: [line({ requested_qty: 5, prepared_qty: 2, issued_qty: 1 })] }), definition);
  assert.equal(draft.quantities[GLOVES], 5);
});

test('a line with no quantity or answer is skipped and a Yes answer comes back as Yes', () => {
  const draft = draftFromRequest(returned({
    lines: [
      line({ requested_qty: null }),
      line({ id: 2, bag_key: 'als-med-bag', item_key: 'als.rescue.narcs-box', response_type: 'yes_no', requested_qty: null, requested_flag: true, label: 'NARCS BOX' }),
      line({ id: 3, bag_key: 'als-med-bag', item_key: 'als.trauma.gauze', response_type: 'yes_no', requested_qty: null, requested_flag: null, label: 'Gauze' }),
    ],
  }), definition);
  assert.deepEqual(draft.quantities, {});
  assert.deepEqual(draft.answers, { [NARCS]: true });
});

test('every correction gets a new client submission id', () => {
  const first = draftFromRequest(returned(), definition);
  const second = draftFromRequest(returned(), definition);
  assert.ok(isUuid(first.clientSubmissionId));
  assert.notEqual(first.clientSubmissionId, second.clientSubmissionId);
  assert.notEqual(first.clientSubmissionId, REQUEST_ID, 'never the id of the request it corrects');
  assert.equal(draftFromRequest(returned(), definition, SENT_ID).clientSubmissionId, SENT_ID);
});

test('the unit comes back from apparatus_id, Detail comes back with its event text, and a name is never guessed between units', () => {
  assert.equal(draftFromRequest(returned({ apparatus_id: 9, target_label: 'Engine 1' }), definition).targetChoice, 'unit:9');
  assert.equal(draftFromRequest(returned({ apparatus_id: 11, target_label: 'Engine 1' }), definition).targetChoice, 'unit:11');
  const detail = draftFromRequest(returned({ target_kind: 'detail_event', apparatus_id: null, target_label: 'Detail / Special Event', event_assignment: 'Boat show, Marine 1' }), definition);
  assert.equal(detail.targetChoice, 'detail');
  assert.equal(detail.eventAssignment, 'Boat show, Marine 1');
  assert.equal(draftFromRequest(returned({ apparatus_id: 7 }), definition).eventAssignment, '', 'event text is only for Detail');
  // No apparatus id from the server: one unit with exactly that name is fine, two are not.
  assert.equal(draftFromRequest(returned({ apparatus_id: undefined, target_label: 'Rescue 2' }), definition).targetChoice, 'unit:7');
  assert.equal(draftFromRequest(returned({ apparatus_id: undefined, target_label: 'Engine 1', target_kind: 'other_unit' }), definition).targetChoice, '');
  // A unit that is no longer offered leaves the choice empty rather than picking another.
  assert.equal(draftFromRequest(returned({ apparatus_id: 99 }), definition).targetChoice, '');
});

test('items no longer on the form are retired with their names, never dropped and never sent', () => {
  const request = returned({
    lines: [
      line({ requested_qty: 3 }),
      line({ id: 2, bag_key: 'airway-bag', item_key: 'airway.old.mask', label: 'Old Mask', requested_qty: 6 }),
      line({ id: 3, bag_key: 'retired-list', item_key: 'old.flag', label: 'Old Check', response_type: 'yes_no', requested_qty: null, requested_flag: true }),
      line({ id: 4, bag_key: null, item_key: 'orphan.item', label: 'Orphan', requested_qty: 2 }),
    ],
  });
  const draft = draftFromRequest(request, definition);
  assert.deepEqual(draft.quantities, { [GLOVES]: 3 });
  assert.equal(retiredCount(draft), 3);
  assert.deepEqual(draft.retired.quantities, { 'airway-bag|airway.old.mask': 6, '|orphan.item': 2 });
  assert.deepEqual(draft.retired.answers, { 'retired-list|old.flag': true });
  assert.deepEqual(retiredEntries(draft).map((entry) => entry.label).sort(), ['Old Check', 'Old Mask', 'Orphan']);
  const payload = buildPayload(draft, definition);
  assert.deepEqual(payload.lines, [{ form: 'airway-bag', item_key: 'airway.top.gloves', quantity: 3 }], 'retired entries are not part of the payload');
});

test('a retired entry returns to the form when the item is listed again (merge by key)', () => {
  const request = returned({ lines: [line({ bag_key: 'airway-bag', item_key: 'airway.later.item', label: 'Later Item', requested_qty: 4 })] });
  const draft = draftFromRequest(request, definition);
  const stored = serializeDraft(draft, '2026-11-01T12:00:00.000Z', definition);
  const later = structuredClone(definition);
  later.forms[0].sections[0].items.push(item('airway.later.item', 'Later Item'));
  const back = restoreDraft(stored, later);
  assert.equal(back.quantities['airway-bag|airway.later.item'], 4);
  assert.equal(retiredCount(back), 0);
  assert.equal(back.revises, REQUEST_ID, 'still a correction');
});

test('the payload carries revises with the new submission id; an ordinary request carries neither', () => {
  const draft = draftFromRequest(returned(), definition);
  const payload = buildPayload(setQuantity(draft, GLOVES, 4), definition);
  assert.equal(payload.revises, REQUEST_ID);
  assert.equal(payload.client_submission_id, draft.clientSubmissionId);
  assert.deepEqual(payload.target, { kind: 'rescue_unit', apparatus_id: 7, label_seen: 'Rescue 2' });
  assert.equal(payload.member_note, 'Short after the Tuesday call.');
  assert.deepEqual(payload.lines.map((entry) => [entry.form, entry.item_key, entry.quantity ?? entry.flag]), [
    ['airway-bag', 'airway.top.gloves', 4], ['als-med-bag', 'als.trauma.gauze', 40], ['als-med-bag', 'als.rescue.narcs-box', false],
  ]);

  const plain = buildPayload({ ...blankDraft(SENT_ID), targetChoice: 'unit:7', quantities: { [GLOVES]: 1 } }, definition);
  assert.equal('revises' in plain, false);
  // A damaged value is never sent as the thing to revise.
  assert.equal('revises' in buildPayload({ ...blankDraft(SENT_ID), targetChoice: 'unit:7', quantities: { [GLOVES]: 1 }, revises: 'not-a-uuid' }, definition), false);
});

test('a correction is stored in the same version-1 shape plus optional fields, and a plain draft gains nothing', () => {
  const draft = setQuantity(draftFromRequest(returned(), definition), GLOVES, 9);
  const stored = serializeDraft(draft, '2026-11-01T12:00:00.000Z', definition);
  assert.equal(stored.v, 1);
  assert.equal(stored.revises, REQUEST_ID);
  assert.equal(stored.revisesNumber, 'MIR-000004');
  assert.equal(stored.returnReason, 'Gauze count looks too high for one shift.');
  assert.equal(stored.clientSubmissionId, draft.clientSubmissionId);
  assert.equal('note' in stored, false, 'the note is not kept on the device');
  assert.equal('eventAssignment' in stored, false, 'neither is the event text');
  const back = restoreDraft(stored, definition);
  assert.equal(back.clientSubmissionId, draft.clientSubmissionId, 'the same submission id after a restart, so a retry replays once');
  assert.equal(back.quantities[GLOVES], 9);
  assert.equal(back.revises, REQUEST_ID);

  const plain = serializeDraft({ ...blankDraft(SENT_ID), targetChoice: 'unit:7', quantities: { [GLOVES]: 1 } }, '2026-11-01T12:00:00.000Z', definition);
  assert.equal('revises' in plain, false);
  assert.equal('revisesNumber' in plain, false);
  assert.equal('returnReason' in plain, false);
});

test('an emptied correction is still stored, so reopening it does not bring the sent copy back over the edit', () => {
  const emptied = { ...blankDraft(SENT_ID), revises: REQUEST_ID, revisesNumber: 'MIR-000004', returnReason: 'x' };
  assert.notEqual(serializeDraft(emptied, undefined, definition), null);
  assert.equal(serializeDraft(blankDraft(SENT_ID), undefined, definition), null, 'an empty ordinary draft is still not stored');
});

test('a stored row with a damaged revises is read as an ordinary draft', () => {
  const stored = { v: 1, clientSubmissionId: SENT_ID, targetChoice: 'unit:7', quantities: { [GLOVES]: 2 }, answers: {}, revises: 'nope', savedAt: 'x' };
  const back = restoreDraft(stored, definition);
  assert.equal(back.revises, undefined);
  assert.equal(back.quantities[GLOVES], 2);
});

test('the correction lives under its own key, never the normal draft key', () => {
  assert.equal(reviseStoreKey(OWNER, REQUEST_ID), `214:revise:${REQUEST_ID}`);
  assert.notEqual(reviseStoreKey(OWNER, REQUEST_ID), OWNER);
  assert.notEqual(reviseStoreKey(OWNER, REQUEST_ID), reviseStoreKey(OWNER, SENT_ID));
  assert.notEqual(reviseStoreKey('21', `4:revise:${REQUEST_ID}`), reviseStoreKey('214', REQUEST_ID));
});

test('reopening resumes the correction in progress, not the server copy', () => {
  const started = draftFromRequest(returned(), definition);
  const edited = setQuantity(started, GAUZE, 12);
  const stored = serializeDraft(edited, '2026-11-01T12:00:00.000Z', definition);
  const reopened = reviseStart(returned(), stored, definition);
  assert.equal(reopened.resumed, true);
  assert.equal(reopened.draft.quantities[GAUZE], 12, 'the member edit, not the 40 that was sent');
  assert.equal(reopened.draft.clientSubmissionId, started.clientSubmissionId, 'the same new submission id');
  assert.equal(reopened.draft.note, 'Short after the Tuesday call.', 'note comes from the sent copy: it is not kept on the device');
  assert.equal(reopened.draft.revises, REQUEST_ID);

  const fresh = reviseStart(returned(), null, definition);
  assert.equal(fresh.resumed, false);
  assert.equal(fresh.draft.quantities[GAUZE], 40);
});

test('a stored correction for a different request is not used', () => {
  const other = draftFromRequest(returned({ id: SENT_ID, number: 'MIR-000009' }), definition);
  const stored = serializeDraft(setQuantity(other, GAUZE, 1), undefined, definition);
  const start = reviseStart(returned(), stored, definition);
  assert.equal(start.resumed, false);
  assert.equal(start.draft.revises, REQUEST_ID);
  assert.equal(start.draft.quantities[GAUZE], 40);
  assert.equal(reviseFromStored(stored, definition, REQUEST_ID), null);
  assert.equal(reviseFromStored(stored, definition, SENT_ID).revises, SENT_ID);
  assert.equal(reviseFromStored(null, definition, REQUEST_ID), null);
});

test('a normal unfinished request is not read as a correction, and the ordinary restore never carries revises', () => {
  const normal = serializeDraft({ ...blankDraft(SENT_ID), targetChoice: 'unit:9', quantities: { [GLOVES]: 2 } }, undefined, definition);
  assert.equal(reviseStart(returned(), normal, definition).resumed, false);
  assert.equal(restoreDraft(normal, definition).revises, undefined);
});

test('a queued correction can be edited again with its revises and its banner details', () => {
  const draft = setQuantity(draftFromRequest(returned(), definition), GLOVES, 8);
  const payload = buildPayload(draft, definition);
  const again = draftFromPayload(payload, definition, { number: 'MIR-000004', reason: 'Gauze count looks too high for one shift.' });
  assert.equal(again.revises, REQUEST_ID);
  assert.equal(again.revisesNumber, 'MIR-000004');
  assert.equal(again.clientSubmissionId, payload.client_submission_id, 'the same id, so the server still replays it once');
  assert.equal(again.quantities[GLOVES], 8);
  assert.equal(draftFromPayload(buildPayload({ ...blankDraft(SENT_ID), targetChoice: 'unit:7', quantities: { [GLOVES]: 1 } }, definition), definition).revises, undefined);
  assert.equal(draftFromPayload(payload, definition).revisesNumber, '', 'without queue details the banner falls back to plain wording');
});

test('the banner names the form and what Captain 5 said, and says something sensible without either', () => {
  assert.deepEqual(revisionBanner(draftFromRequest(returned(), definition)), {
    title: 'Correcting MIR-000004.', reason: 'Captain 5 said: Gauze count looks too high for one shift.',
  });
  assert.deepEqual(revisionBanner({ ...blankDraft(SENT_ID), revises: REQUEST_ID }), { title: 'Correcting an earlier form.', reason: 'Captain 5 sent it back.' });
  assert.equal(revisionBanner(blankDraft(SENT_ID)), null);
});

test('the address of a correction survives a refresh, and a damaged one is ignored', () => {
  const view = { name: 'form', revises: REQUEST_ID };
  assert.equal(searchForView(view), `?form=medical-inventory-request&revises=${REQUEST_ID}`);
  assert.deepEqual(viewFromSearch(searchForView(view)), view);
  assert.equal(revisesFromSearch(`?form=medical-inventory-request&revises=${REQUEST_ID.toUpperCase()}`), REQUEST_ID);
  assert.equal(revisesFromSearch('?form=medical-inventory-request&revises=nope'), null);
  assert.equal(revisesFromSearch(''), null);
  assert.deepEqual(viewFromSearch('?form=medical-inventory-request&revises=nope'), { name: 'form' });
  assert.equal(searchForView({ name: 'form' }), '?form=medical-inventory-request');
});

test('statuses: a returned request asks for attention; reconciled and superseded use the server label', () => {
  assert.equal(statusLabel('returned'), 'Returned');
  assert.equal(statusTone('returned'), 'attention');
  assert.equal(statusLabel('reconciled', 'Reconciled'), 'Reconciled');
  assert.equal(statusLabel('superseded', 'Replaced by a correction'), 'Replaced by a correction');
  assert.equal(statusLabel('superseded'), 'superseded', 'no label at all falls back to the status');
  assert.equal(statusTone('reconciled'), 'done');
  assert.equal(statusTone('superseded'), 'muted');
  assert.equal(statusLabel('submitted', 'Something else'), 'Submitted', 'a status this build knows keeps its plain wording');
  assert.deepEqual(['rejected', 'reconciled', 'revised'].map(eventLabel), ['Returned for correction', 'Reconciled', 'Corrected and resent']);
});

test('only a request the server says can be revised offers Fix and resend', () => {
  assert.equal(needsCorrection(returned()), true);
  assert.equal(needsCorrection(returned({ can_revise: false })), false);
  assert.equal(needsCorrection(returned({ can_revise: undefined })), false);
  assert.equal(needsCorrection(returned({ status: 'submitted', can_revise: false })), false);
});

test('the reason, who and when come from the fields, else from the rejected history entry', () => {
  assert.equal(returnReason(returned()), 'Gauze count looks too high for one shift.');
  assert.deepEqual(returnInfo(returned()), { by: 'Captain Rivera', at: '2026-10-31T13:00:00Z' });
  const history = [
    { action: 'submitted', actor_name: 'You', payload: {}, created_at: '2026-10-30T14:00:00Z' },
    { action: 'rejected', actor_name: 'Captain Dana Ortiz', payload: { reason: 'Wrong unit chosen.' }, created_at: '2026-10-31T15:30:00Z' },
  ];
  const bare = returned({ return_reason: null, returned_at: null, returned_by: null, history });
  assert.equal(returnReason(bare), 'Wrong unit chosen.');
  assert.deepEqual(returnInfo(bare), { by: 'Captain Dana Ortiz', at: '2026-10-31T15:30:00Z' });
  assert.equal(returnReason(returned({ return_reason: '   ' })), '');
});

test('a refused correction is recognized by its code and has a plain message that says nothing was lost', () => {
  assert.equal(isCannotRevise({ status: 409, code: 'cannot_revise' }), true);
  assert.equal(isCannotRevise({ status: 409, code: 'duplicate_submission' }), false);
  assert.equal(isCannotRevise({ status: 409, code: null }), false);
  assert.match(CANNOT_REVISE_MESSAGE, /already corrected or cancelled/);
  assert.match(CANNOT_REVISE_MESSAGE, /still here/);
  assert.equal(/[–—]/.test(CANNOT_REVISE_MESSAGE), false, 'no em dash in text members read');
});

test('quantities in a correction follow the same rules as any draft', () => {
  const draft = draftFromRequest(returned({ lines: [line({ requested_qty: 5000 }), line({ id: 2, bag_key: 'als-med-bag', item_key: 'als.trauma.gauze', label: 'Gauze', requested_qty: 0 })] }), definition);
  assert.equal(draft.quantities[GLOVES], normalizeQuantity(5000, 999));
  assert.equal(GAUZE in draft.quantities, false, 'a zero is never kept');
});
