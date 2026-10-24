import assert from 'node:assert/strict';
import test from 'node:test';
import {
  blankDraft, buildPayload, bumpQuantity, choiceForUnit, classifyFailure, countRequested, draftFromPayload,
  eventDetail, fieldForError, filterForms, flagTags, formatEastern, groupSections, hasContent, isDocumentRecordType,
  isUuid, itemsLabel, lineKey, minLabel, newSubmissionId, normalizeQuantity, payloadItemCount, readiness,
  recordTypeLabel, requestedInSections, restoreDraft, reviewGroups, formName, searchForView, serializeDraft, setAnswer, setQuantity,
  statusLabel, variantCaption, viewFromDeepLink, viewFromSearch,
} from '../../../resources/js/operational-forms/ems/emsLogic.js';

const item = (item_key, label, extra = {}) => ({
  item_key, label, min_text: '2 EACH', min_qty: 2, min_unit: null, variant_group: null, variant_label: null,
  response_type: 'quantity', flags: {}, aliases: [], ...extra,
});

const definition = {
  forms: [
    {
      key: 'airway-bag', title: 'AIRWAY BAG INVENTORY', version_label: 'undated', printed_note: null,
      sections: [
        { key: 'airway-top', label: 'Top Compartment', parent_key: null, items: [item('airway.top.gloves', 'Gloves', { min_text: '3 SETS', aliases: ['Exam gloves'] }), item('airway.top.bvm', 'BVM')] },
        { key: 'airway-main', label: 'Main Compartment', parent_key: null, items: [item('airway.main.mask', 'Mask')] },
        { key: 'airway-bvm', label: 'BVM Bag Set Up', parent_key: 'airway-main', items: [
          item('airway.bvm.igel-3', 'i-gel Size 3', { variant_group: 'Igel Size 3,4,5', variant_label: 'Size 3' }),
          item('airway.bvm.igel-4', 'i-gel Size 4', { variant_group: 'Igel Size 3,4,5', variant_label: 'Size 4' }),
        ] },
      ],
    },
    {
      key: 'als-med-bag', title: 'ALS MED BAG or BOX INVENTORY', version_label: '3/2023', printed_note: 'Totals can include amounts in Bobo Bag.',
      sections: [
        { key: 'als-trauma', label: 'Trauma', parent_key: null, items: [item('als.trauma.gauze', 'Gauze')] },
        { key: 'als-misc', label: 'Miscellaneous', parent_key: null, items: [item('als.misc.cardizem', 'Cardizem', { flags: { refrigerated: true } })] },
        { key: 'als-rescue', label: 'Rescue Only', parent_key: 'als-misc', items: [
          item('als.rescue.narcs-box', 'NARCS BOX', { response_type: 'yes_no', min_text: 'Y / N', flags: { rescue_only: true, controlled_substance: true } }),
        ] },
      ],
    },
  ],
  units: [
    { id: 7, kind: 'rescue_unit', label: 'Rescue 2', designation: 'R2', status: 'In Service', group: 'Rescue units' },
    { id: 9, kind: 'other_unit', label: 'Engine 1', designation: 'E1', status: 'In Service', group: 'Other units' },
    { id: null, kind: 'detail_event', label: 'Detail / Special Event', designation: null, status: null, group: 'Details' },
  ],
  limits: { max_quantity: 999, note_max: 2000, event_assignment_max: 160 },
  rules: { blank_is_not_zero: true, needs_item_or_note: true, controlled_substance_note: 'x' },
};

const GLOVES = lineKey('airway-bag', 'airway.top.gloves');
const GAUZE = lineKey('als-med-bag', 'als.trauma.gauze');
const NARCS = lineKey('als-med-bag', 'als.rescue.narcs-box');

test('quantities are whole numbers from 1 to the maximum and blank is never 0', () => {
  assert.equal(normalizeQuantity(''), null);
  assert.equal(normalizeQuantity(null), null);
  assert.equal(normalizeQuantity(undefined), null);
  assert.equal(normalizeQuantity('0'), null);
  assert.equal(normalizeQuantity(0), null);
  assert.equal(normalizeQuantity('abc'), null);
  assert.equal(normalizeQuantity('-3'), 3, 'a stray minus sign is dropped, not turned into a negative');
  assert.equal(normalizeQuantity(' 12 '), 12);
  assert.equal(normalizeQuantity('2.5'), 25, 'digits only');
  assert.equal(normalizeQuantity('5000', 999), 999);
});

test('steppers start at 1 from blank and return to blank below 1', () => {
  let draft = blankDraft('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
  draft = bumpQuantity(draft, GLOVES, -1);
  assert.deepEqual(draft.quantities, {}, 'minus on a blank item stays blank');
  draft = bumpQuantity(draft, GLOVES, 1);
  assert.equal(draft.quantities[GLOVES], 1);
  draft = bumpQuantity(draft, GLOVES, 1);
  assert.equal(draft.quantities[GLOVES], 2);
  draft = bumpQuantity(draft, GLOVES, -2);
  assert.equal(GLOVES in draft.quantities, false);
});

test('draft updates are immutable', () => {
  const before = blankDraft('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
  const after = setQuantity(before, GLOVES, 3);
  assert.notEqual(before, after);
  assert.deepEqual(before.quantities, {});
  assert.equal(after.quantities[GLOVES], 3);
});

test('summary counts: quantities and Yes answers are requests, No is an answer but not a request', () => {
  let draft = blankDraft();
  assert.equal(countRequested(draft), 0);
  draft = setQuantity(draft, GLOVES, 2);
  draft = setQuantity(draft, GAUZE, 5);
  draft = setAnswer(draft, NARCS, true);
  assert.equal(countRequested(draft), 3);
  draft = setAnswer(draft, NARCS, false);
  assert.equal(countRequested(draft), 2);
  assert.equal(hasContent(draft), true);
  assert.equal(itemsLabel(1), '1 item');
  assert.equal(itemsLabel(2), '2 items');
  assert.equal(itemsLabel(0), '0 items');

  const airway = definition.forms[0];
  assert.equal(requestedInSections(draft, 'airway-bag', airway.sections), 1);
});

test('readiness explains what is missing instead of failing after the fact', () => {
  const empty = blankDraft();
  assert.deepEqual([readiness(empty).needsTarget, readiness(empty).needsContent, readiness(empty).ready], [true, true, false]);
  const withItem = { ...setQuantity(empty, GLOVES, 1) };
  assert.equal(readiness(withItem).message, 'Choose who this is for to send.');
  const withUnit = { ...empty, targetChoice: 'unit:7' };
  assert.equal(readiness(withUnit).message, 'Add an item or a note to send.');
  assert.equal(readiness({ ...withUnit, note: ' need to talk ' }).ready, true, 'a note alone is enough');
  assert.equal(readiness({ ...withItem, targetChoice: 'detail' }).ready, true);
});

test('the payload lists only touched items, never a 0, and never identity or time', () => {
  let draft = { ...blankDraft('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), targetChoice: 'unit:7' };
  draft = setQuantity(draft, GLOVES, 2);
  draft = setQuantity(draft, lineKey('airway-bag', 'airway.top.bvm'), '0');
  draft = setQuantity(draft, GAUZE, '');
  draft = setAnswer(draft, NARCS, true);

  const payload = buildPayload(draft, definition);
  assert.deepEqual(payload, {
    client_submission_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    target: { kind: 'rescue_unit', apparatus_id: 7 },
    lines: [
      { form: 'airway-bag', item_key: 'airway.top.gloves', quantity: 2 },
      { form: 'als-med-bag', item_key: 'als.rescue.narcs-box', flag: true },
    ],
  });
  const text = JSON.stringify(payload);
  for (const forbidden of ['submitted_by', 'submitter', 'user_id', 'employee', 'submitted_at', '"quantity":0']) {
    assert.equal(text.includes(forbidden), false, `${forbidden} must not be sent`);
  }
  assert.equal(payloadItemCount(payload), 2);
});

test('a Detail / Special Event payload carries the optional event text only when typed', () => {
  let draft = { ...blankDraft(), targetChoice: 'detail' };
  draft = setQuantity(draft, GAUZE, 1);
  assert.deepEqual(buildPayload(draft, definition).target, { kind: 'detail_event' });
  assert.deepEqual(buildPayload({ ...draft, eventAssignment: '   ' }, definition).target, { kind: 'detail_event' });
  assert.deepEqual(buildPayload({ ...draft, eventAssignment: ' Air show, medic 4 ' }, definition).target, { kind: 'detail_event', event_assignment: 'Air show, medic 4' });
  // Event text typed before switching back to a unit is not sent.
  assert.deepEqual(buildPayload({ ...draft, targetChoice: 'unit:9', eventAssignment: 'Air show' }, definition).target, { kind: 'other_unit', apparatus_id: 9 });
});

test('a note-only request is a valid payload and a No answer is sent as a flag of false', () => {
  const noteOnly = buildPayload({ ...blankDraft(), targetChoice: 'unit:7', note: '  Jump bag restock  ' }, definition);
  assert.deepEqual(noteOnly.lines, []);
  assert.equal(noteOnly.member_note, 'Jump bag restock');
  const no = buildPayload(setAnswer({ ...blankDraft(), targetChoice: 'unit:7' }, NARCS, false), definition);
  assert.deepEqual(no.lines, [{ form: 'als-med-bag', item_key: 'als.rescue.narcs-box', flag: false }]);
  assert.equal(payloadItemCount(no), 0);
  assert.equal('member_note' in buildPayload({ ...blankDraft(), targetChoice: 'unit:7', note: '   ' }, definition), false);
});

test('building a payload without a unit fails loudly instead of sending a guess', () => {
  assert.throws(() => buildPayload(setQuantity(blankDraft(), GLOVES, 1), definition), /Choose a unit/);
});

test('the stored draft holds quantities, answers, the unit choice and the submission id only', () => {
  let draft = { ...blankDraft('cccccccc-cccc-4ccc-8ccc-cccccccccccc'), targetChoice: 'unit:7', note: 'Pat Medic asked for this', eventAssignment: 'Lt. Smith, air show' };
  draft = setQuantity(draft, GLOVES, 4);
  draft = setAnswer(draft, NARCS, false);
  const stored = serializeDraft(draft, '2026-10-23T12:00:00.000Z');
  assert.deepEqual(Object.keys(stored).sort(), ['answers', 'clientSubmissionId', 'quantities', 'savedAt', 'targetChoice', 'v']);
  assert.deepEqual(stored.quantities, { [GLOVES]: 4 });
  const text = JSON.stringify(stored);
  assert.equal(text.includes('Pat Medic'), false);
  assert.equal(text.includes('Smith'), false);
  assert.equal(serializeDraft(blankDraft()), null, 'an untouched draft stores nothing');
  assert.equal(JSON.stringify(serializeDraft(setQuantity(blankDraft(), GLOVES, '0'))), 'null', 'a typed 0 stores nothing');
});

test('restoring keeps only what the current form still has and drops junk', () => {
  const stored = {
    v: 1, clientSubmissionId: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', targetChoice: 'unit:7', savedAt: 'x',
    quantities: { [GLOVES]: 3, 'airway-bag|gone.item': 2, [GAUZE]: 0, [lineKey('als-med-bag', 'als.misc.cardizem')]: 99999 },
    answers: { [NARCS]: true, 'als-med-bag|gone': true },
  };
  const draft = restoreDraft(stored, definition);
  assert.equal(draft.clientSubmissionId, stored.clientSubmissionId, 'the same submission id is reused');
  assert.deepEqual(draft.quantities, { [GLOVES]: 3, [lineKey('als-med-bag', 'als.misc.cardizem')]: 999 });
  assert.deepEqual(draft.answers, { [NARCS]: true });
  assert.equal(draft.targetChoice, 'unit:7');
  assert.equal(restoreDraft({ ...stored, targetChoice: 'unit:404' }, definition).targetChoice, '', 'a unit that is gone is cleared');
  assert.equal(restoreDraft({ ...stored, v: 2 }, definition), null);
  assert.equal(restoreDraft({ ...stored, clientSubmissionId: 'not-a-uuid' }, definition), null);
  assert.equal(restoreDraft(null, definition), null);
});

test('a payload that could not be sent turns back into an editable draft with the same submission id', () => {
  let draft = { ...blankDraft('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'), targetChoice: 'detail', eventAssignment: 'Marathon', note: 'n' };
  draft = setQuantity(draft, GLOVES, 2);
  draft = setAnswer(draft, NARCS, true);
  const back = draftFromPayload(buildPayload(draft, definition), definition);
  assert.deepEqual(back, draft);
  assert.equal(choiceForUnit(definition.units[0]), 'unit:7');
  assert.equal(choiceForUnit(definition.units[2]), 'detail');
});

test('search filters across both forms by label, alias, variant, section and minimum', () => {
  assert.equal(filterForms(definition.forms, '').matches, 8);
  assert.deepEqual(filterForms(definition.forms, 'gauze').forms.map((f) => f.key), ['als-med-bag']);
  assert.equal(filterForms(definition.forms, 'exam gloves').matches, 1, 'aliases match');
  assert.equal(filterForms(definition.forms, 'size 4').matches, 1);
  assert.equal(filterForms(definition.forms, 'trauma').matches, 1, 'section name keeps its items');
  assert.equal(filterForms(definition.forms, '3 sets').matches, 1);
  assert.equal(filterForms(definition.forms, 'zzz').matches, 0);
  assert.equal(filterForms(definition.forms, '  GLOVES ').matches, 1, 'case and spacing do not matter');
  assert.deepEqual(filterForms(definition.forms, 'i-gel').forms[0].sections.map((s) => s.key), ['airway-bvm']);
});

test('sub-sections nest under their parent and stay in order', () => {
  const grouped = groupSections(definition.forms[0].sections);
  assert.deepEqual(grouped.map((g) => g.section.key), ['airway-top', 'airway-main']);
  assert.deepEqual(grouped[1].children.map((c) => c.key), ['airway-bvm']);
  const orphan = groupSections([{ key: 'a', label: 'A', parent_key: 'missing', items: [] }]);
  assert.deepEqual(orphan.map((g) => g.section.key), ['a'], 'a sub-section with no parent on the form stands alone');
});

test('the review lists only what will be sent, grouped by bag, with Yes/No answers as answers', () => {
  let draft = { ...blankDraft(), targetChoice: 'unit:7' };
  draft = setQuantity(draft, GAUZE, 5);
  draft = setQuantity(draft, GLOVES, 2);
  draft = setAnswer(draft, NARCS, false);
  const groups = reviewGroups(draft, definition);
  assert.deepEqual(groups.map((g) => [g.name, g.rows.map((r) => `${r.label}:${r.detail}`)]), [
    ['Airway Bag', ['Gloves:2']],
    ['ALS Med Bag/Box', ['Gauze:5', 'NARCS BOX:No']],
  ]);
  assert.deepEqual(reviewGroups(blankDraft(), definition), []);
  assert.equal(formName(definition.forms[0]), 'Airway Bag');
  assert.equal(formName({ key: 'other', title: 'Other form', sections: [] }), 'Other form');
});

test('source text is shown as the paper form gives it, with flags as tags', () => {
  const [gloves] = definition.forms[0].sections[0].items;
  assert.equal(minLabel(gloves), 'Min 3 SETS');
  assert.equal(minLabel(definition.forms[1].sections[2].items[0]), '', 'Yes/No items show no minimum');
  assert.equal(minLabel(item('x', 'X', { min_text: null })), '');
  assert.deepEqual(flagTags({ rescue_only: true, controlled_substance: true, refrigerated: false }), ['Rescue only', 'Controlled']);
  assert.deepEqual(flagTags({ detail_airway_bag_only: true, refrigerated: true }), ['Detail airway bags only', 'Refrigerated']);
  assert.deepEqual(flagTags(undefined), []);
  const items = definition.forms[0].sections[2].items;
  assert.equal(variantCaption(items, 0), 'Igel Size 3,4,5');
  assert.equal(variantCaption(items, 1), '', 'the caption shows once per run');
});

test('submission ids are uuids, from crypto.randomUUID or the fallback', () => {
  assert.equal(isUuid(newSubmissionId()), true);
  assert.equal(isUuid(newSubmissionId({ randomUUID: undefined, getRandomValues: (a) => a.fill(7) })), true);
  assert.equal(isUuid(newSubmissionId({})), true);
  assert.notEqual(newSubmissionId(), newSubmissionId());
});

test('status labels are plain language and Eastern time is used for submitted times', () => {
  assert.deepEqual(['submitted', 'reviewed', 'prepared', 'partially_issued', 'issued', 'closed', 'cancelled'].map(statusLabel),
    ['Submitted', 'Reviewed', 'Being prepared', 'Partly issued', 'Issued', 'Closed', 'Cancelled']);
  assert.match(formatEastern('2026-10-23T20:05:00Z'), /Oct 23, 2026.*4:05\s?PM EDT/);
  assert.match(formatEastern('2026-01-15T17:05:00Z'), /Jan 15, 2026.*12:05\s?PM EST/);
  assert.equal(formatEastern(null), '');
  assert.equal(formatEastern('garbage'), '');
  assert.equal(eventDetail({ label: 'Gauze', quantity: 4, reason: 'Short on stock' }), 'Gauze: 4. Reason: Short on stock');
  assert.equal(eventDetail({}), '');
});

test('deep links open the right view and survive a round trip', () => {
  const id = '0f8fad5b-d9cb-469f-a165-70867728950e';
  assert.deepEqual(viewFromSearch(`?ems_request=${id}`), { name: 'detail', id });
  assert.deepEqual(viewFromSearch(`?ems_request=${id.toUpperCase()}`), { name: 'detail', id });
  assert.deepEqual(viewFromSearch('?form=medical-inventory-request'), { name: 'form' });
  assert.deepEqual(viewFromSearch('?form=medical-inventory-request&view=mine'), { name: 'mine' });
  assert.equal(viewFromSearch('?form=something-else'), null);
  assert.equal(viewFromSearch('?ems_request=not-a-uuid'), null);
  assert.equal(viewFromSearch(''), null);
  for (const view of [{ name: 'form' }, { name: 'mine' }, { name: 'detail', id }]) {
    assert.deepEqual(viewFromSearch(searchForView(view)), view);
  }
  assert.equal(searchForView(null), '');
  assert.deepEqual(viewFromDeepLink({ view: 'detail', request: id }), { name: 'detail', id });
  assert.equal(viewFromDeepLink({ view: 'detail', request: 'nope' }), null);
  assert.deepEqual(viewFromDeepLink({ view: 'mine', request: null }), { name: 'mine' });
  assert.equal(viewFromDeepLink({ view: null, request: null }), null);
});

test('failed sends are queued, sent to sign-in, or shown with the server message', () => {
  assert.equal(classifyFailure({ status: 0, network: true }), 'queue');
  assert.equal(classifyFailure({ status: 503 }), 'queue');
  assert.equal(classifyFailure({ status: 429 }), 'queue');
  assert.equal(classifyFailure({ status: 401 }), 'auth');
  assert.equal(classifyFailure({ status: 419 }), 'auth');
  assert.equal(classifyFailure({ status: 422, code: 'empty_request' }), 'message');
  assert.equal(classifyFailure({ status: 503, code: 'forms_unavailable' }), 'message', 'a known refusal is shown, not retried forever');
  assert.equal(classifyFailure({ status: 409, code: 'duplicate_submission' }), 'message');
});

test('server refusals are placed next to the control they are about', () => {
  assert.equal(fieldForError('invalid_unit', { 'target.apparatus_id': ['x'] }), 'target');
  assert.equal(fieldForError('validation_failed', { target: ['x'] }), 'target');
  assert.equal(fieldForError('validation_failed', { 'target.event_assignment': ['x'] }), 'event');
  assert.equal(fieldForError('validation_failed', { member_note: ['x'] }), 'note');
  assert.equal(fieldForError('empty_request', { lines: ['x'] }), 'items');
  assert.equal(fieldForError('invalid_quantity', {}), 'items');
  assert.equal(fieldForError('forms_unavailable', {}), 'general');
  assert.equal(fieldForError(null, null), 'general');
});

test('Medical Inventory Request records get a proper label and open as a read-only PDF', () => {
  assert.equal(recordTypeLabel('medical_inventory_request'), 'Medical Inventory Request');
  assert.equal(recordTypeLabel('uploaded_file'), 'Submitted file');
  assert.equal(recordTypeLabel('ics_214'), '');
  assert.equal(isDocumentRecordType('medical_inventory_request'), true);
  assert.equal(isDocumentRecordType('uploaded_file'), true);
  assert.equal(isDocumentRecordType('ics_214'), false);
  assert.equal(isDocumentRecordType('froc_log_001_ff'), false);
});
