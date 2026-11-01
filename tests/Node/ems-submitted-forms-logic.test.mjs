import assert from 'node:assert/strict';
import test from 'node:test';

// Plain TypeScript with type-only imports between files, so Node loads it directly (same approach as ems-captain5-logic.test.mjs).
const logic = await import('../../resources/js/daily-checkout/src/components/captain5/ems/forms/logic.ts');

const form = (overrides = {}) => ({
  id: 'f1', number: 'MIR-000101', status: 'submitted', status_label: 'Submitted', submitted_at: '2026-10-30T18:05:00Z', submitter_name: 'Pat Medic', submitter_rank: 'Paramedic',
  target_kind: 'rescue_unit', target_label: 'Rescue 2', apparatus_id: 2, event_assignment: null, line_count: 3, issued_line_count: 0, has_note: false, last_activity_at: null, closed_at: null,
  acknowledged: false, acknowledged_at: null, acknowledged_by: null, can_reconcile: false, can_reject: true, can_acknowledge: true, return_reason: null, returned_at: null,
  reconciled_at: null, reconciled_by: null, revision_number: 1, items_preview: [{ label: 'Gauze 4x4', quantity: 10, unit: null }], quantity_total: 10, ...overrides,
});

const master = (id, overrides = {}) => ({
  id, name: `Item ${id}`, aliases: [], location_code: 'A2', location_label: 'Shelf A2', base_unit: 'each', count_unit: 'each', units_per_count_unit: 1,
  package_unit: null, units_per_package: null, on_hand_base: 20, counted_at: '2026-10-26T14:00:00Z', ...overrides,
});

const line = (id, overrides = {}) => ({
  line_id: id, request_id: 'f1', number: 'MIR-000101', target_label: 'Rescue 2', label: `Line ${id}`, variant_label: null, requested_qty: 5, unit: null, quantity: 5, quantity_base: 5,
  state: 'ready', message: null, item: { id: 71, name: 'Gauze 4x4', location_code: 'A2', count_unit: 'each' }, ...overrides,
});

const preview = (lines, totals = []) => ({ last_movement_id: 9, blocked: false, forms: [], lines, totals });
const total = (id, overrides = {}) => ({ supply_item_id: id, name: `Item ${id}`, location_code: 'A2', count_unit: 'each', units_per_count_unit: 1, deduct_base: 5, before_base: 20, after_base: 15, low: false, not_counted: false, ...overrides });

// ------------------------------------------------------------------ card wording

test('acknowledgment states read as plain sentences', () => {
  assert.equal(logic.ackText(form()), 'Waiting for acknowledgment');
  const acknowledged = logic.ackText(form({ acknowledged: true, acknowledged_by: 'Capt. Rivera', acknowledged_at: '2026-10-30T18:30:00Z' }));
  assert.match(acknowledged, /^Acknowledged by Capt\. Rivera, Oct 30, 2:30 PM EDT$/);
  assert.equal(logic.ackText(form({ acknowledged: true })), 'Acknowledged');
  assert.equal(logic.returnedText(form({ status: 'returned', return_reason: 'Count the tape again' })), 'Returned to Pat Medic: Count the tape again');
});

test('archived cards say what happened to the form', () => {
  assert.match(logic.archiveText(form({ status: 'reconciled', reconciled_by: 'Capt. Rivera', reconciled_at: '2026-10-31T13:00:00Z' })), /^Reconciled by Capt\. Rivera, Oct 31, 9:00 AM EDT$/);
  assert.equal(logic.archiveText(form({ status: 'superseded' })), 'Replaced by a corrected form');
  assert.equal(logic.archiveText(form({ status: 'issued', status_label: 'Issued' })), 'Older record: Issued');
  assert.equal(logic.archiveText(form({ status: 'cancelled', status_label: 'Cancelled' })), 'Cancelled');
});

test('the revision tag appears only after the first version', () => {
  assert.equal(logic.revisionTag(form()), null);
  assert.equal(logic.revisionTag(form({ revision_number: 2 })), 'Revision 2');
});

test('the item summary lists the preview, counts the rest and gives the total', () => {
  const full = logic.itemSummary(form({
    line_count: 5, quantity_total: 22,
    items_preview: [{ label: 'Gauze 4x4', quantity: 10, unit: null }, { label: 'Tape', quantity: 2, unit: 'box' }, { label: 'Gloves', quantity: 10, unit: 'each' }],
  }));
  assert.equal(full.list, 'Gauze 4x4 (10), Tape (2 box), Gloves (10), +2 more');
  assert.equal(full.total, '22 in all');
  // A Yes/No line has no quantity: its name stands alone.
  const yesNo = logic.itemSummary(form({ line_count: 1, quantity_total: 0, items_preview: [{ label: 'Narcs Box', quantity: null, unit: null }] }));
  assert.equal(yesNo.list, 'Narcs Box');
  assert.equal(yesNo.total, null);
  assert.equal(logic.itemSummary(form({ items_preview: [], line_count: 0 })).list, 'No items listed');
  assert.equal(logic.itemSummary(form({ items_preview: [], line_count: 4 })).list, '4 items');
});

// ------------------------------------------------------------------ selection

test('only acknowledged, eligible forms can be ticked; an unacknowledged one says why', () => {
  assert.equal(logic.selectionControl(form({ acknowledged: true, can_reconcile: true }), true), 'enabled');
  assert.equal(logic.selectionControl(form(), true), 'needs_acknowledgment');
  assert.equal(logic.selectionControl(form({ status: 'returned' }), true), 'none');
  assert.equal(logic.selectionControl(form({ status: 'issued', acknowledged: true }), true), 'none');
  assert.equal(logic.selectionControl(form({ acknowledged: true, can_reconcile: true }), false), 'none');
});

test('select all ready takes the eligible forms; toggling and pruning never mutate', () => {
  const forms = [form({ id: 'a', can_reconcile: true }), form({ id: 'b' }), form({ id: 'c', can_reconcile: true })];
  assert.deepEqual(logic.readyIds(forms), ['a', 'c']);
  assert.deepEqual(logic.readyIds(forms, false), []);
  const empty = new Set();
  const one = logic.toggleId(empty, 'a');
  assert.equal(empty.size, 0);
  assert.deepEqual([...one], ['a']);
  assert.deepEqual([...logic.toggleId(one, 'a')], []);
  const pruned = logic.pruneSelection(new Set(['a', 'b', 'z']), forms);
  assert.deepEqual([...pruned].sort(), ['a', 'z']);
  const same = new Set(['a']);
  assert.equal(logic.pruneSelection(same, forms), same);
});

test('the selection stops at fifty forms', () => {
  let selected = new Set();
  for (let i = 0; i < 60; i += 1) selected = logic.toggleId(selected, `f${i}`);
  assert.equal(selected.size, 50);
  const many = Array.from({ length: 60 }, (_, i) => form({ id: `f${i}`, can_reconcile: true }));
  assert.equal(logic.readyIds(many).length, 50);
});

test('mergeSummary takes the server answer for the row and drops the heavy parts', () => {
  const merged = logic.mergeSummary(form(), { ...form({ acknowledged: true, can_reconcile: true }), lines: [{ id: 1 }], history: [], messages: [], member_note: 'x', close_reason: null, cancel_reason: null, revisions: [] });
  assert.equal(merged.acknowledged, true);
  assert.equal(merged.can_reconcile, true);
  assert.equal('lines' in merged, false);
  assert.equal('history' in merged, false);
});

// ------------------------------------------------------------------ reject

test('a reason needs 3 to 500 characters after trimming', () => {
  assert.equal(logic.reasonError(''), 'Say what needs to be fixed.');
  assert.equal(logic.reasonError('  ab '), 'Write at least 3 characters.');
  assert.equal(logic.reasonError('abc'), null);
  assert.equal(logic.reasonError('x'.repeat(500)), null);
  assert.equal(logic.reasonError('x'.repeat(501)), 'Keep it to 500 characters or fewer.');
});

// ------------------------------------------------------------------ item search

test('item search matches name, nickname and shelf, and ranks names that start with the word first', () => {
  const items = [
    master(1, { name: 'Sterile Gauze 4x4', aliases: ['4x4 sponge'] }),
    master(2, { name: 'Gauze Roll', location_code: 'B7', location_label: 'Shelf B7' }),
    master(3, { name: 'Tape 1 inch', aliases: ['cloth tape'], location_code: 'C1' }),
  ];
  assert.deepEqual(logic.searchItems(items, 'gauze').map((i) => i.id), [2, 1]);
  assert.deepEqual(logic.searchItems(items, 'sponge').map((i) => i.id), [1]);
  assert.deepEqual(logic.searchItems(items, 'b7').map((i) => i.id), [2]);
  assert.deepEqual(logic.searchItems(items, 'gauze 4x4').map((i) => i.id), [1]);
  assert.deepEqual(logic.searchItems(items, '   '), []);
  assert.deepEqual(logic.searchItems(items, 'gauze', 1).map((i) => i.id), [2]);
});

// ------------------------------------------------------------------ confirmation text

test('the success line counts forms and items that came off the shelf', () => {
  const done = { reconciled: [{ id: 'a', number: 'A' }, { id: 'b', number: 'B' }], deducted: [{ supply_item_id: 1, name: 'x', deduct_base: 7 }, { supply_item_id: 2, name: 'y', deduct_base: 5 }] };
  assert.equal(logic.reconcileDoneText(done), 'Reconciled 2 forms. 12 items came off the shelf.');
  assert.equal(logic.reconcileDoneText({ reconciled: [{ id: 'a', number: 'A' }], deducted: [{ supply_item_id: 1, name: 'x', deduct_base: 1 }] }), 'Reconciled 1 form. 1 item came off the shelf.');
  assert.equal(logic.reconcileDoneText({ reconciled: [{ id: 'a', number: 'A' }], deducted: [] }), 'Reconciled 1 form. Nothing came off the shelf.');
});

// ------------------------------------------------------------------ history

test('history sentences cover the events this workflow adds and fall back to the old ones', () => {
  const event = (action, payload = {}) => ({ id: 1, action, payload, actor_name: null, actor_rank: null, actor_context: 'captain5', actor_context_label: 'Captain 5', request_line_id: null, from_status: null, to_status: null, created_at: null });
  assert.equal(logic.formEventSentence(event('reviewed')), 'Acknowledged the form.');
  assert.equal(logic.formEventSentence(event('rejected', { reason: 'Recount tape' })), 'Sent the form back to the member. Reason: Recount tape');
  assert.equal(logic.formEventSentence(event('revised')), 'Sent a corrected form.');
  assert.equal(logic.formEventSentence(event('issued', { label: 'Tape', quantity: 2 })), 'Issued 2 of Tape.');
});

// ------------------------------------------------------------------ reconcile maths

test('unit conversion: each and the base unit as typed, the package unit by its size, anything else unknown', () => {
  const box = { base_unit: 'each', package_unit: 'box', units_per_package: 24 };
  assert.equal(logic.unitFactor(null, box), 1);
  assert.equal(logic.unitFactor('each', box), 1);
  assert.equal(logic.unitFactor('Box', box), 24);
  assert.equal(logic.unitFactor('case', box), null);
  assert.equal(logic.unitFactor('box', { base_unit: 'each', package_unit: 'box', units_per_package: null }), null);
});

test('a ready line defaults to the requested quantity and scales when edited; 0 means not taken', () => {
  const ready = line(1, { quantity: 5, quantity_base: 120, unit: 'box' });
  assert.equal(logic.resolveLine(ready).baseDeduct, 120);
  assert.equal(logic.resolveLine(ready, { quantity: '2' }).baseDeduct, 48);
  assert.equal(logic.resolveLine(ready, { quantity: '0' }).baseDeduct, 0);
  assert.equal(logic.resolveLine(ready, { quantity: '0' }).resolved, true);
  const blank = logic.resolveLine(ready, { quantity: '' });
  assert.equal(blank.resolved, false);
  assert.equal(blank.baseDeduct, null);
  assert.match(blank.problem, /whole number/);
});

test('needs_item stays unresolved until an item or "not a supply room item" is chosen', () => {
  const needs = line(2, { state: 'needs_item', item: null, quantity: 3, quantity_base: null });
  const items = logic.indexMaster([master(71, { package_unit: 'box', units_per_package: 10 })]);
  assert.equal(logic.resolveLine(needs, {}, items).resolved, false);
  const picked = logic.resolveLine(needs, { itemId: 71 }, items);
  assert.equal(picked.resolved, true);
  assert.equal(picked.baseDeduct, 3);
  assert.equal(picked.itemId, 71);
  const none = logic.resolveLine(needs, { notStocked: true }, items);
  assert.equal(none.resolved, true);
  assert.equal(none.baseDeduct, 0);
  assert.equal(none.itemId, null);
});

test('a picked item whose unit cannot be converted asks for shelf units', () => {
  const needs = line(3, { state: 'needs_item', item: null, unit: 'case', quantity: 2, quantity_base: null });
  const items = logic.indexMaster([master(71, { package_unit: 'box', units_per_package: 10 })]);
  const asked = logic.resolveLine(needs, { itemId: 71 }, items);
  assert.equal(asked.needsUnits, true);
  assert.equal(asked.resolved, false);
  const typed = logic.resolveLine(needs, { itemId: 71, units: '40' }, items);
  assert.equal(typed.resolved, true);
  assert.equal(typed.baseDeduct, 40);
});

test('needs_unit takes the typed shelf units, and 0 is a real answer', () => {
  const needs = line(4, { state: 'needs_unit', unit: 'case', quantity: 2, quantity_base: null });
  assert.equal(logic.resolveLine(needs).resolved, false);
  assert.equal(logic.resolveLine(needs, { units: '0' }).resolved, true);
  assert.equal(logic.resolveLine(needs, { units: '12' }).baseDeduct, 12);
});

test('stock effect free lines are quiet and always resolved', () => {
  for (const state of ['no_stock_effect', 'not_stocked']) {
    const quiet = logic.resolveLine(line(5, { state, item: null }));
    assert.equal(quiet.group, 'quiet');
    assert.equal(quiet.resolved, true);
    assert.equal(quiet.baseDeduct, 0);
  }
});

test('unresolved lines are counted across the whole preview', () => {
  const data = preview([line(1), line(2, { state: 'needs_item', item: null }), line(3, { state: 'needs_unit' }), line(4, { state: 'no_stock_effect', item: null })]);
  const resolutions = logic.resolveAll(data, {}, new Map());
  assert.equal(logic.unresolvedCount(resolutions), 2);
  const answered = logic.resolveAll(data, { 3: { units: '4' }, 2: { notStocked: true } }, new Map());
  assert.equal(logic.unresolvedCount(answered), 0);
});

test('items add up across forms and flag a result below zero only for counted items', () => {
  const data = preview(
    [line(1, { quantity: 5, quantity_base: 5 }), line(2, { quantity: 20, quantity_base: 20 }), line(3, { quantity: 4, quantity_base: 4, item: { id: 80, name: 'Tape', location_code: 'A1', count_unit: 'each' } })],
    [total(71, { name: 'Gauze 4x4', before_base: 20, deduct_base: 25 }), total(80, { name: 'Tape', before_base: 0, not_counted: true, location_code: 'A1' })],
  );
  const takes = logic.itemTakes(data, logic.resolveAll(data, {}, new Map()), new Map());
  const gauze = takes.find((t) => t.itemId === 71);
  assert.equal(gauze.deductBase, 25);
  assert.equal(gauze.afterBase, -5);
  assert.equal(gauze.low, true);
  assert.equal(logic.takeText(gauze), 'Take 25');
  assert.equal(logic.onBooksText(gauze.afterBase, gauze), '-5');
  const tape = takes.find((t) => t.itemId === 80);
  assert.equal(tape.low, false);
  assert.equal(tape.notCounted, true);
  assert.equal(logic.hasLowStock(takes), true);
  // Typing what was actually taken clears the warning.
  const edited = logic.itemTakes(data, logic.resolveAll(data, { 2: { quantity: '10' } }, new Map()), new Map());
  assert.equal(edited.find((t) => t.itemId === 71).deductBase, 15);
  assert.equal(logic.hasLowStock(edited), false);
});

test('count units are shown in boxes with one decimal at most', () => {
  const boxy = { deductBase: 250, perCount: 100, countUnit: 'box' };
  assert.equal(logic.takeText(boxy), 'Take 2.5 boxes');
  assert.equal(logic.takeText({ deductBase: 100, perCount: 100, countUnit: 'box' }), 'Take 1 box');
  assert.equal(logic.takeText({ deductBase: 5, perCount: 1, countUnit: 'each' }), 'Take 5');
  assert.equal(logic.onBooksText(-150, boxy), '-1.5 boxes');
});

test('a picked item brings its own numbers from the master list', () => {
  const data = preview([line(2, { state: 'needs_item', item: null, quantity: 6, quantity_base: null })]);
  const items = logic.indexMaster([master(71, { name: 'Gauze 4x4', on_hand_base: 4 })]);
  const takes = logic.itemTakes(data, logic.resolveAll(data, { 2: { itemId: 71 } }, items), items);
  assert.equal(takes.length, 1);
  assert.equal(takes[0].afterBase, -2);
  assert.equal(takes[0].low, true);
});

test('decisions carry only what differs from the preview', () => {
  const data = preview([
    line(1, { quantity: 5, quantity_base: 5 }),
    line(2, { quantity: 8, quantity_base: 8 }),
    line(3, { state: 'needs_item', item: null, quantity: 3, quantity_base: null }),
    line(4, { state: 'needs_item', item: null, quantity: 1, quantity_base: null }),
    line(5, { state: 'needs_unit', unit: 'case', quantity: 2, quantity_base: null }),
    line(6, { state: 'needs_item', item: null, unit: 'case', quantity: 2, quantity_base: null }),
    line(7, { state: 'no_stock_effect', item: null }),
  ]);
  const items = logic.indexMaster([master(71, { package_unit: 'box', units_per_package: 10 })]);
  const inputs = { 2: { quantity: '6' }, 3: { itemId: 71, quantity: '2' }, 4: { notStocked: true }, 5: { units: '12' }, 6: { itemId: 71, units: '30' } };
  assert.deepEqual(logic.buildDecisions(logic.resolveAll(data, inputs, items)), [
    { line_id: 2, quantity: 6 },
    { line_id: 3, supply_item_id: 71, quantity: 2 },
    { line_id: 4, not_stocked: true },
    { line_id: 5, quantity_base: 12 },
    { line_id: 6, supply_item_id: 71, quantity_base: 30 },
  ]);
});

test('the idempotency signature ignores the order of forms and changes with any content', () => {
  const a = logic.reconcileSignature(['b', 'a'], 9, false, []);
  assert.equal(a, logic.reconcileSignature(['a', 'b'], 9, false, []));
  assert.notEqual(a, logic.reconcileSignature(['a', 'b'], 10, false, []));
  assert.notEqual(a, logic.reconcileSignature(['a', 'b'], 9, true, []));
  assert.notEqual(a, logic.reconcileSignature(['a', 'b'], 9, false, [{ line_id: 1, quantity: 2 }]));
});

test('typed values survive a reloaded preview only for lines still on it', () => {
  const data = preview([line(1), line(2)]);
  assert.deepEqual(logic.keepInputs({ 1: { quantity: '3' }, 9: { quantity: '4' } }, data), { 1: { quantity: '3' } });
});

test('a 422 names the lines to fix', () => {
  const data = preview([line(11), line(12)]);
  assert.deepEqual(logic.correctionLineIds({ lines: 'Lines 12, 99 need an item.' }, data), [12]);
  assert.deepEqual(logic.correctionLineIds({}, data), []);
});
