import assert from 'node:assert/strict';
import test from 'node:test';

// The helpers are plain TypeScript with type-only imports, so Node loads them directly.
const logic = await import('../../resources/js/daily-checkout/src/components/captain5/ems/logic.ts');

const line = (overrides = {}) => ({
  id: 1, bag_key: 'als-med-bag', item_key: 'als.trauma.gauze', label: 'Gauze 4x4', section_label: 'Trauma', variant_label: null, min_text: '10 EACH', unit: null,
  response_type: 'quantity', requested_qty: 10, requested_flag: null, prepared_qty: null, issued_qty: 0, line_status: 'open', line_status_label: 'Waiting',
  not_issued_reason: null, refrigerated: false, controlled_substance: false, supply_candidates: [], ...overrides,
});

const event = (id, action, payload = {}, extra = {}) => ({
  id, action, payload, actor_name: 'Cap Tain', actor_rank: 'Capt.', actor_context: 'captain5', actor_context_label: 'Captain 5',
  request_line_id: 1, from_status: null, to_status: null, created_at: '2026-10-23T18:05:00Z', ...extra,
});

test('times are shown in Eastern time, with the zone', () => {
  assert.equal(logic.etDateTime('2026-10-23T18:05:00Z'), 'Oct 23, 2:05 PM EDT');
  assert.equal(logic.etDateTime('2026-01-15T18:05:00Z'), 'Jan 15, 1:05 PM EST');
  assert.equal(logic.etDateTime(null), 'Unknown time');
});

test('a Detail / Special Event request reads with its typed event; units read as their label', () => {
  assert.equal(logic.targetText({ target_kind: 'detail_event', target_label: 'Detail / Special Event', event_assignment: 'Ultra Music Fest' }), 'Detail / Special Event: Ultra Music Fest');
  assert.equal(logic.targetText({ target_kind: 'detail_event', target_label: 'Detail / Special Event', event_assignment: '  ' }), 'Detail / Special Event');
  assert.equal(logic.targetText({ target_kind: 'rescue_unit', target_label: 'Rescue 2', event_assignment: null }), 'Rescue 2');
  assert.equal(logic.memberText({ submitter_rank: 'Paramedic', submitter_name: 'Pat Medic' }), 'Paramedic Pat Medic');
});

test('lines are grouped by bag and then by the section they sit in, in form order', () => {
  const groups = logic.groupLines([
    line({ id: 1, bag_key: 'airway-bag', section_label: 'Top Compartment', label: 'Stethoscope' }),
    line({ id: 2, bag_key: 'als-med-bag', section_label: 'Trauma', label: 'Gauze' }),
    line({ id: 3, bag_key: 'als-med-bag', section_label: 'Trauma', label: 'Tape' }),
    line({ id: 4, bag_key: 'als-med-bag', section_label: null, label: 'Loose item' }),
  ]);
  assert.deepEqual(groups.map((g) => g.bagLabel), ['Airway Bag', 'ALS Med Bag or Box']);
  assert.deepEqual(groups[1].sections.map((s) => [s.label, s.lines.length]), [['Trauma', 2], ['Other items', 1]]);
});

test('a line reads as requested and carries its flags', () => {
  const narcs = line({ response_type: 'yes_no', requested_qty: null, requested_flag: true, controlled_substance: true });
  assert.equal(logic.requestedText(narcs), 'Yes');
  assert.deepEqual(logic.lineFlags(narcs), ['Controlled item']);
});

test('a correction shows the quantity an issuance stands at now, replaying corrections in order', () => {
  const history = [
    event(10, 'issued', { label: 'Gauze 4x4', quantity: 6, supply_item_id: 7, supply_item_name: 'Gauze 4x4 (A2)', stock_effect: 'applied' }),
    event(11, 'issued', { label: 'Tape', quantity: 2, stock_effect: 'unmatched' }, { request_line_id: 2 }),
    event(12, 'issue_corrected', { label: 'Gauze 4x4', corrects_event_id: 10, previous_quantity: 6, new_quantity: 5, supply_item_id: 7, stock_effect: 'applied' }),
    event(13, 'issue_corrected', { label: 'Gauze 4x4', corrects_event_id: 10, previous_quantity: 5, new_quantity: 4, supply_item_id: 7, stock_effect: 'applied' }),
  ];
  const [gauze, tape] = logic.currentIssuances([...history].reverse());
  assert.deepEqual([gauze.eventId, gauze.quantity, gauze.originalQuantity, gauze.corrected, gauze.supplyItemName], [10, 4, 6, true, 'Gauze 4x4 (A2)']);
  assert.deepEqual([tape.eventId, tape.quantity, tape.corrected, tape.stockEffect], [11, 2, false, 'unmatched']);
});

test('a correction that changes the item drops the old item name', () => {
  const [issuance] = logic.currentIssuances([
    event(1, 'issued', { label: 'Gauze 4x4', quantity: 2, supply_item_id: 7, supply_item_name: 'Gauze A', stock_effect: 'applied' }),
    event(2, 'issue_corrected', { corrects_event_id: 1, new_quantity: 2, supply_item_id: 9, stock_effect: 'applied' }),
  ]);
  assert.equal(issuance.supplyItemId, 9);
  assert.equal(issuance.supplyItemName, null);
});

test('what an issue did to stock is told in the words the crew uses', () => {
  assert.equal(logic.stockEffectText('applied'), 'Stock updated');
  assert.equal(logic.stockEffectText('none'), 'Recorded only (controlled item)');
  assert.equal(logic.stockEffectText('unmatched'), 'Recorded only');
});

test('an idempotency key is reused for the same content and replaced when the content changes or the action is confirmed', () => {
  let n = 0;
  const keeper = logic.createKeyKeeper(() => `key-${++n}`);
  assert.equal(keeper.keyFor('a'), 'key-1');
  assert.equal(keeper.keyFor('a'), 'key-1', 'a retry sends the same key');
  assert.equal(keeper.keyFor('b'), 'key-2', 'different content is a different action');
  keeper.reset();
  assert.equal(keeper.keyFor('b'), 'key-3', 'after the server confirmed, the next action is new');
});

test('event sentences are plain and name the reason', () => {
  assert.equal(logic.eventSentence(event(1, 'closed', { reason: 'Issued in full' })), 'Closed the request. Reason: Issued in full');
  assert.equal(logic.eventSentence(event(2, 'issue_corrected', { label: 'Gauze', previous_quantity: 6, new_quantity: 5, reason: 'Miscount' })), 'Corrected Gauze from 6 to 5. Reason: Miscount');
  assert.equal(logic.eventSentence(event(3, 'issued', { label: 'Tape', quantity: 3, over_issue: true, reason: 'Crew short' })), 'Issued 3 of Tape. More than was requested. Reason: Crew short');
});

test('whole numbers: counts may run past 999 and anything else is refused', () => {
  assert.equal(logic.parseWhole('1200', 0, 999_999), 1200, 'counts may run past 999');
  assert.equal(logic.parseWhole('  '), null);
  assert.ok(Number.isNaN(logic.parseWhole('1000000', 0, 999_999)));
  assert.ok(Number.isNaN(logic.parseWhole('12a')));
});
