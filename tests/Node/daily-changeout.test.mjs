import assert from 'node:assert/strict';
import test from 'node:test';
import {
  alertActionPath,
  applyTruckDefaults,
  buildSubmission,
  dailyRouterPath,
  effectiveChoice,
  equipmentCounts,
  isAttentionLine,
  normalizeChoice,
  reconcileDraft,
  resolveTruck,
  stationReturnPath,
  truckNeedsFleet,
  truckProblem,
  waitingNames,
  withLineChoice,
} from '../../resources/js/daily-checkout/src/components/changeout/changeoutLogic.ts';
import {
  parseAlerts,
  parseChangeoutHandoff,
  parseConflictDetails,
  parseContext,
  parseSummary,
} from '../../resources/js/daily-checkout/src/components/changeout/changeoutParse.ts';
import {
  CHANGEOUT_DRAFT_MAX_AGE_MS,
  clearChangeoutDraft,
  loadChangeoutDraft,
  loadContextSnapshot,
  saveChangeoutDraft,
  saveContextSnapshot,
} from '../../resources/js/daily-checkout/src/components/changeout/changeoutDraft.ts';

/**
 * The rules behind the Daily changeout wizard (docs/apparatus-changeouts.md): what a line defaults to, what is sent
 * and when a truck needs Fleet. Plain TypeScript with no framework, so it runs here against a small localStorage fake.
 */

const line = (key, overrides = {}) => ({
  item_key: key,
  name: key,
  expected_quantity: null,
  controlled: false,
  default_handling: 'transfer',
  previous: null,
  ...overrides,
});

const rawContext = (overrides = {}) => ({
  unit: { apparatus_id: 52, designation: 'R 44', slug: 'r44', vehicle_number: '17503', station_id: 32, station_label: 'Station 4', status: 'In Service' },
  alert: { id: 12, title: 'Upcoming', reason: 'Exhaust repair', schedule_label: 'Sunday', vehicle_number: '17503' },
  mode: 'start',
  candidates: [
    { apparatus_id: 57, vehicle_number: '1033', label: 'Rescue 1033', location: 'Station 2', status: 'Available', direct: true, is_return: false },
    { apparatus_id: 59, vehicle_number: '002-16', label: 'Rescue 2', location: 'Station 3', status: 'Reserve', direct: false, is_return: false },
  ],
  other_vehicles: [{ apparatus_id: 58, vehicle_number: '1034', label: 'Rescue 1034', location: 'Station 2', status: 'In Service' }],
  equipment: {
    checklist_version: 'a'.repeat(64),
    usable: true,
    compartments: [{
      id: 'front_cab',
      name: 'Front Cab',
      items: [
        line('radio', { name: 'Portable radio', expected_quantity: 2 }),
        line('spare', { name: 'Spare bag' }),
        line('field:seal', { name: 'Narcotic Seal #', controlled: true, default_handling: 'review' }),
      ],
    }],
  },
  open_issues_on_truck: 3,
  previous_changeout: null,
  pending_changeout: null,
  can_fleet_commit: false,
  ...overrides,
});

const context = (overrides) => parseContext(rawContext(overrides));
const radio = (ctx) => ctx.equipment.compartments[0].items[0];
const spare = (ctx) => ctx.equipment.compartments[0].items[1];

const draft = (overrides = {}) => ({
  version: 1,
  apparatusId: 52,
  userId: 401,
  alertId: 12,
  clientSubmissionId: '11111111-1111-4111-8111-111111111111',
  step: 'truck',
  choice: { kind: 'listed', apparatusId: 57, vehicleNumber: '1033' },
  typedVehicle: '',
  exceptions: {},
  note: '',
  attempted: false,
  savedAt: 0,
  ...overrides,
});

const ok = { handling: 'transfer', quantity: null, condition: 'ok', note: '' };

test('vehicle numbers stay strings, and an unknown quantity stays unknown', () => {
  const ctx = context({ candidates: [{ apparatus_id: 57, vehicle_number: 1033, direct: true }, { apparatus_id: 59, vehicle_number: '002-16', direct: true }] });
  assert.equal(ctx.candidates[0].vehicle_number, '1033');
  assert.equal(ctx.candidates[1].vehicle_number, '002-16');
  assert.equal(spare(ctx).expected_quantity, null);
  assert.equal(effectiveChoice(spare(ctx), {}).quantity, null);
});

test('a template using title instead of name still has a compartment name', () => {
  const ctx = context({ equipment: { usable: true, compartments: [{ id: 'a', title: 'Rear', items: [] }] } });
  assert.equal(ctx.equipment.compartments[0].name, 'Rear');
});

test('a line back at its default is not an exception', () => {
  const ctx = context();
  const changed = withLineChoice({}, spare(ctx), { ...ok, handling: 'leave' });
  assert.deepEqual(Object.keys(changed), ['spare']);
  assert.deepEqual(withLineChoice(changed, spare(ctx), ok), {});
});

test('a full count is no partial quantity, and a count is never kept on a line that is not moving in good condition', () => {
  const ctx = context();
  assert.equal(normalizeChoice(radio(ctx), { ...ok, quantity: 2 }).quantity, null);
  assert.equal(normalizeChoice(radio(ctx), { ...ok, quantity: 1 }).quantity, 1);
  assert.equal(normalizeChoice(radio(ctx), { ...ok, quantity: 9 }).quantity, null);
  assert.equal(normalizeChoice(radio(ctx), { ...ok, quantity: 1, handling: 'leave' }).quantity, null);
  assert.equal(normalizeChoice(radio(ctx), { ...ok, quantity: 1, condition: 'damaged' }).quantity, null);
  assert.equal(normalizeChoice(radio(ctx), { ...ok, quantity: 1, condition: 'missing' }).quantity, null);
  assert.equal(normalizeChoice(spare(ctx), { ...ok, quantity: 1 }).quantity, null);
});

test('a missing or damaged item is not counted as moving, and controlled lines never count', () => {
  const ctx = context();
  assert.deepEqual(equipmentCounts(ctx, {}), { total: 2, moving: 2, controlled: 1 });
  assert.equal(equipmentCounts(ctx, { spare: { ...ok, condition: 'missing' } }).moving, 1);
  assert.equal(equipmentCounts(ctx, { spare: { ...ok, condition: 'damaged' } }).moving, 1);
});

test('the submission names only exceptions, in a stable order, and carries the truck and unit it was built on', () => {
  const ctx = context();
  const body = buildSubmission(draft({
    note: '  Swap for exhaust  ',
    exceptions: {
      spare: { ...ok, handling: 'leave', note: ' Stays ' },
      radio: { ...ok, quantity: 1 },
      gone: { ...ok, handling: 'leave' },
      'field:seal': { ...ok, handling: 'transfer' },
    },
  }), ctx);

  assert.deepEqual(body, {
    client_submission_id: '11111111-1111-4111-8111-111111111111',
    expected_vehicle_number: '17503',
    expected_unit_slug: 'r44',
    alert_id: 12,
    to_apparatus_id: 57,
    to_vehicle_number: null,
    checklist_version: 'a'.repeat(64),
    exceptions: [
      { item_key: 'radio', handling: 'transfer', quantity: 1, condition: 'ok', note: null },
      { item_key: 'spare', handling: 'leave', quantity: null, condition: 'ok', note: 'Stays' },
    ],
    note: 'Swap for exhaust',
  });
});

test('a typed number is matched exactly as a label: 002-16 is not 2-16', () => {
  const ctx = context();
  assert.equal(resolveTruck(draft({ choice: { kind: 'typed' }, typedVehicle: '002-16' }), ctx).apparatusId, 59);
  const typed = resolveTruck(draft({ choice: { kind: 'typed' }, typedVehicle: '2-16' }), ctx);
  assert.equal(typed.apparatusId, null);
  assert.equal(typed.vehicleNumber, '2-16');
  const body = buildSubmission(draft({ choice: { kind: 'typed' }, typedVehicle: ' 2-16 ' }), ctx);
  assert.equal(body.to_apparatus_id, null);
  assert.equal(body.to_vehicle_number, '2-16');
});

test('the truck step says what is wrong in words', () => {
  const ctx = context();
  assert.match(truckProblem(draft({ choice: null }), ctx), /Choose the truck/);
  assert.match(truckProblem(draft({ choice: { kind: 'typed' }, typedVehicle: '' }), ctx), /Enter the truck number/);
  assert.match(truckProblem(draft({ choice: { kind: 'typed' }, typedVehicle: '<b>9' }), ctx), /Letters, numbers/);
  assert.match(truckProblem(draft({ choice: { kind: 'typed' }, typedVehicle: '17503' }), ctx), /truck R 44 is on now/);
  assert.equal(truckProblem(draft(), ctx), null);
  assert.equal(truckProblem(draft({ choice: null }), context({ mode: 'handoff_only' })), null);
});

test('who needs Fleet follows direct from the server, whoever is asking and whatever the alert says', () => {
  const member = context();
  const fleet = context({
    can_fleet_commit: true,
    other_vehicles: [{ apparatus_id: 58, vehicle_number: '1034', direct: true }],
  });
  const beforeTheDate = context({
    candidates: [{ apparatus_id: 57, vehicle_number: '1033', direct: false, is_return: false }],
  });
  const pick = (id) => draft({ choice: { kind: 'listed', apparatusId: id, vehicleNumber: 'x' } });
  const typed = draft({ choice: { kind: 'typed' }, typedVehicle: 'NEW-1' });

  assert.equal(truckNeedsFleet(resolveTruck(pick(57), member)), false);
  assert.equal(truckNeedsFleet(resolveTruck(pick(59), member)), true);
  assert.equal(truckNeedsFleet(resolveTruck(pick(58), member)), true);
  assert.equal(truckNeedsFleet(resolveTruck(typed, member)), true);
  assert.equal(truckNeedsFleet(resolveTruck(pick(58), fleet)), false);
  assert.equal(truckNeedsFleet(resolveTruck(typed, fleet)), true);
  assert.equal(beforeTheDate.alert.id, 12);
  assert.equal(truckNeedsFleet(resolveTruck(pick(57), beforeTheDate)), true);
});

test('line defaults from last time apply only on a return to the truck the unit left', () => {
  const previous = (handling, extra = {}) => ({ custody_status: 'left_behind', note: 'Stays with the truck', return_handling: handling, return_quantity: null, ...extra });
  const ctx = context({
    candidates: [
      { apparatus_id: 57, vehicle_number: '1033', direct: true, is_return: false },
      { apparatus_id: 60, vehicle_number: '17503', direct: true, is_return: true },
    ],
    equipment: {
      checklist_version: 'b'.repeat(64),
      usable: true,
      compartments: [{ id: 'c', name: 'C', items: [
        line('left', { previous: previous('leave') }),
        line('unsure', { previous: previous('review') }),
        line('part', { expected_quantity: 3, previous: previous('transfer', { return_quantity: 2 }) }),
        line('field:seal', { controlled: true, previous: { custody_status: 'pending_verification', return_handling: 'review' } }),
      ] }],
    },
  });
  const other = resolveTruck(draft(), ctx);
  const back = resolveTruck(draft({ choice: { kind: 'listed', apparatusId: 60, vehicleNumber: '17503' } }), ctx);

  assert.equal(applyTruckDefaults(ctx, other), ctx);
  const view = applyTruckDefaults(ctx, back);
  const [left, unsure, part, seal] = view.equipment.compartments[0].items;
  assert.equal(left.default_handling, 'leave');
  assert.equal(left.default_note, 'Stays with the truck');
  assert.equal(unsure.default_handling, 'review');
  assert.equal(part.default_quantity, 2);
  assert.equal(seal.default_handling, 'transfer');
  assert.equal(isAttentionLine(left, {}), true);
  assert.deepEqual(equipmentCounts(view, {}), { total: 3, moving: 1, controlled: 1 });
  assert.deepEqual(buildSubmission(draft({ choice: { kind: 'listed', apparatusId: 60, vehicleNumber: '17503' } }), view).exceptions, []);
  // Moving a left-behind line back is a real exception, even though "transfer" is the plain default elsewhere.
  assert.deepEqual(Object.keys(withLineChoice({}, left, ok)), ['left']);
  assert.deepEqual(waitingNames(view, {}), ['field:seal', 'unsure']);
});

test('a saved draft keeps what still applies on a fresh list and names the rest', () => {
  const before = context();
  const after = context({
    candidates: [{ apparatus_id: 59, vehicle_number: '002-16', direct: true }],
    equipment: { checklist_version: 'c'.repeat(64), usable: true, compartments: [{ id: 'x', name: 'X', items: [line('radio', { name: 'Portable radio', expected_quantity: 1 })] }] },
  });
  const result = reconcileDraft(draft({ step: 'review', exceptions: { radio: { ...ok, condition: 'damaged' }, spare: { ...ok, handling: 'leave' } } }), after, before);

  assert.deepEqual(result.dropped, ['Spare bag']);
  assert.equal(result.truckCleared, true);
  assert.equal(result.draft.choice, null);
  assert.equal(result.draft.step, 'truck');
  assert.deepEqual(Object.keys(result.draft.exceptions), ['radio']);
});

test('the Daily route for a summary loses its /daily base, and outside paths are refused', () => {
  assert.equal(dailyRouterPath('/daily/apparatus/r44'), '/apparatus/r44');
  assert.equal(dailyRouterPath('/apparatus/r44'), '/apparatus/r44');
  assert.equal(dailyRouterPath('/daily'), '/');
  assert.equal(dailyRouterPath('//evil.example/x'), null);
  assert.equal(dailyRouterPath('https://evil.example/x'), null);
  assert.equal(dailyRouterPath(null), null);
});

test('only a station page is a way back', () => {
  assert.equal(stationReturnPath('/stations/32', 9), '/stations/32');
  assert.equal(stationReturnPath('/daily/stations/32', 9), '/stations/32');
  assert.equal(stationReturnPath('https://evil.example', 9), '/stations/9');
  assert.equal(stationReturnPath(null, null), '/stations');
});

test('alert actions become wizard or record routes, and none has no route', () => {
  const action = (kind, extra = {}) => ({ kind, label: 'Go', apparatus_id: 52, alert_id: 12, changeout_id: null, ...extra });
  assert.equal(alertActionPath(action('start'), 32), '/changeouts/new/52?alert_id=12&return_to=%2Fstations%2F32');
  assert.equal(alertActionPath(action('handoff'), 32), '/changeouts/new/52?alert_id=12&return_to=%2Fstations%2F32');
  assert.equal(alertActionPath(action('view', { changeout_id: 8 }), 32), '/changeouts/8?return_to=%2Fstations%2F32');
  assert.equal(alertActionPath(action('none'), 32), null);
  assert.equal(alertActionPath(null, 32), null);
});

test('unreadable alerts are skipped and a bad payload is an empty list', () => {
  assert.deepEqual(parseAlerts(null), []);
  assert.deepEqual(parseAlerts({ alerts: 'nope' }), []);
  assert.equal(parseAlerts({ alerts: [{ title: 'no id' }, { id: 3 }, { id: 4, title: 'Fine' }] }).length, 1);
  assert.throws(() => parseContext({ unit: {} }));
  assert.throws(() => parseSummary({ changeout: {} }));
});

test('the changeout-level can_verify opens only unsettled lines on a completed move', () => {
  const lines = [{ id: 1, name: 'Seal', custody_status: 'pending_verification' }, { id: 2, name: 'Radio', custody_status: 'moved' }];
  const done = parseSummary({ changeout: { id: 8, status: 'completed', can_verify: true, lines } });
  assert.deepEqual(done.lines.map((row) => row.can_verify), [true, false]);
  const waiting = parseSummary({ changeout: { id: 8, status: 'pending_fleet', can_verify: true, lines } });
  assert.deepEqual(waiting.lines.map((row) => row.can_verify), [false, false]);
});

test('conflict details read from the flat Hub shape', () => {
  assert.deepEqual(parseConflictDetails({ code: 'CHANGEOUT_STALE', current_vehicle_number: '1033', current_apparatus_id: 57 }), {
    current_vehicle_number: '1033', current_apparatus_id: 57, changeout_id: null,
  });
  assert.equal(parseConflictDetails({ changeout: { id: 9 } }).changeout_id, 9);
  assert.equal(parseConflictDetails({ changeout_id: 7 }).changeout_id, 7);
});

test('an old cached checklist or an empty handoff means no note', () => {
  assert.equal(parseChangeoutHandoff(undefined), null);
  assert.equal(parseChangeoutHandoff(null), null);
  assert.equal(parseChangeoutHandoff({ from_vehicle_number: '17503', left_behind: [] }), null);
  assert.deepEqual(parseChangeoutHandoff({ from_vehicle_number: '002-16', effective_at: 'x', left_behind: [{ name: 'Chair', note: null }, { note: 'no name' }] }), {
    from_vehicle_number: '002-16', effective_at: 'x', left_behind: [{ name: 'Chair', note: null }],
  });
});

test('with no known member nothing is stored or restored: station phones are shared', () => {
  const values = new Map();
  globalThis.localStorage = {
    get length() { return values.size; },
    key: (index) => [...values.keys()][index] ?? null,
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
  assert.equal(saveChangeoutDraft(draft({ userId: null })), false);
  assert.equal(saveContextSnapshot(52, null, context()), false);
  assert.equal(values.size, 0);
  assert.equal(loadChangeoutDraft(52, null), null);
  assert.equal(loadContextSnapshot(52, null), null);
  // A draft that is somehow there under another member stays theirs.
  assert.equal(saveChangeoutDraft(draft({ userId: 401 }), 1), true);
  assert.equal(loadChangeoutDraft(52, null, 1), null);
  assert.equal(values.size, 1);
  clearChangeoutDraft(52, null);
  assert.equal(values.size, 1);
  delete globalThis.localStorage;
});

test('a draft is kept per truck and member, restored whole, and expires', () => {
  const values = new Map();
  globalThis.localStorage = {
    get length() { return values.size; },
    key: (index) => [...values.keys()][index] ?? null,
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
  const now = 1_000_000_000;
  const saved = draft({ exceptions: { spare: { ...ok, handling: 'leave' } } });

  assert.equal(saveChangeoutDraft(saved, now), true);
  assert.equal(saveContextSnapshot(52, 401, context()), true);
  assert.equal(loadChangeoutDraft(52, 402, now), null);
  assert.equal(loadChangeoutDraft(53, 401, now), null);
  const restored = loadChangeoutDraft(52, 401, now);
  assert.equal(restored.clientSubmissionId, saved.clientSubmissionId);
  assert.deepEqual(restored.exceptions, saved.exceptions);
  assert.equal(loadContextSnapshot(52, 401).unit.vehicle_number, '17503');

  assert.equal(loadChangeoutDraft(52, 401, now + CHANGEOUT_DRAFT_MAX_AGE_MS + 1), null);
  assert.equal(loadContextSnapshot(52, 401), null);

  saveChangeoutDraft(saved, now);
  clearChangeoutDraft(52, 401);
  assert.equal(loadChangeoutDraft(52, 401, now), null);

  globalThis.localStorage = { get length() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, getItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); } };
  assert.equal(saveChangeoutDraft(saved, now), false);
  assert.equal(loadChangeoutDraft(52, 401, now), null);
  assert.doesNotThrow(() => clearChangeoutDraft(52, 401));
  delete globalThis.localStorage;
});
