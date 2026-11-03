import assert from 'node:assert/strict';
import test from 'node:test';

// Plain TypeScript with type-only imports, so Node loads the helpers directly (same approach as ems-master-inventory-logic.test.mjs).
const dir = '../../resources/js/daily-checkout/src/components/captain5/ems/master';
const levels = await import(`${dir}/stockLevel.ts`);
const orders = await import(`${dir}/orderLogic.ts`);
const batch = await import(`${dir}/batchLogic.ts`);
const low = await import(`${dir}/lowStock.ts`);

const item = (overrides = {}) => ({
  id: 1, name: 'Gauze 4x4', aliases: [], location_code: 'A2', location_label: 'A2 Wound care', section_key: 'wound', section_label: 'Wound care',
  base_unit: 'each', count_unit: 'each', count_unit_label: 'each', count_unit_label_plural: 'each', units_per_count_unit: 1,
  package_unit: null, units_per_package: null, on_hand_base: null, on_hand: null, deducted_base: 0, deducted: 0, counted_at: null, counted_by: null,
  required_base: null, order_rule_text: null, refrigerated: false, needs_review: false, review_reasons: [], config_problem: null,
  expected: null, to_order: null, order_state: 'ok', open_order: null, ...overrides,
});
const box = (overrides = {}) => item({ count_unit: 'box', count_unit_label: 'box', count_unit_label_plural: 'boxes', units_per_count_unit: 10, package_unit: 'box', units_per_package: 10, ...overrides });

/** The level for every on-hand amount from 0 to `top`, as one list per level. */
function bands(expected, top, reorder = null, critical = null) {
  const lines = levels.stockLines(expected, reorder, critical);
  const out = { red: [], yellow: [], green: [] };
  for (let onHand = 0; onHand <= top; onHand += 1) out[levels.levelFor(onHand, lines)].push(onHand);
  return out;
}

// ------------------------------------------------------------------------- the presets

test('presets: yellow at half the expected amount, red at a quarter, rounded down', () => {
  assert.deepEqual(levels.stockLines(20), { reorder: 10, critical: 5 });
  assert.deepEqual(levels.stockLines(4), { reorder: 2, critical: 1 });
  assert.deepEqual(levels.stockLines(1), { reorder: 0, critical: 0 });
  assert.deepEqual(levels.stockLines(7), { reorder: 3, critical: 1 });
});

test('no lines without an expected amount of 1 or more', () => {
  assert.equal(levels.stockLines(null), null);
  assert.equal(levels.stockLines(undefined), null);
  assert.equal(levels.stockLines(0), null);
  assert.equal(levels.stockLines(0.5), null);
  assert.equal(levels.stockLines(Number.NaN), null);
});

test('expected 20: red 0-5, yellow 6-10, green 11 and up', () => {
  const out = bands(20, 25);
  assert.deepEqual(out.red, [0, 1, 2, 3, 4, 5]);
  assert.deepEqual(out.yellow, [6, 7, 8, 9, 10]);
  assert.deepEqual(out.green, [11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25]);
});

test('expected 4: red 0-1, yellow 2, green 3-4', () => {
  assert.deepEqual(bands(4, 4), { red: [0, 1], yellow: [2], green: [3, 4] });
});

test('expected 1: red at 0, green at 1', () => {
  assert.deepEqual(bands(1, 1), { red: [0], yellow: [], green: [1] });
});

test('a fraction on hand sits in the band it falls in, and anything at or below 0 is red', () => {
  const lines = levels.stockLines(20);
  assert.equal(levels.levelFor(5.5, lines), 'yellow');
  assert.equal(levels.levelFor(10.5, lines), 'green');
  assert.equal(levels.levelFor(-1, lines), 'red');
});

test('no level when the item was never counted or has no lines', () => {
  assert.equal(levels.levelFor(null, levels.stockLines(20)), null);
  assert.equal(levels.levelFor(undefined, levels.stockLines(20)), null);
  assert.equal(levels.levelFor(5, null), null);
});

// ------------------------------------------------------------------- a person's own lines

test('overrides replace the presets', () => {
  assert.deepEqual(levels.stockLines(20, 15, 3), { reorder: 15, critical: 3 });
  const out = bands(20, 20, 15, 3);
  assert.deepEqual(out.red, [0, 1, 2, 3]);
  assert.deepEqual(out.yellow, [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15]);
  assert.deepEqual(out.green, [16, 17, 18, 19, 20]);
});

test('only one line overridden: the other stays the preset', () => {
  assert.deepEqual(levels.stockLines(20, 12, null), { reorder: 12, critical: 5 });
  assert.deepEqual(levels.stockLines(20, null, 2), { reorder: 10, critical: 2 });
});

test('red is never above yellow: a high red line, or a preset red above a low yellow, is clamped', () => {
  assert.deepEqual(levels.stockLines(20, null, 12), { reorder: 10, critical: 10 });
  assert.deepEqual(levels.stockLines(20, 2, null), { reorder: 2, critical: 2 });
  assert.deepEqual(bands(20, 4, 2, null), { red: [0, 1, 2], yellow: [], green: [3, 4] });
});

test('a yellow line above the expected amount sits at the expected amount, as on the server', () => {
  assert.deepEqual(levels.stockLines(10, 15, null), { reorder: 10, critical: 2 });
  assert.deepEqual(levels.stockLines(10, 15, 12), { reorder: 10, critical: 10 });
  assert.deepEqual(levels.stockLines(2.5, 9, null), { reorder: 2, critical: 0 });
  assert.deepEqual(bands(10, 11, 15, null), { red: [0, 1, 2], yellow: [3, 4, 5, 6, 7, 8, 9, 10], green: [11] });
});

test('lines of 0: only an empty shelf is red', () => {
  assert.deepEqual(bands(8, 3, 0, 0), { red: [0], yellow: [], green: [1, 2, 3] });
});

// --------------------------------------------------------------------- server items

test('itemLines uses the server numbers as given, null means no lines, missing means presets', () => {
  assert.deepEqual(levels.itemLines({ expected: 20, reorder_at: 12, critical_at: 4 }), { reorder: 12, critical: 4 });
  assert.deepEqual(levels.itemLines({ expected: 20, reorder_at: 3, critical_at: 9 }), { reorder: 3, critical: 3 });
  assert.equal(levels.itemLines({ expected: null, reorder_at: null, critical_at: null }), null);
  assert.deepEqual(levels.itemLines({ expected: 20 }), { reorder: 10, critical: 5 });
});

test('normalizeItem keeps the server level and lines, and works them out for an older answer', () => {
  const sent = orders.normalizeItem(item({ on_hand: 30, expected: 20, level: 'red', reorder_at: 12, critical_at: 4, reorder_at_auto: false, critical_at_auto: false }));
  assert.equal(sent.level, 'red');
  assert.deepEqual([sent.reorder_at, sent.critical_at, sent.reorder_at_auto, sent.critical_at_auto], [12, 4, false, false]);
  const older = orders.normalizeItem(item({ on_hand: 7, expected: 20 }));
  assert.deepEqual([older.level, older.reorder_at, older.critical_at, older.reorder_at_auto, older.critical_at_auto], ['yellow', 10, 5, true, true]);
  const uncounted = orders.normalizeItem(item({ on_hand: null, expected: 20, level: null }));
  assert.equal(uncounted.level, null);
});

// ----------------------------------------------------------------------- Edit mode

const server = (overrides = {}) => orders.normalizeItem(item({
  on_hand: 8, on_hand_base: 8, expected: 20, required_base: 20, to_order: 12, order_state: 'needs_order',
  level: 'yellow', reorder_at: 10, critical_at: 5, reorder_at_auto: true, critical_at_auto: true, ...overrides,
}));

test('an untouched row shows the server level', () => {
  assert.equal(batch.liveLevel(server(), batch.startDraft(server())), 'yellow');
  assert.equal(batch.liveLevel(server({ level: 'green' }), batch.startDraft(server())), 'green');
});

test('a typed on hand recolours the row straight away, using the server lines', () => {
  const target = server({ reorder_at: 12, critical_at: 4, reorder_at_auto: false, critical_at_auto: false });
  const start = batch.startDraft(target);
  assert.equal(batch.liveLevel(target, batch.withNumber(start, 'onHand', '4')), 'red');
  assert.equal(batch.liveLevel(target, batch.withNumber(start, 'onHand', '12')), 'yellow');
  assert.equal(batch.liveLevel(target, batch.withNumber(start, 'onHand', '13')), 'green');
  // A blank on-hand box keeps the saved 8.
  assert.equal(batch.liveLevel(target, batch.withNumber(start, 'onHand', '')), 'yellow');
});

test('a typed expected amount works the presets out again while the lines are on Auto', () => {
  const target = server();
  const draft = batch.withNumber(batch.startDraft(target), 'expected', '40');
  assert.deepEqual(batch.draftLines(target, draft), { reorder: 20, critical: 10 });
  assert.equal(batch.liveLevel(target, draft), 'red');
  assert.equal(batch.linePreset(target, draft, 'reorder'), 20);
  assert.equal(batch.linePreset(target, draft, 'critical'), 10);
  assert.equal(batch.liveLevel(target, batch.withNumber(batch.startDraft(target), 'expected', '')), null);
});

test('a person\'s own line stays when the expected amount changes', () => {
  const target = server({ reorder_at: 12, reorder_at_auto: false });
  const draft = batch.withNumber(batch.startDraft(target), 'expected', '40');
  assert.deepEqual(batch.draftLines(target, draft), { reorder: 12, critical: 10 });
  assert.equal(batch.shownLine(target, draft, 'reorder'), '12');
  assert.equal(batch.shownLine(target, draft, 'critical'), '');
});

test('typed lines recolour the row', () => {
  const target = server();
  assert.equal(batch.liveLevel(target, batch.withLine(batch.startDraft(target), 'critical', '8')), 'red');
  assert.equal(batch.liveLevel(target, batch.withLine(batch.startDraft(target), 'reorder', '6')), 'green');
});

test('a typed line is sent only when it changes; a blank box on a person\'s line sends null (back to Auto)', () => {
  const auto = server();
  assert.deepEqual(batch.planRow(auto, batch.withLine(batch.startDraft(auto), 'reorder', '8')).change, { id: 1, reorder_at: 8 });
  assert.deepEqual(batch.planRow(auto, batch.withLine(batch.startDraft(auto), 'reorder', '')), { change: null, errors: {} });

  const own = server({ reorder_at: 12, critical_at: 4, reorder_at_auto: false, critical_at_auto: false });
  assert.deepEqual(batch.planRow(own, batch.withLine(batch.startDraft(own), 'reorder', '12')), { change: null, errors: {} });
  const cleared = batch.withLine(batch.withLine(batch.startDraft(own), 'reorder', ''), 'critical', '');
  assert.deepEqual(batch.planRow(own, cleared).change, { id: 1, reorder_at: null, critical_at: null });
  assert.equal(batch.linePreset(own, cleared, 'reorder'), 10);
});

test('Red at above Yellow at, and Yellow at above the expected amount, are refused before saving', () => {
  const target = server();
  const high = batch.withLine(batch.withLine(batch.startDraft(target), 'reorder', '6'), 'critical', '7');
  assert.deepEqual(batch.planRow(target, high).errors, { critical: batch.CRITICAL_ABOVE_REORDER });
  assert.equal(batch.CRITICAL_ABOVE_REORDER, 'Red at cannot be above Yellow at.');

  const abovePreset = batch.withLine(batch.startDraft(target), 'critical', '11');
  assert.deepEqual(batch.planRow(target, abovePreset).errors, { critical: batch.CRITICAL_ABOVE_REORDER });

  const tooHigh = batch.withLine(batch.startDraft(target), 'reorder', '21');
  assert.deepEqual(batch.planRow(target, tooHigh).errors, { reorder: batch.REORDER_ABOVE_EXPECTED });
  assert.equal(batch.REORDER_ABOVE_EXPECTED, 'Yellow at cannot be above the expected amount.');

  // Lowering only the expected amount is allowed: the saved yellow line then sits at the expected amount, as on the server.
  const own = server({ reorder_at: 15, critical_at: 8, reorder_at_auto: false, critical_at_auto: false });
  const lowered = batch.withNumber(batch.startDraft(own), 'expected', '12');
  assert.deepEqual(batch.planRow(own, lowered), { change: { id: 1, expected: 12 }, errors: {} });
  assert.deepEqual(batch.draftLines(own, lowered), { reorder: 12, critical: 8 });
  assert.deepEqual(batch.planRow(own, batch.withLine(lowered, 'reorder', '13')).errors, { reorder: batch.REORDER_ABOVE_EXPECTED });
  // Editing a line checks the saved other line too.
  assert.deepEqual(batch.planRow(own, batch.withLine(batch.startDraft(own), 'reorder', '6')).errors, { critical: batch.CRITICAL_ABOVE_REORDER });
});

test('a line needs an expected amount, and must be a whole number', () => {
  const none = server({ expected: null, required_base: null, reorder_at: null, critical_at: null, level: null });
  assert.deepEqual(batch.planRow(none, batch.withLine(batch.startDraft(none), 'reorder', '3')).errors, { reorder: batch.LINE_NEEDS_EXPECTED });
  const target = server();
  assert.deepEqual(batch.planRow(target, batch.withLine(batch.startDraft(target), 'reorder', '2.5')).errors, { reorder: batch.LINE_MESSAGE });
});

test('a unit change clears typed lines like the other numbers', () => {
  const target = server();
  const draft = batch.withCounting(batch.withLine(batch.startDraft(target), 'reorder', '8'), { countUnit: 'box', perPackage: '4' });
  assert.equal(draft.reorderTouched, false);
  assert.equal(draft.numbersReset, true);
  assert.deepEqual(batch.planRow(target, draft).change, { id: 1, count_unit: 'box', units_per_package: 4 });
});

test('a person\'s line follows a unit change for the colour and the box, rounded down', () => {
  const target = orders.normalizeItem(box({ on_hand: 3, on_hand_base: 30, expected: 10, required_base: 100, reorder_at: 6, critical_at: 2, reorder_at_auto: false, critical_at_auto: false, level: 'yellow' }));
  const draft = batch.withCounting(batch.startDraft(target), { countUnit: 'each' });
  assert.equal(batch.shownLine(target, draft, 'reorder'), '60');
  assert.equal(batch.shownLine(target, draft, 'critical'), '20');
  assert.equal(batch.liveLevel(target, draft), 'yellow');
});

test('server errors for the lines land on their boxes', () => {
  const sent = [{ id: 4, reorder_at: 30 }, { id: 9, critical_at: 9 }];
  const placed = batch.placeServerErrors({ 'changes.0.reorder_at': 'Too high.', 'changes.1.critical_at': 'Above yellow.' }, sent);
  assert.deepEqual(placed.rows, { 4: { reorder: 'Too high.' }, 9: { critical: 'Above yellow.' } });
});

// ------------------------------------------------------------------------ Low or out card

const words = { count_unit: 'box', count_unit_label: 'box', count_unit_label_plural: 'boxes' };

test('the card lists only red items, in list order', () => {
  const list = [{ id: 1, level: 'green' }, { id: 2, level: 'red' }, { id: 3, level: null }, { id: 4, level: 'red' }, { id: 5, level: 'yellow' }];
  assert.deepEqual(low.lowStockItems(list).map((entry) => entry.id), [2, 4]);
  assert.equal(low.lowStockHeading(1), '1 item is low or out');
  assert.equal(low.lowStockHeading(3), '3 items are low or out');
});

test('what is left reads "1 of 3 boxes", or "2 of 4" for single units', () => {
  assert.equal(low.stockOfText({ ...words, on_hand: 1, expected: 3 }), '1 of 3 boxes');
  assert.equal(low.stockOfText({ count_unit: 'each', count_unit_label: 'each', count_unit_label_plural: 'each', on_hand: 2, expected: 4 }), '2 of 4');
  assert.equal(low.stockOfText({ ...words, on_hand: 0, expected: 1 }), '0 of 1 box');
});

test('advice: exactly how many to order, or the open order instead', () => {
  assert.deepEqual(low.lowStockAdvice({ ...words, to_order: 2, open_order: null }), { kind: 'order', text: 'Order 2 boxes' });
  assert.deepEqual(low.lowStockAdvice({ count_unit: 'each', count_unit_label: 'each', count_unit_label_plural: 'each', to_order: 15, open_order: null }), { kind: 'order', text: 'Order 15' });
  const order = (status, expectedOn = null) => ({ id: 1, status, quantity: 4, unit_label: 'boxes', expected_on: expectedOn, note: null, placed_at: null, placed_by: null, updated_at: null, updated_by: null });
  assert.deepEqual(low.lowStockAdvice({ ...words, to_order: 2, open_order: order('ordered') }), { kind: 'open', text: 'Ordered 4 boxes' });
  assert.deepEqual(low.lowStockAdvice({ ...words, to_order: 2, open_order: order('backordered', '2026-11-09') }), { kind: 'open', text: 'Back ordered 4 boxes, expected Nov 9' });
  assert.deepEqual(low.lowStockAdvice({ ...words, to_order: 2, open_order: order('pending') }), { kind: 'open', text: 'Pending 4 boxes' });
  assert.deepEqual(low.lowStockAdvice({ ...words, to_order: 0, open_order: null }), { kind: 'none', text: 'Nothing to order' });
});

test('short dates have no time zone shift and ignore anything that is not a plain date', () => {
  assert.equal(low.shortDay('2026-11-09'), 'Nov 9');
  assert.equal(low.shortDay('2026-01-01'), 'Jan 1');
  assert.equal(low.shortDay(null), '');
  assert.equal(low.shortDay('soon'), '');
});
