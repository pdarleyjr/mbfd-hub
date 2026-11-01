import assert from 'node:assert/strict';
import test from 'node:test';

// Plain TypeScript with type-only imports, so Node loads the helpers directly (same approach as ems-captain5-logic.test.mjs).
const dir = '../../resources/js/daily-checkout/src/components/captain5/ems/master';
const logic = await import(`${dir}/logic.ts`);
const edit = await import(`${dir}/editLogic.ts`);

const item = (overrides = {}) => ({
  id: 1, name: 'Gauze 4x4', aliases: [], location_code: 'A2', location_label: 'A2 Wound care', section_key: 'wound', section_label: 'Wound care',
  base_unit: 'each', count_unit: 'each', count_unit_label: 'each', count_unit_label_plural: 'each', units_per_count_unit: 1,
  package_unit: null, units_per_package: null, on_hand_base: null, on_hand: null, deducted_base: 0, deducted: 0, counted_at: null, counted_by: null,
  required_base: null, order_rule_text: null, refrigerated: false, needs_review: false, review_reasons: [], config_problem: null, ...overrides,
});
const box = (overrides = {}) => item({ count_unit: 'box', count_unit_label: 'box', count_unit_label_plural: 'boxes', units_per_count_unit: 250, package_unit: 'box', units_per_package: 250, ...overrides });

// ----------------------------------------------------------------- orderFor (contract section 5)

test('each items: 10 required', () => {
  const rule = item({ required_base: 10 });
  assert.deepEqual(logic.orderFor(5, rule), { kind: 'order', quantity: 5 });
  assert.deepEqual(logic.orderFor(8, rule), { kind: 'order', quantity: 2 });
  assert.deepEqual(logic.orderFor(10, rule), { kind: 'no-order' });
  assert.deepEqual(logic.orderFor(15, rule), { kind: 'no-order' });
});

test('each items: 25 required and nothing on the shelf orders 25', () => {
  assert.deepEqual(logic.orderFor(0, item({ required_base: 25 })), { kind: 'order', quantity: 25 });
});

test('box item, 250 per box, required 500: 1 box orders 1 box, 0 boxes orders 2 boxes', () => {
  const rule = box({ required_base: 500 });
  assert.deepEqual(logic.orderFor(1, rule), { kind: 'order', quantity: 1 });
  assert.deepEqual(logic.orderFor(0, rule), { kind: 'order', quantity: 2 });
  assert.deepEqual(logic.orderFor(2, rule), { kind: 'no-order' });
});

test('box item, 50 per box, required 25: 0 boxes orders 1 box (rounded up)', () => {
  const rule = box({ units_per_count_unit: 50, units_per_package: 50, required_base: 25 });
  assert.deepEqual(logic.orderFor(0, rule), { kind: 'order', quantity: 1 });
  assert.deepEqual(logic.orderFor(1, rule), { kind: 'no-order' });
});

test('no order level, or an unusable unit size, is "none set", never a guess', () => {
  assert.deepEqual(logic.orderFor(0, item({ required_base: null })), { kind: 'none-set' });
  assert.deepEqual(logic.orderFor(0, item({ required_base: 10, units_per_count_unit: 0 })), { kind: 'none-set' });
});

test('order wording carries the unit when it is not each', () => {
  const rule = box({ required_base: 500 });
  assert.equal(logic.orderText(logic.orderFor(0, rule), rule), 'Order 2 boxes');
  assert.equal(logic.orderText(logic.orderFor(1, rule), rule), 'Order 1 box');
  assert.equal(logic.orderText(logic.orderFor(2, rule), rule), 'No order needed');
  assert.equal(logic.orderText(logic.orderFor(2, box()), box()), 'No order level set');
  assert.equal(logic.orderText(logic.orderFor(5, item({ required_base: 10 })), item({ required_base: 10 })), 'Order 5');
});

// ------------------------------------------------------------------------------ display

test('quantities show whole numbers and at most one decimal', () => {
  assert.equal(logic.formatQty(5), '5');
  assert.equal(logic.formatQty(2.5), '2.5');
  assert.equal(logic.formatQty(2.04), '2');
  assert.equal(logic.formatQty(0), '0');
});

test('the red number has its own accessible words and is absent at zero', () => {
  assert.deepEqual(logic.deductedDisplay({ deducted: 5 }), { text: '-5', label: 'minus 5 since last count' });
  assert.deepEqual(logic.deductedDisplay({ deducted: 1.5 }), { text: '-1.5', label: 'minus 1.5 since last count' });
  assert.equal(logic.deductedDisplay({ deducted: 0 }), null);
});

test('unit words: nothing for each, singular for one, plural otherwise', () => {
  assert.equal(logic.unitWord(item(), 5), '');
  assert.equal(logic.unitWord(box(), 1), 'box');
  assert.equal(logic.unitWord(box(), 0), 'boxes');
  assert.equal(logic.unitWord(box(), 2.5), 'boxes');
});

test('last counted line needs a date, and the name is optional', () => {
  assert.equal(logic.lastCountText(null), null);
  assert.equal(logic.lastCountText({ finalized_at: null, finalized_name: 'A' }), null);
  assert.match(logic.lastCountText({ finalized_at: '2026-10-26T14:00:00Z', finalized_name: 'Alex Rivera' }), /^Last counted Oct 26, 2026 by Alex Rivera$/);
  assert.match(logic.lastCountText({ finalized_at: '2026-10-26T14:00:00Z', finalized_name: null }), /^Last counted Oct 26, 2026$/);
});

test('machine reasons read as plain words', () => {
  assert.equal(logic.humanReason('unknown_conversion'), 'Unknown conversion');
});

// ------------------------------------------------------------------------------ search

const catalog = [
  item({ id: 1, name: 'IV Catheter 18 Gauge', aliases: ['18g angiocath'], location_code: 'B1', location_label: 'B1 IV supplies', section_label: 'IV' }),
  item({ id: 2, name: 'IV Catheter 20 Gauge', aliases: ['20g'], location_code: 'B1', location_label: 'B1 IV supplies', section_label: 'IV' }),
  item({ id: 3, name: 'Gauze 4x4', aliases: ['4 x 4 sponge'], location_code: 'A2', location_label: 'A2 Wound care', section_label: 'Wound care' }),
  item({ id: 4, name: 'Gauze Roll', aliases: [], location_code: 'A2', location_label: 'A2 Wound care', section_label: 'Wound care' }),
  item({ id: 5, name: 'Sterile Saline 0.9%', aliases: ['NS flush'], location_code: 'C3', location_label: 'C3 Fluids', section_label: 'Fluids' }),
  item({ id: 6, name: 'Tape', aliases: [], location_code: null, location_label: null, section_label: null }),
];
const index = logic.buildSearchIndex(catalog);
const ids = (query) => logic.searchItems(index, query).map((entry) => entry.id);

test('text is normalized: case, punctuation, and digits split from letters', () => {
  assert.equal(logic.normalizeText('  18G  Angio-Cath! '), '18 g angio cath');
  assert.equal(logic.normalizeText('4x4'), '4 x 4');
  assert.equal(logic.normalizeText('Épinéphrine'), 'epinephrine');
});

test('"18g" finds the 18 gauge catheter and not the 20 gauge one', () => {
  assert.deepEqual(ids('18g'), [1]);
  assert.deepEqual(ids('18 gauge'), [1]);
  assert.deepEqual(ids('GAUGE 18'), [1]);
});

test('word order and punctuation do not matter', () => {
  assert.deepEqual(ids('catheter iv'), [1, 2]);
  assert.deepEqual(ids('4x4 gauze'), [3]);
  assert.deepEqual(ids('gauze, 4 x 4'), [3]);
});

test('aliases and shelf codes and labels and section labels are searched', () => {
  assert.deepEqual(ids('angiocath'), [1]);
  assert.deepEqual(ids('ns flush'), [5]);
  assert.deepEqual(ids('wound'), [3, 4]);
  assert.deepEqual(ids('B1'), [1, 2]);
  assert.deepEqual(ids('fluids'), [5]);
});

test('an exact name sorts before partial matches', () => {
  assert.deepEqual(ids('tape'), [6]);
  const withExact = logic.buildSearchIndex([item({ id: 10, name: 'Gauze Roll Large' }), item({ id: 11, name: 'Gauze' }), item({ id: 12, name: 'Sterile Gauze' })]);
  assert.deepEqual(logic.searchItems(withExact, 'gauze').map((entry) => entry.id), [11, 10, 12]);
});

test('a blank or unmatched search returns nothing', () => {
  assert.deepEqual(ids('   '), []);
  assert.deepEqual(ids('zzz'), []);
});

// ---------------------------------------------------------------- shelf navigation

const sections = [
  { code: 'A2', label: 'A2 Wound care', item_count: 2 },
  { code: 'B1', label: 'B1 IV supplies', item_count: 2 },
  { code: 'C3', label: 'C3 Fluids', item_count: 1 },
  { code: null, label: 'No shelf assigned', item_count: 1 },
];

test('the shelf in the address picks the shelf, and an unknown one falls back to the first', () => {
  assert.equal(logic.sectionIndex(sections, 'B1'), 1);
  assert.equal(logic.sectionIndex(sections, 'none'), 3);
  assert.equal(logic.sectionIndex(sections, 'zz'), 0);
  assert.equal(logic.sectionIndex(sections, null), 0);
  assert.equal(logic.sectionParam(sections[3]), 'none');
});

test('stepping stops at both ends', () => {
  assert.equal(logic.stepIndex(0, -1, 4), 0);
  assert.equal(logic.stepIndex(3, 1, 4), 3);
  assert.equal(logic.stepIndex(1, 1, 4), 2);
  assert.equal(logic.clampIndex(9, 0), 0);
  assert.equal(logic.shelfPosition(2, 49), 'Shelf 3 of 49');
});

test('items are grouped by shelf in server order, and strays go to no shelf', () => {
  const groups = logic.groupBySection([...catalog, item({ id: 7, name: 'Stray', location_code: 'Q9' })], sections);
  assert.deepEqual(groups.get('A2').map((entry) => entry.id), [3, 4]);
  assert.deepEqual(groups.get('B1').map((entry) => entry.id), [1, 2]);
  assert.deepEqual(groups.get('none').map((entry) => entry.id), [6, 7]);
});

test('next and previous item positions cross shelves and skip empty ones', () => {
  const shelves = [[{ id: 1 }, { id: 2 }], [], [{ id: 3 }]];
  assert.deepEqual(logic.nextItemPosition(shelves, 0, 0), { shelf: 0, row: 1 });
  assert.deepEqual(logic.nextItemPosition(shelves, 0, 1), { shelf: 2, row: 0 });
  assert.equal(logic.nextItemPosition(shelves, 2, 0), null);
  assert.deepEqual(logic.previousItemPosition(shelves, 2, 0), { shelf: 0, row: 1 });
  assert.equal(logic.previousItemPosition(shelves, 0, 0), null);
});

// -------------------------------------------------------------------- count entries

test('only digits can be typed, six at most, and 0 is a real count while blank is not', () => {
  assert.equal(logic.digitsOnly('12ab-3'), '123');
  assert.equal(logic.digitsOnly('1234567'), '123456');
  assert.equal(logic.digitsOnly('007'), '7');
  assert.equal(logic.digitsOnly('0'), '0');
  assert.equal(logic.parseEntry(''), null);
  assert.equal(logic.parseEntry('abc'), null);
  assert.equal(logic.parseEntry('0'), 0);
  assert.equal(logic.parseEntry('42'), 42);
});

test('entries are changed by copy: clearing removes, 0 stays', () => {
  const start = Object.freeze({ 1: 5 });
  const withZero = logic.withEntry(start, 2, 0);
  assert.deepEqual(withZero, { 1: 5, 2: 0 });
  assert.deepEqual(start, { 1: 5 });
  assert.deepEqual(logic.withEntry(withZero, 1, null), { 2: 0 });
  assert.equal(logic.enteredTotal([{ id: 1 }, { id: 2 }, { id: 3 }], withZero), 2);
  assert.equal(logic.entryText({ 2: 0 }, 2), '0');
  assert.equal(logic.entryText({ 2: 0 }, 3), '');
});

test('merge: the server entries with this device\'s unsent numbers on top, including cleared ones', () => {
  const server = { 1: 5, 2: 7, 3: 9 };
  const local = { 1: 6, 3: 9 };
  const dirty = { 1: true, 2: true };
  assert.deepEqual(logic.mergeEntries(server, local, dirty), { 1: 6, 3: 9 });
  assert.deepEqual(server, { 1: 5, 2: 7, 3: 9 });
  assert.deepEqual(logic.mergeEntries(server, {}, {}), server);
});

test('batches hold unsent items in item order, send cleared ones as null, and honor the size limit', () => {
  const entries = { 12: 3, 4: 0 };
  const dirty = { 12: true, 4: true, 7: true };
  assert.deepEqual(logic.buildBatch(entries, dirty), [
    { supply_item_id: 4, on_hand: 0 }, { supply_item_id: 7, on_hand: null }, { supply_item_id: 12, on_hand: 3 },
  ]);
  assert.equal(logic.buildBatch(entries, dirty, 2).length, 2);
  assert.deepEqual(logic.buildBatch({}, {}), []);
});

test('a confirmed save only clears what is still the same; numbers typed meanwhile stay unsent', () => {
  const sent = [{ supply_item_id: 4, on_hand: 0 }, { supply_item_id: 7, on_hand: null }, { supply_item_id: 12, on_hand: 3 }];
  const current = { 4: 0, 12: 8 };
  const remaining = logic.settleDirty({ 4: true, 7: true, 12: true }, sent, current);
  assert.deepEqual(remaining, { 12: true });
  assert.equal(logic.dirtyCount(remaining), 1);
});

test('a save counts only when the server holds exactly what was sent', () => {
  const sent = [{ supply_item_id: 4, on_hand: 0 }, { supply_item_id: 7, on_hand: null }];
  assert.equal(logic.savedMatches(sent, { 4: 0 }), true);
  assert.equal(logic.savedMatches(sent, { 4: 1 }), false);
  assert.equal(logic.savedMatches(sent, { 4: 0, 7: 2 }), false);
  assert.equal(logic.savedMatches(sent, undefined), false);
});

test('retry waits grow and stop at thirty seconds', () => {
  assert.deepEqual([0, 1, 2, 3, 4, 5, 9].map(logic.retryDelayMs), [1000, 2000, 4000, 8000, 16000, 30000, 30000]);
});

test('the copy on this device round-trips and ignores anything it does not understand', () => {
  assert.equal(logic.countStorageKey('abc'), 'mbfd-ems-count:abc:room');
  const raw = logic.serializeDraft({ 4: 0, 12: 3 }, { 12: true });
  assert.deepEqual(logic.parseDraft(raw), { entries: { 4: 0, 12: 3 }, dirty: { 12: true } });
  assert.equal(logic.parseDraft(null), null);
  assert.equal(logic.parseDraft('not json'), null);
  assert.equal(logic.parseDraft(JSON.stringify({ v: 2, entries: {}, dirty: [] })), null);
  assert.deepEqual(logic.parseDraft(JSON.stringify({ v: 1, entries: { 1: 2.5, 2: -1, x: 3, 4: 4 }, dirty: ['4', 'z', 9] })), { entries: { 4: 4 }, dirty: { 4: true } });
});

// ------------------------------------------------------------------------------ review

test('missing items are grouped under their shelf in shelf order', () => {
  const missing = [
    { id: 3, name: 'Gauze 4x4', location_code: 'A2' },
    { id: 6, name: 'Tape', location_code: null },
    { id: 1, name: 'IV Catheter 18 Gauge', location_code: 'B1' },
    { id: 4, name: 'Gauze Roll', location_code: 'A2' },
  ];
  const groups = logic.groupMissing(missing, sections);
  assert.deepEqual(groups.map((group) => [group.label, group.items.map((entry) => entry.id)]), [
    ['A2 Wound care', [3, 4]], ['B1 IV supplies', [1]], ['No shelf assigned', [6]],
  ]);
  assert.equal(logic.missingNote(1), 'Submit with 1 item not counted');
  assert.equal(logic.missingNote(3), 'Submit with 3 items not counted');
});

// -------------------------------------------------------------------------- edit rules

test('the starting form shows the order level in the unit people count in', () => {
  const form = edit.initialForm(box({ required_base: 500, on_hand: 2.5 }));
  assert.equal(form.threshold, '2');
  assert.equal(form.onHand, '2.5');
  assert.equal(form.countUnit, 'box');
  assert.equal(form.perPackage, '250');
});

test('an untouched form changes nothing', () => {
  const target = box({ required_base: 500, on_hand: 3 });
  const plan = edit.planEdit(target, edit.initialForm(target));
  assert.equal(plan.nothingChanged, true);
  assert.deepEqual(plan.errors, {});
});

test('only changed fields are sent; the on-hand number is a count, not a field', () => {
  const target = item({ on_hand: 4, required_base: 10 });
  const form = { ...edit.initialForm(target), name: 'Gauze 4x4 sterile', shelf: 'B1', onHand: '9', threshold: '' };
  const plan = edit.planEdit(target, form);
  assert.deepEqual(plan.changes, { name: 'Gauze 4x4 sterile', location_code: 'B1', threshold: null });
  assert.equal(plan.count, 9);
});

test('a typed new shelf is sent as a label, and switching to a package unit sends the size too', () => {
  const target = item({ on_hand: 4 });
  const plan = edit.planEdit(target, { ...edit.initialForm(target), shelf: edit.NEW_SHELF, newShelf: ' Bay 7 ', countUnit: 'box', perPackage: '50' });
  assert.deepEqual(plan.changes, { new_location_label: 'Bay 7', count_unit: 'box', units_per_package: 50 });
  assert.equal(plan.count, null);
});

test('edits that cannot be saved say why', () => {
  const target = item({ on_hand: 4 });
  const plan = edit.planEdit(target, { ...edit.initialForm(target), name: '  ', shelf: edit.NEW_SHELF, newShelf: '', countUnit: 'case', perPackage: '', threshold: 'abc' });
  assert.deepEqual(Object.keys(plan.errors).sort(), ['name', 'perPackage', 'shelf', 'threshold']);
});

test('a new item needs a name and, when counted in packages, the package size', () => {
  assert.equal(edit.planNewItem({ ...edit.EMPTY_NEW_ITEM }).input, null);
  const ok = edit.planNewItem({ ...edit.EMPTY_NEW_ITEM, name: 'Splint', shelf: 'A2', countUnit: 'box', perPackage: '12', threshold: '2.5', onHand: '3' });
  assert.deepEqual(ok.input, { name: 'Splint', count_unit: 'box', location_code: 'A2', units_per_package: 12, threshold: 2.5, on_hand: 3 });
});

test('unit choices are each plus the item\'s own package unit; an item with none may choose any', () => {
  assert.deepEqual(edit.unitChoices(box()), ['each', 'box']);
  assert.deepEqual(edit.unitChoices(item()), ['each', 'box', 'case', 'pack', 'bag']);
});

test('number fields keep digits, and the level keeps one decimal', () => {
  assert.equal(edit.wholeText('1a2,3'), '123');
  assert.equal(edit.levelText('2.55.1'), '2.5');
  assert.equal(edit.levelText('x7'), '7');
});
