import assert from 'node:assert/strict';
import test from 'node:test';
import {
  alertActionPath,
  buildSubmission,
  dailyRouterPath,
  equipmentCounts,
  hasWork,
  leaveCompartment,
  leftItems,
  presetChoice,
  realUnitLabel,
  reconcileDraft,
  resolveTruck,
  stationReturnPath,
  takeCompartment,
  toggleLeft,
  truckProblem,
  withLeftNote,
} from '../../resources/js/daily-checkout/src/components/changeout/changeoutLogic.ts';
import {
  isLeftLine,
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
 * The rules behind the Daily changeout wizard: what is sent, what a truck choice means and what stays on the phone.
 * Plain TypeScript with no framework, so it runs here against a small localStorage fake.
 */

const line = (key, overrides = {}) => ({ item_key: key, name: key, expected_quantity: null, previous: null, ...overrides });

const rawContext = (overrides = {}) => ({
  unit: { apparatus_id: 52, designation: 'R 44', slug: 'r44', vehicle_number: '17503', station_id: 32, station_label: 'Station 4', status: 'In Service' },
  alert: { id: 12, title: 'Upcoming', reason: 'Exhaust repair', schedule_label: 'Sunday', vehicle_number: '17503', planned_replacement_apparatus_id: null, changeout_id: null },
  mode: 'start',
  candidates: [
    { apparatus_id: 57, vehicle_number: '1033', label: 'Rescue 1033', location: 'Station 2', status: 'Available', is_return: false },
    { apparatus_id: 59, vehicle_number: '002-16', label: 'Rescue 2', location: 'Station 3', status: 'Reserve', is_return: false },
  ],
  other_vehicles: [{ apparatus_id: 58, vehicle_number: '1034', label: 'Rescue 1034', location: 'Station 2', status: 'In Service', listed_as: 'R 4' }],
  equipment: {
    checklist_version: 'a'.repeat(64),
    usable: true,
    compartments: [{ id: 'front_cab', name: 'Front Cab', items: [line('radio', { name: 'Portable radio', expected_quantity: 2 }), line('spare', { name: 'Spare bag' })] }],
  },
  open_issues_on_truck: 3,
  previous_changeout: null,
  ...overrides,
});

const context = (overrides) => parseContext(rawContext(overrides));

const draft = (overrides = {}) => ({
  version: 2,
  apparatusId: 52,
  userId: 401,
  alertId: 12,
  clientSubmissionId: '11111111-1111-4111-8111-111111111111',
  step: 'truck',
  choice: { kind: 'listed', apparatusId: 57, vehicleNumber: '1033' },
  preselected: false,
  typedVehicle: '',
  left: {},
  note: '',
  attempted: false,
  savedAt: 0,
  ...overrides,
});

test('vehicle numbers stay strings', () => {
  const ctx = context({ candidates: [{ apparatus_id: 57, vehicle_number: 1033 }, { apparatus_id: 59, vehicle_number: '002-16' }] });
  assert.equal(ctx.candidates[0].vehicle_number, '1033');
  assert.equal(ctx.candidates[1].vehicle_number, '002-16');
});

test('a template using title instead of name still has a compartment name', () => {
  const ctx = context({ equipment: { usable: true, compartments: [{ id: 'a', title: 'Rear', items: [] }] } });
  assert.equal(ctx.equipment.compartments[0].name, 'Rear');
});

test('a row switches between Took and Left, and a note lives only on a Left item', () => {
  const left = toggleLeft({}, 'spare');
  assert.deepEqual(left, { spare: '' });
  const noted = withLeftNote(left, 'spare', 'Too big');
  assert.deepEqual(noted, { spare: 'Too big' });
  assert.deepEqual(withLeftNote(noted, 'radio', 'Nope'), noted);
  assert.deepEqual(toggleLeft(noted, 'spare'), {});
  // The input is never changed in place.
  assert.deepEqual(left, { spare: '' });
});

test('the whole-compartment shortcut keeps notes already typed, and undoes as one', () => {
  const [front] = context().equipment.compartments;
  const left = leaveCompartment({ spare: 'Too big', other: 'Elsewhere' }, front);
  assert.deepEqual(left, { spare: 'Too big', other: 'Elsewhere', radio: '' });
  assert.deepEqual(takeCompartment(left, front), { other: 'Elsewhere' });
});

test('counts ignore a saved Left item that is no longer on the list', () => {
  const ctx = context();
  assert.deepEqual(equipmentCounts(ctx, {}), { total: 2, took: 2, left: 0 });
  assert.deepEqual(equipmentCounts(ctx, { spare: '', gone: '' }), { total: 2, took: 1, left: 1 });
  assert.deepEqual(leftItems(ctx, { spare: ' Too big ', gone: '' }), [{ name: 'Spare bag', note: 'Too big' }]);
});

test('the submission names only Left items, in a stable order, and carries the truck and unit it was built on', () => {
  const body = buildSubmission(draft({ note: '  Swap for exhaust  ', left: { spare: ' Stays ', radio: '', gone: '' } }), context());

  assert.deepEqual(body, {
    client_submission_id: '11111111-1111-4111-8111-111111111111',
    expected_vehicle_number: '17503',
    expected_unit_slug: 'r44',
    alert_id: 12,
    to_apparatus_id: 57,
    to_vehicle_number: null,
    checklist_version: 'a'.repeat(64),
    exceptions: [
      { item_key: 'radio', handling: 'leave', note: null },
      { item_key: 'spare', handling: 'leave', note: 'Stays' },
    ],
    note: 'Swap for exhaust',
  });
});

test('any truck keeps the alert: there is no off-plan path', () => {
  const ctx = context({ alert: { id: 12, planned_replacement_apparatus_id: 57 } });
  const body = buildSubmission(draft({ choice: { kind: 'listed', apparatusId: 59, vehicleNumber: '002-16' } }), ctx);
  assert.equal(body.alert_id, 12);
  assert.equal(body.to_apparatus_id, 59);
});

test('a typed number is matched exactly as a label: 002-16 is not 2-16', () => {
  const ctx = context();
  assert.equal(resolveTruck(draft({ choice: { kind: 'typed' }, typedVehicle: '002-16' }), ctx).apparatusId, 59);
  const typed = resolveTruck(draft({ choice: { kind: 'typed' }, typedVehicle: '2-16' }), ctx);
  assert.equal(typed.apparatusId, null);
  assert.equal(typed.source, 'typed');
  const body = buildSubmission(draft({ choice: { kind: 'typed' }, typedVehicle: ' 2-16 ' }), ctx);
  assert.equal(body.to_apparatus_id, null);
  assert.equal(body.to_vehicle_number, '2-16');
});

test('a truck listed as a real unit says so, and a plain reserve label does not', () => {
  const ctx = context();
  assert.equal(resolveTruck(draft({ choice: { kind: 'listed', apparatusId: 58, vehicleNumber: '1034' } }), ctx).listedAs, 'R 4');
  assert.equal(resolveTruck(draft(), ctx).listedAs, null);
  assert.equal(realUnitLabel('Reserve'), null);
  assert.equal(realUnitLabel(null), null);
  assert.equal(realUnitLabel('E 11'), 'E 11');
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

test('the plan or the link can preselect a truck, the link wins, and neither is work to keep', () => {
  const planned = context({ alert: { id: 12, planned_replacement_apparatus_id: 59 } });
  assert.deepEqual(presetChoice(planned, null), { kind: 'listed', apparatusId: 59, vehicleNumber: '002-16' });
  assert.equal(presetChoice(planned, '1034').apparatusId, 58);
  assert.equal(presetChoice(context(), null), null);
  assert.equal(presetChoice(context({ alert: { id: 12, planned_replacement_apparatus_id: 999 } }), null), null);
  assert.equal(presetChoice(context({ mode: 'handoff_only' }), '1033'), null);

  assert.equal(hasWork(draft({ preselected: true })), false);
  assert.equal(hasWork(draft({ preselected: true, choice: { kind: 'typed' }, typedVehicle: 'NEW-9' })), false);
  assert.equal(hasWork(draft({ preselected: true, left: { spare: '' } })), true);
  assert.equal(hasWork(draft()), true);
  assert.equal(hasWork(draft({ choice: null })), false);
});

test('a saved draft keeps Left items that still exist on a fresh list and names the rest', () => {
  const before = context();
  const after = context({
    candidates: [{ apparatus_id: 59, vehicle_number: '002-16' }],
    equipment: { checklist_version: 'c'.repeat(64), usable: true, compartments: [{ id: 'x', name: 'X', items: [line('radio', { name: 'Portable radio' })] }] },
  });
  const result = reconcileDraft(draft({ step: 'review', left: { radio: 'Note', spare: '' } }), after, before);

  assert.deepEqual(result.dropped, ['Spare bag']);
  assert.equal(result.truckCleared, true);
  assert.equal(result.draft.choice, null);
  assert.equal(result.draft.step, 'truck');
  assert.deepEqual(result.draft.left, { radio: 'Note' });
});

test('the Daily route for a summary loses its /daily base, and outside paths are refused', () => {
  assert.equal(dailyRouterPath('/daily/apparatus/r44'), '/apparatus/r44');
  assert.equal(dailyRouterPath('/apparatus/r44'), '/apparatus/r44');
  assert.equal(dailyRouterPath('/daily'), '/');
  assert.equal(dailyRouterPath('//evil.example/x'), null);
  assert.equal(dailyRouterPath('https://evil.example/x'), null);
  assert.equal(dailyRouterPath(null), null);
});

test('only a station page is a way back to the station', () => {
  assert.equal(stationReturnPath('/stations/32', 9), '/stations/32');
  assert.equal(stationReturnPath('/daily/stations/32', 9), '/stations/32');
  assert.equal(stationReturnPath('https://evil.example', 9), '/stations/9');
  assert.equal(stationReturnPath('/apparatus/r44', 9), '/stations/9');
  assert.equal(stationReturnPath(null, null), '/stations');
});

test('alert actions become wizard routes, and anything else has no route', () => {
  const action = (kind, extra = {}) => ({ kind, label: 'Go', apparatus_id: 52, alert_id: 12, ...extra });
  assert.equal(alertActionPath(action('start'), 32), '/changeouts/new/52?alert_id=12&return_to=%2Fstations%2F32');
  assert.equal(alertActionPath(action('handoff'), 32), '/changeouts/new/52?alert_id=12&return_to=%2Fstations%2F32');
  assert.equal(alertActionPath(action('start', { alert_id: null }), 32), '/changeouts/new/52?return_to=%2Fstations%2F32');
  assert.equal(alertActionPath(action('none'), 32), null);
  assert.equal(alertActionPath(null, 32), null);
  assert.equal(parseAlerts({ alerts: [{ id: 1, title: 'A', action: { kind: 'view', changeout_id: 8 } }] })[0].action, null);
});

test('unreadable alerts are skipped and a bad payload is an empty list', () => {
  assert.deepEqual(parseAlerts(null), []);
  assert.deepEqual(parseAlerts({ alerts: 'nope' }), []);
  assert.equal(parseAlerts({ alerts: [{ title: 'no id' }, { id: 3 }, { id: 4, title: 'Fine' }] }).length, 1);
  assert.throws(() => parseContext({ unit: {} }));
  assert.throws(() => parseSummary({ changeout: {} }));
});

test('the summary reads named Left items, a bare count, or lines, and the displaced unit in either shape', () => {
  const named = parseSummary({ changeout: { id: 8, moved: 81, left_behind: [{ name: 'Chair', note: 'Too big' }, { name: 'Bag', note: null }] } });
  assert.deepEqual(named.left_behind, [{ name: 'Chair', note: 'Too big' }, { name: 'Bag', note: null }]);
  assert.equal(named.leftCount, 2);
  assert.equal(named.moved, 81);

  const counted = parseSummary({ id: 8, moved: 3, left_behind: 2 });
  assert.deepEqual(counted.left_behind, []);
  assert.equal(counted.leftCount, 2);

  const fromLines = parseSummary({ id: 8, left_behind: 1, lines: [
    { id: 1, name: 'Chair', custody_status: 'left_behind', note: 'Too big' },
    { id: 2, name: 'Radio', custody_status: 'moved' },
    { id: 3, item_name: 'Bag', handling: 'leave' },
  ] });
  assert.deepEqual(fromLines.lines.map(isLeftLine), [true, false, true]);
  assert.deepEqual(fromLines.left_behind.map((row) => row.name), ['Chair', 'Bag']);

  assert.equal(parseSummary({ id: 8, displaced_unit: 'R 4' }).displaced_unit, 'R 4');
  assert.equal(parseSummary({ id: 8, displaced_unit: { designation: 'R 4' } }).displaced_unit, 'R 4');
  assert.equal(parseSummary({ id: 8 }).displaced_unit, null);
  assert.equal(parseSummary({ id: 8, new_truck_record: true }).new_truck_record, true);
});

test('conflict details read from the flat Hub shape or a current truck object', () => {
  assert.deepEqual(parseConflictDetails({ code: 'CHANGEOUT_STALE', current_vehicle_number: '1033', current_apparatus_id: 57 }), {
    current_vehicle_number: '1033', current_apparatus_id: 57, changeout_id: null,
  });
  assert.deepEqual(parseConflictDetails({ code: 'CHANGEOUT_STALE', current: { vehicle_number: '002-18', apparatus_id: 60 } }), {
    current_vehicle_number: '002-18', current_apparatus_id: 60, changeout_id: null,
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

const fakeStorage = () => {
  const values = new Map();
  globalThis.localStorage = {
    get length() { return values.size; },
    key: (index) => [...values.keys()][index] ?? null,
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };

  return values;
};

test('with no known member nothing is stored or restored: station phones are shared', () => {
  const values = fakeStorage();
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

test('a draft from the earlier shape is not read as this one', () => {
  fakeStorage();
  const old = { ...draft(), version: 1, exceptions: {} };
  delete old.left;
  localStorage.setItem('mbfd_changeout_draft_52_401', JSON.stringify({ ...old, savedAt: 1 }));
  assert.equal(loadChangeoutDraft(52, 401, 1), null);
  delete globalThis.localStorage;
});

test('a draft is kept per truck and member, restored whole, and expires', () => {
  fakeStorage();
  const now = 1_000_000_000;
  const saved = draft({ left: { spare: 'Too big' } });

  assert.equal(saveChangeoutDraft(saved, now), true);
  assert.equal(saveContextSnapshot(52, 401, context()), true);
  assert.equal(loadChangeoutDraft(52, 402, now), null);
  assert.equal(loadChangeoutDraft(53, 401, now), null);
  const restored = loadChangeoutDraft(52, 401, now);
  assert.equal(restored.clientSubmissionId, saved.clientSubmissionId);
  assert.deepEqual(restored.left, saved.left);
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
