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

test('the Open chip asks for the four working statuses and nothing else', () => {
  const query = new URLSearchParams(logic.requestListQuery(logic.EMPTY_FILTERS));
  assert.equal(query.get('status'), 'submitted,reviewed,prepared,partially_issued');
  assert.equal(query.get('page'), '1');
  assert.equal(query.get('per_page'), '25');
  assert.equal(logic.OPEN_COUNT_QUERY, 'status=submitted,reviewed,prepared,partially_issued&per_page=1');
});

test('Issued, Closed and All chips map to their statuses; Closed includes cancelled', () => {
  const status = (chip) => new URLSearchParams(logic.requestListQuery({ ...logic.EMPTY_FILTERS, chip })).get('status');
  assert.equal(status('issued'), 'issued');
  assert.equal(status('closed'), 'closed,cancelled');
  assert.equal(status('all'), null);
});

test('unit, dates and search are sent only when set; search is trimmed; a bad range is reported', () => {
  const query = new URLSearchParams(logic.requestListQuery({ chip: 'all', unit: 'detail', dateFrom: '2026-10-01', dateTo: '2026-10-23', q: '  MIR-12 ', page: 3 }));
  assert.equal(query.get('unit'), 'detail');
  assert.equal(query.get('date_from'), '2026-10-01');
  assert.equal(query.get('date_to'), '2026-10-23');
  assert.equal(query.get('q'), 'MIR-12');
  assert.equal(query.get('page'), '3');
  assert.equal(logic.dateRangeError('2026-10-23', '2026-10-01'), 'The end date cannot be before the start date.');
  assert.equal(logic.dateRangeError('2026-10-01', ''), null);
});

test('times are shown in Eastern time, with the zone', () => {
  assert.equal(logic.etDateTime('2026-10-23T18:05:00Z'), 'Oct 23, 2:05 PM EDT');
  assert.equal(logic.etDateTime('2026-01-15T18:05:00Z'), 'Jan 15, 1:05 PM EST');
  assert.equal(logic.etDateTime(null), 'Unknown time');
  assert.equal(logic.etYmd(new Date('2026-10-24T02:30:00Z')), '2026-10-23');
});

test('the statistics window defaults to the last 90 days ending today (Eastern)', () => {
  assert.deepEqual(logic.defaultStatsRange(new Date('2026-10-23T16:00:00Z')), { from: '2026-07-26', to: '2026-10-23' });
  assert.equal(logic.addDaysYmd('2026-03-01', -1), '2026-02-28');
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

test('requested, outstanding and what can be done to a line', () => {
  const gauze = line({ requested_qty: 10, issued_qty: 4 });
  assert.equal(logic.wantedQty(gauze), 10);
  assert.equal(logic.outstandingQty(gauze), 6);
  assert.equal(logic.outstandingQty(line({ line_status: 'not_issued', requested_qty: 5 })), 0);
  const narcs = line({ response_type: 'yes_no', requested_qty: null, requested_flag: true, controlled_substance: true });
  assert.equal(logic.requestedText(narcs), 'Yes');
  assert.equal(logic.wantedQty(narcs), 1);
  assert.deepEqual(logic.lineFlags(narcs), ['Controlled item']);
  assert.equal(logic.lineCanIssue(line({ response_type: 'yes_no', requested_qty: null, requested_flag: false })), false);
  assert.equal(logic.lineCanBeMarkedNotIssued(line({ line_status: 'issued' })), false);
  assert.equal(logic.lineCanBeMarkedNotIssued(gauze), true);
});

test('issuing: the payload carries line, quantity, chosen item and reason, and only for lines that were filled', () => {
  const lines = [line({ id: 1, requested_qty: 10 }), line({ id: 2, label: 'Tape', requested_qty: 2 })];
  const { payload, errors } = logic.buildIssuePayload(lines, { 1: { quantity: '4', supplyItemId: '12', reason: '' }, 2: { quantity: '', supplyItemId: '', reason: '' } });
  assert.deepEqual(errors, {});
  assert.deepEqual(payload, { issuances: [{ line_id: 1, quantity: 4, supply_item_id: 12 }] });
});

test('issuing more than requested needs a reason; more than one candidate needs a choice', () => {
  const gauze = line({ id: 1, requested_qty: 10, issued_qty: 8, supply_candidates: [{ id: 1, name: 'Gauze 4x4' }, { id: 2, name: 'Gauze Sterile' }] });
  const noReason = logic.buildIssuePayload([gauze], { 1: { quantity: '5', supplyItemId: '1', reason: '' } });
  assert.equal(noReason.payload, null);
  assert.match(noReason.errors['1'], /Give a reason to issue extra/);
  const noChoice = logic.buildIssuePayload([gauze], { 1: { quantity: '1', supplyItemId: '', reason: '' } });
  assert.equal(noChoice.payload, null);
  assert.match(noChoice.errors['1'], /choose which supply room item/);
  const recordOnly = logic.buildIssuePayload([gauze], { 1: { quantity: '5', supplyItemId: 'none', reason: 'Crew short on a call' } });
  assert.deepEqual(recordOnly.payload, { issuances: [{ line_id: 1, quantity: 5, reason: 'Crew short on a call' }] });
  assert.match(logic.buildIssuePayload([gauze], {}).errors.form, /at least one line/);
  assert.match(logic.buildIssuePayload([gauze], { 1: { quantity: '0', supplyItemId: '1', reason: '' } }).errors['1'], /whole number from 1 to 999/);
});

test('preparing sends only the quantities that changed; blank clears', () => {
  const lines = [line({ id: 1, prepared_qty: 3 }), line({ id: 2, prepared_qty: null })];
  assert.deepEqual(logic.buildPreparePayload(lines, { 1: '3', 2: '5' }).payload, { lines: [{ line_id: 2, prepared_qty: 5 }] });
  assert.deepEqual(logic.buildPreparePayload(lines, { 1: '', 2: '' }).payload, { lines: [{ line_id: 1, prepared_qty: null }] });
  assert.match(logic.buildPreparePayload(lines, { 1: '3', 2: '' }).errors.form, /Change at least one/);
  assert.equal(logic.buildPreparePayload(lines, { 1: '12x', 2: '' }).payload, null);
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

test('correcting needs a new quantity, a reason, and a real change', () => {
  const issuance = { eventId: 10, lineId: 1, label: 'Gauze 4x4', quantity: 4, originalQuantity: 6, supplyItemId: 7, supplyItemName: null, stockEffect: 'applied', corrected: true, actor: null, at: null };
  assert.deepEqual(logic.buildCorrectionPayload(issuance, '3', '', 'Counted wrong').payload, { event_id: 10, new_quantity: 3, reason: 'Counted wrong' });
  assert.deepEqual(logic.buildCorrectionPayload(issuance, '4', '9', 'Wrong item used').payload, { event_id: 10, new_quantity: 4, supply_item_id: 9, reason: 'Wrong item used' });
  assert.match(logic.buildCorrectionPayload(issuance, '4', '', 'No change').errors.new_quantity, /already the recorded quantity/);
  assert.match(logic.buildCorrectionPayload(issuance, '', '', 'x').errors.reason, /Give a reason/);
  assert.match(logic.buildCorrectionPayload(issuance, '', '', 'Because').errors.new_quantity, /correct quantity/);
});

test('what an issue did to stock is told in the two words the crew uses', () => {
  const before = [event(1, 'reviewed')];
  const after = [
    ...before,
    event(2, 'issued', { label: 'Gauze 4x4', quantity: 2, supply_item_id: 7, supply_item_name: 'Gauze 4x4', stock_effect: 'applied' }),
    event(3, 'issued', { label: 'Tape', quantity: 1, stock_effect: 'unmatched' }, { request_line_id: 2 }),
  ];
  const added = logic.newIssuances(before, after);
  assert.deepEqual(added.map(logic.outcomeText), ['Stock updated: Gauze 4x4 (2) from Gauze 4x4', 'Recorded only: Tape (1)']);
  assert.equal(logic.stockEffectText('none'), 'Recorded only (controlled item)');
  assert.deepEqual(logic.newIssuances(after, after), []);
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

const item = (overrides = {}) => ({
  id: 1, name: 'Gauze 4x4', location_code: 'A2', base_unit: 'each', package_unit: 'box', units_per_package: 200, packages_per_case: null,
  threshold_qty_base: 50, threshold_source_text: '< 50U', current_quantity: 120, below_threshold: false, needs_review: false, controlled_substance: false, ...overrides,
});

test('stock states: below threshold outranks needs review; controlled items are never stock', () => {
  assert.equal(logic.stockState(item({ below_threshold: true, needs_review: true })), 'below');
  assert.equal(logic.stockState(item({ needs_review: true })), 'review');
  assert.equal(logic.stockState(item({ current_quantity: null, below_threshold: null })), 'uncounted');
  assert.equal(logic.stockState(item()), 'ok');
  assert.equal(logic.stockState(item({ controlled_substance: true, below_threshold: true })), 'controlled');
  const split = logic.splitControlled([item(), item({ id: 2, controlled_substance: true })]);
  assert.deepEqual([split.tracked.length, split.controlled.length], [1, 1]);
  assert.equal(logic.stockText(item({ current_quantity: null })), 'Not counted yet');
  assert.equal(logic.thresholdText(item()), 'Reorder below 50 (source says < 50U)');
  assert.equal(logic.packageText(item({ package_unit: 'case', units_per_package: null })), 'Case, size not stated');
  assert.equal(logic.packageText(item({ package_unit: 'box', units_per_package: 24, packages_per_case: 12 })), 'Box of 24, 12 per case');
});

test('cases and boxes are offered only where the size is stated', () => {
  assert.deepEqual(logic.countFields(item({ package_unit: 'box', units_per_package: 24 })), ['boxes', 'each']);
  assert.deepEqual(logic.countFields(item({ package_unit: 'case', units_per_package: 10 })), ['cases', 'each']);
  assert.deepEqual(logic.countFields(item({ package_unit: 'box', units_per_package: 24, packages_per_case: 6 })), ['cases', 'boxes', 'each']);
  assert.deepEqual(logic.countFields(item({ package_unit: 'case', units_per_package: null })), ['each']);
  assert.deepEqual(logic.countFields(item({ package_unit: 'each', units_per_package: null })), ['each']);
});

test('typed counts convert only with an explicit factor, and never guess', () => {
  const box = item({ package_unit: 'box', units_per_package: 24, packages_per_case: 6 });
  assert.equal(logic.convertEntry(box, { each: 5, boxes: 2 }), 53);
  assert.equal(logic.convertEntry(box, { cases: 1, boxes: 1, each: 1 }), 6 * 24 + 24 + 1);
  assert.equal(logic.convertEntry(item({ package_unit: 'case', units_per_package: null }), { cases: 2 }), null);
  assert.equal(logic.convertEntry(item({ package_unit: 'case', units_per_package: 10 }), { boxes: 2 }), null);
});

test('autosave sends only the rows whose typed text differs from what the server holds; a cleared row goes blank', () => {
  const rows = [
    { id: 1, name: 'Tape', location_code: 'A2', package_unit: 'box', units_per_package: 24, packages_per_case: null, entry: { each: 30 }, quantity_base: 30, warnings: [] },
    { id: 2, name: 'Gauze', location_code: 'A2', package_unit: 'box', units_per_package: 200, packages_per_case: null, entry: null, quantity_base: null, warnings: [] },
  ];
  const changes = logic.countChanges(rows, { 1: { each: '30' }, 2: { boxes: '3', each: '' } });
  assert.deepEqual(changes.lines, [{ supply_item_id: 2, cases: null, boxes: 3, each: null }]);
  assert.deepEqual(logic.countChanges(rows, { 1: { each: '' } }).lines, [{ supply_item_id: 1, cases: null, boxes: null, each: null }]);
  const bad = logic.countChanges(rows, { 2: { each: '12a' } });
  assert.deepEqual(bad.lines, []);
  assert.match(bad.errors['2'], /whole numbers/);
  assert.equal(logic.parseWhole('1200', 0, 999_999), 1200, 'counts may run past 999');
  assert.ok(Number.isNaN(logic.parseWhole('1000000', 0, 999_999)));
});

test('reorder advice words: source amount, worked-out amount, and unknown size', () => {
  const base = { package_unit: 'box', order_amount_text: '1BX = 200U' };
  assert.equal(logic.suggestedOrderText({ ...base, suggested_basis: 'source_order_amount', suggested_packages: 1 }), "1 box (the order sheet's amount: 1BX = 200U)");
  assert.equal(logic.suggestedOrderText({ ...base, suggested_basis: 'rounded_up_to_packages', suggested_packages: 3 }), '3 boxes (worked out from how far below the threshold it is)');
  assert.match(logic.suggestedOrderText({ ...base, suggested_basis: 'unknown_package_size', suggested_packages: null }), /decide the amount by hand/);
});

test('reconciliation rows and warnings read plainly; downloads point at the staff export routes', () => {
  assert.equal(logic.reconcileDelta({ issued: 4, received: 0, adjusted: -2 }), 'issued 4, received 0, adjusted -2');
  assert.equal(logic.warningText('unmatched_issuances'), 'Issued with no matching item');
  assert.equal(logic.warningText('something_new'), 'something new');
  assert.equal(logic.snapshotExportUrl('0f8fad5b-d9cb-469f-a165-70867728950e', 'pdf'), '/ems/api/supply/snapshots/0f8fad5b-d9cb-469f-a165-70867728950e/export/pdf');
  assert.equal(logic.movementText({ movement_type: 'issued', quantity_base: -4 }), 'Issued -4');
  assert.equal(logic.movementText({ movement_type: 'received', quantity_base: 48 }), 'Received +48');
});

test('statistics: the query carries dates, unit and kind; the footnote and per-week wording are fixed', () => {
  const query = new URLSearchParams(logic.statsQuery({ from: '2026-07-26', to: '2026-10-23' }, '12', 'rescue_unit'));
  assert.equal(query.get('date_from'), '2026-07-26');
  assert.equal(query.get('apparatus_id'), '12');
  assert.equal(query.get('target_kind'), 'rescue_unit');
  assert.equal(new URLSearchParams(logic.statsQuery({ from: '', to: '' }, 'detail', '')).has('apparatus_id'), false);
  assert.equal(logic.STATS_FOOTNOTE, 'These figures count requests and issuances, not supplies used on calls.');
  assert.equal(logic.perWeekText(1.5), '1.5 per week');
  assert.equal(logic.perWeekText(2), '2 per week');
  assert.equal(logic.perWeekText(0.33), '0.33 per week');
  assert.equal(logic.monthLabel('2026-03'), 'Mar 2026');
});
