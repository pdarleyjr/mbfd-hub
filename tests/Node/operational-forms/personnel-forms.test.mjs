import assert from 'node:assert/strict';
import test from 'node:test';
import {
  ACCIDENT_FORM_KEY, DRIVER_STEPS, META_SUBJECT, META_SUPERVISOR, META_VEHICLE, MEMO_FORM_KEY, SUPERVISOR_REPORT_FORM_KEY, SUPERVISOR_REPORT_STEPS,
  TYPE_SUPERVISOR, TYPE_VEHICLE, VEHICLE_SUPERVISOR_STEPS, answerLabel, buildPatch, characterCount, classifyFailure, cleanNumber, conflictRows,
  fileNameFromDisposition, firstErrors, formatDate, formatTime, isFieldVisible, isUlid, isUuid, markDirty, memoErrors, memoPayload, mergeAfterSave,
  missingItems, newClientKey, saveStatusLabel, searchForView, settleDirty, signatureExportSize, sortReports, stepForKey, stepsFor, todayIn,
  toggleAnswer, toggleListValue, viewFromDeepLink, viewFromSearch,
} from '../../../resources/js/operational-forms/personnel/personnelLogic.js';

const ULID = '01JBX5K8Q4M2N7P3R6S9T0V1W2';

// Field keys exactly as app/Services/Accidents/AccidentReportSchema.php lists them.
const SCHEMA = {
  driver: ['employee_name', 'dept_div', 'job_classification', 'city_vehicle_number', 'vehicle_type', 'make_and_license', 'accident_date', 'accident_time', 'location', 'passengers',
    'other_party_name', 'other_party_address', 'other_party_phone', 'other_insurance', 'injuries', 'other_vehicle_year', 'other_vehicle_make_tag', 'supervisor_notified', 'police_on_scene',
    'fl_report_made', 'photos_taken', 'person_charged', 'charge', 'witness1_name', 'witness1_address', 'witness1_phone', 'witness2_name', 'witness2_address', 'witness2_phone',
    'damage_other', 'driver_description'],
  driverRequired: ['employee_name', 'accident_date', 'accident_time', 'location', 'injuries', 'supervisor_notified', 'police_on_scene', 'driver_description'],
  supervisor: ['date_of_report', 'driver_years_with_city', 'vehicle_type_experience_years', 'injury_notice', 'hours_on_duty', 'vehicle_condition_form', 'reasonable_observation_form',
    'cdl_drug_test', 'excessive_hours', 'speeding', 'sickness', 'physical_defect', 'alcohol', 'drugs', 'accidents_past_18_months', 'other_factors', 'supervisor_comments'],
  supervisorRequired: ['date_of_report', 'driver_years_with_city', 'vehicle_type_experience_years', 'injury_notice', 'hours_on_duty', 'vehicle_condition_form', 'reasonable_observation_form',
    'cdl_drug_test', 'excessive_hours', 'speeding', 'sickness', 'physical_defect', 'alcohol', 'drugs', 'accidents_past_18_months'],
  report: ['employee_name', 'employee_id', 'division', 'department', 'age', 'classification', 'accident_date', 'accident_time', 'place', 'first_reported_to', 'witnesses', 'description',
    'type_of_injury', 'part_of_body', 'medical_treatment_by', 'losing_time', 'causes', 'cause_another_party_explain', 'cause_other_explain', 'actions', 'action_other_explain',
    'similar_accidents', 'similar_accidents_explain', 'supervisor_title'],
  reportRequired: ['employee_name', 'employee_id', 'accident_date', 'accident_time', 'place', 'first_reported_to', 'description', 'losing_time', 'similar_accidents', 'supervisor_title'],
};

const keys = (steps) => steps.flatMap((step) => step.fields.map((field) => field.key));
const required = (steps) => steps.flatMap((step) => step.fields.filter((field) => field.required && !field.when).map((field) => field.key));

test('every field of each official form appears exactly once, and nothing else', () => {
  assert.deepEqual([...keys(DRIVER_STEPS)].sort(), [...SCHEMA.driver].sort());
  assert.deepEqual([...keys(VEHICLE_SUPERVISOR_STEPS)].sort(), [...SCHEMA.supervisor].sort());
  assert.deepEqual([...keys(SUPERVISOR_REPORT_STEPS)].sort(), [...SCHEMA.report].sort());
  for (const steps of [DRIVER_STEPS, VEHICLE_SUPERVISOR_STEPS, SUPERVISOR_REPORT_STEPS]) assert.equal(new Set(keys(steps)).size, keys(steps).length);
});

test('the required fields match the server submission rules', () => {
  assert.deepEqual(required(DRIVER_STEPS).sort(), [...SCHEMA.driverRequired].sort());
  assert.deepEqual(required(VEHICLE_SUPERVISOR_STEPS).sort(), [...SCHEMA.supervisorRequired].sort());
  assert.deepEqual(required(SUPERVISOR_REPORT_STEPS).sort(), [...SCHEMA.reportRequired].sort());
});

test('only Notice of Injury and the CDL drug test allow N/A', () => {
  const na = VEHICLE_SUPERVISOR_STEPS.flatMap((step) => step.fields).filter((field) => field.kind === 'yes_no_na').map((field) => field.key);
  assert.deepEqual(na.sort(), ['cdl_drug_test', 'injury_notice']);
  assert.ok(DRIVER_STEPS.every((step) => step.fields.every((field) => field.kind !== 'yes_no_na')));
});

test('labels keep the official wording', () => {
  const label = (steps, key) => steps.flatMap((step) => step.fields).find((field) => field.key === key)?.label;
  assert.equal(label(DRIVER_STEPS, 'fl_report_made'), 'Was FL. Traffic Accident Report Made?');
  assert.equal(label(DRIVER_STEPS, 'make_and_license'), 'Make & License Tag of Vehicle');
  assert.equal(label(DRIVER_STEPS, 'driver_description'), "Driver's description of accident");
  assert.equal(label(VEHICLE_SUPERVISOR_STEPS, 'reasonable_observation_form'), 'Did you complete Reasonable Observation Form');
  assert.equal(label(SUPERVISOR_REPORT_STEPS, 'employee_id'), 'ID/Clock No.');
});

test('stepsFor picks the form for the report type and the editable part', () => {
  assert.equal(stepsFor(TYPE_VEHICLE, 'driver'), DRIVER_STEPS);
  assert.equal(stepsFor(TYPE_VEHICLE, 'supervisor'), VEHICLE_SUPERVISOR_STEPS);
  assert.equal(stepsFor(TYPE_SUPERVISOR, 'driver'), SUPERVISOR_REPORT_STEPS);
});

test('deep links round-trip and ignore anything else', () => {
  assert.deepEqual(viewFromSearch(`?form=${ACCIDENT_FORM_KEY}`), { name: 'vehicle' });
  assert.deepEqual(viewFromSearch(`?form=${ACCIDENT_FORM_KEY}&report=${ULID}`), { name: 'report', id: ULID });
  assert.deepEqual(viewFromSearch(`?form=${ACCIDENT_FORM_KEY}&view=mine`), { name: 'mine' });
  assert.deepEqual(viewFromSearch(`?form=${ACCIDENT_FORM_KEY}&report=not-a-ulid`), { name: 'vehicle' });
  assert.deepEqual(viewFromSearch(`?form=${SUPERVISOR_REPORT_FORM_KEY}`), { name: 'supervisor-report' });
  assert.deepEqual(viewFromSearch(`?form=${MEMO_FORM_KEY}`), { name: 'memo' });
  assert.equal(viewFromSearch('?form=narcotics-check'), null);
  assert.equal(viewFromSearch(''), null);
  for (const view of [{ name: 'vehicle' }, { name: 'report', id: ULID }, { name: 'mine' }, { name: 'supervisor-report' }, { name: 'memo' }]) {
    assert.deepEqual(viewFromSearch(searchForView(view)), view);
  }
  assert.equal(searchForView(null), '');
  assert.deepEqual(viewFromDeepLink({ view: 'report', report: ULID }), { name: 'report', id: ULID });
  assert.deepEqual(viewFromDeepLink({ view: 'report', report: 'x' }), { name: 'vehicle' });
  assert.equal(viewFromDeepLink({ view: null, report: null }), null);
  assert.equal(viewFromDeepLink(undefined), null);
  // The supervisor notification link.
  assert.deepEqual(viewFromSearch(`?form=accident-report&report=${ULID.toLowerCase()}`), { name: 'report', id: ULID.toLowerCase() });
});

test('ids: ULIDs and client keys', () => {
  assert.ok(isUlid(ULID));
  assert.ok(!isUlid('81JBX5K8Q4M2N7P3R6S9T0V1W2'));
  assert.ok(!isUlid('01JBX5K8Q4M2N7P3R6S9T0V1WU'));
  const key = newClientKey();
  assert.ok(isUuid(key));
  assert.notEqual(key, newClientKey());
});

test('yes / no / N/A answers', () => {
  assert.equal(answerLabel('yes'), 'Yes');
  assert.equal(answerLabel('no'), 'No');
  assert.equal(answerLabel('na'), 'N/A');
  assert.equal(answerLabel(null), '');
  assert.equal(toggleAnswer('yes', 'yes'), null);
  assert.equal(toggleAnswer('no', 'yes'), 'yes');
  assert.equal(toggleAnswer(null, 'na'), 'na');
});

test('checkbox lists keep the official order and never duplicate', () => {
  const order = ['a', 'b', 'c', 'other'];
  assert.deepEqual(toggleListValue(['c'], 'a', true, order), ['a', 'c']);
  assert.deepEqual(toggleListValue(['a', 'c'], 'a', true, order), ['a', 'c']);
  assert.deepEqual(toggleListValue(['a', 'c'], 'a', false, order), ['c']);
  assert.deepEqual(toggleListValue(undefined, 'other', true, order), ['other']);
  assert.deepEqual(toggleListValue(['zzz'], 'b', true, order), ['b']);
});

test('explanations appear and are required only when their answer calls for them', () => {
  const explain = SUPERVISOR_REPORT_STEPS.flatMap((step) => step.fields).find((field) => field.key === 'cause_another_party_explain');
  assert.equal(isFieldVisible(explain, { causes: ['carelessness'] }), false);
  assert.equal(isFieldVisible(explain, { causes: ['another_party'] }), true);
  const similar = SUPERVISOR_REPORT_STEPS.flatMap((step) => step.fields).find((field) => field.key === 'similar_accidents_explain');
  assert.equal(isFieldVisible(similar, { similar_accidents: 'no' }), false);
  assert.equal(isFieldVisible(similar, { similar_accidents: 'yes' }), true);

  const complete = {
    employee_name: 'A', employee_id: '1', accident_date: '2026-11-05', accident_time: '14:35', place: 'Station 2', first_reported_to: 'Lt.', description: 'x',
    losing_time: 'no', similar_accidents: 'no', supervisor_title: 'Lieutenant', causes: ['other'], actions: [],
  };
  const missing = missingItems(SUPERVISOR_REPORT_STEPS, complete, { [META_SUBJECT]: 4 }).map((item) => item.key);
  assert.deepEqual(missing, ['cause_other_explain']);
  assert.deepEqual(missingItems(SUPERVISOR_REPORT_STEPS, { ...complete, cause_other_explain: 'Squeegee missing' }, { [META_SUBJECT]: 4 }), []);
  assert.deepEqual(missingItems(SUPERVISOR_REPORT_STEPS, { ...complete, cause_other_explain: 'x', similar_accidents: 'yes' }, { [META_SUBJECT]: 4 }).map((i) => i.key), ['similar_accidents_explain']);
});

test('the driver cannot submit without the employee, the vehicle and a supervisor', () => {
  const missing = missingItems(DRIVER_STEPS, {}, { [META_SUBJECT]: 7, [META_VEHICLE]: null, [META_SUPERVISOR]: null });
  assert.deepEqual(missing.filter((item) => item.key.endsWith('_id')).map((item) => [item.stepId, item.key]), [['who', META_VEHICLE], ['supervisor', META_SUPERVISOR]]);
  assert.equal(missing.find((item) => item.key === 'injuries').stepId, 'others');
  assert.ok(missing.every((item) => item.label));
});

test('server errors point at the right step', () => {
  assert.equal(stepForKey(DRIVER_STEPS, 'driver_description'), 'description');
  assert.equal(stepForKey(DRIVER_STEPS, META_VEHICLE), 'who');
  assert.equal(stepForKey(DRIVER_STEPS, META_SUPERVISOR), 'supervisor');
  assert.equal(stepForKey(DRIVER_STEPS, 'signature'), 'review');
  assert.equal(stepForKey(SUPERVISOR_REPORT_STEPS, 'causes.1'), 'cause');
  assert.deepEqual(firstErrors({ 'causes.0': ['Bad cause.'], causes: ['Second.'], location: ['Complete this field before submitting.'] }), { causes: 'Bad cause.', location: 'Complete this field before submitting.' });
  assert.deepEqual(firstErrors(undefined), {});
});

test('the autosave PATCH carries only changed fields; cleared fields become null', () => {
  const body = buildPatch(3, { location: '  1701 Meridian ', injuries: '', causes: [] }, ['location', 'injuries', META_VEHICLE], { [META_VEHICLE]: 12 });
  assert.deepEqual(body, { revision: 3, data: { location: '1701 Meridian', injuries: null }, [META_VEHICLE]: 12 });
  assert.deepEqual(buildPatch(4, {}, [META_SUPERVISOR], { [META_SUPERVISOR]: null }), { revision: 4, [META_SUPERVISOR]: null });
});

test('a field changed again during a save stays dirty; the rest settle', () => {
  let versions = {};
  versions = markDirty(versions, 'location', 1);
  versions = markDirty(versions, 'injuries', 2);
  const sent = { ...versions };
  versions = markDirty(versions, 'location', 3); // typed while the request was in flight
  assert.deepEqual(settleDirty(versions, sent), { location: 3 });
  assert.deepEqual(settleDirty(sent, sent), {});
});

test('merging the server answer keeps in-flight typing and spaces, and takes server-filled details', () => {
  const server = { location: '1701 Meridian', city_vehicle_number: '4512', employee_name: 'A. Example' };
  const local = { location: '1701 Meridian ', injuries: 'None so f' };
  const merged = mergeAfterSave(server, local, ['location'], ['injuries']);
  assert.deepEqual(merged, { location: '1701 Meridian ', city_vehicle_number: '4512', employee_name: 'A. Example', injuries: 'None so f' });
  // A field cleared locally while the save was in flight stays cleared.
  assert.deepEqual(mergeAfterSave({ charge: 'Old' }, { charge: '' }, [], ['charge']), {});
});

test('a conflict lists only the fields that really differ', () => {
  const fields = DRIVER_STEPS.flatMap((step) => step.fields);
  const rows = conflictRows({ location: 'Server lot', police_on_scene: 'yes' }, { location: 'My lot', police_on_scene: 'yes', charge: '' }, ['location', 'police_on_scene', 'charge', META_VEHICLE], fields);
  assert.deepEqual(rows, [{ key: 'location', label: 'Location of Accident', mine: 'My lot', theirs: 'Server lot' }]);
});

test('save states and failures', () => {
  assert.equal(saveStatusLabel('saved'), 'Saved');
  assert.equal(saveStatusLabel('saving'), 'Saving…');
  assert.equal(saveStatusLabel('offline'), 'Offline. Saved on this device');
  assert.equal(saveStatusLabel('conflict'), 'Changed elsewhere');
  assert.equal(saveStatusLabel('idle'), '');
  assert.equal(classifyFailure({ status: 0, network: true }), 'offline');
  assert.equal(classifyFailure({ status: 409 }), 'conflict');
  assert.equal(classifyFailure({ status: 422 }), 'invalid');
  assert.equal(classifyFailure({ status: 419 }), 'signin');
  assert.equal(classifyFailure({ status: 500 }), 'error');
});

test('display helpers', () => {
  assert.equal(formatDate('2026-11-05'), '11/05/2026');
  assert.equal(formatTime('14:35'), '2:35 PM');
  assert.equal(formatTime('00:05'), '12:05 AM');
  assert.equal(formatTime('12:00'), '12:00 PM');
  assert.equal(todayIn(new Date('2026-11-06T03:30:00Z'), 'America/New_York'), '2026-11-05');
  assert.deepEqual(characterCount('abc', 8000), { text: '3 of 8,000 characters', near: false, over: false });
  assert.equal(characterCount('x'.repeat(95), 100).near, true);
  assert.equal(cleanNumber('12 yrs.5.', 20), '12.5');
  assert.equal(cleanNumber('1234', 3), '123');
});

test('the member\'s list shows reports awaiting them first, then drafts, then the newest', () => {
  const list = sortReports([
    { id: 'a', status: 'complete', updated_at: '2026-11-05T10:00:00Z' },
    { id: 'b', status: 'draft', updated_at: '2026-11-01T10:00:00Z' },
    { id: 'c', status: 'awaiting_supervisor', awaiting_you: true, updated_at: '2026-10-01T10:00:00Z' },
    { id: 'd', status: 'complete', updated_at: '2026-11-06T10:00:00Z' },
  ]);
  assert.deepEqual(list.map((item) => item.id), ['c', 'b', 'd', 'a']);
});

test('the exported signature fits 1600 x 800 and is never enlarged', () => {
  assert.deepEqual(signatureExportSize(1020, 540), { width: 1020, height: 540 });
  assert.deepEqual(signatureExportSize(2400, 540), { width: 1600, height: 360 });
  assert.deepEqual(signatureExportSize(1800, 1200), { width: 1200, height: 800 });
  assert.deepEqual(signatureExportSize(0, 0), { width: 2, height: 2 });
});

test('memorandum: required fields per destination and the payload sent', () => {
  const memo = { to: '', from: 'Lieutenant A. Example', date: '2026-11-05', subject: 'Hose tester', body: 'Line one\r\n\r\nLine two  \n' };
  assert.deepEqual(memoErrors(memo, 'support'), {});
  assert.deepEqual(Object.keys(memoErrors(memo, 'other')), ['to']);
  assert.deepEqual(Object.keys(memoErrors({ ...memo, subject: ' ', body: '', date: '' }, 'support')).sort(), ['body', 'date', 'subject']);
  assert.ok(memoErrors({ ...memo, body: 'x'.repeat(20001) }, 'support').body);
  assert.deepEqual(memoPayload(memo, 'support', 'Support Services'), { to: 'Support Services', from: 'Lieutenant A. Example', date: '2026-11-05', subject: 'Hose tester', body: 'Line one\n\nLine two' });
  assert.equal(memoPayload({ ...memo, to: ' Chief of Department ' }, 'other', 'Support Services').to, 'Chief of Department');
});

test('the downloaded file keeps the name the server gave', () => {
  assert.equal(fileNameFromDisposition('attachment; filename="MBFD Memorandum - Hose - 2026-11-05.pdf"', 'x.pdf'), 'MBFD Memorandum - Hose - 2026-11-05.pdf');
  assert.equal(fileNameFromDisposition("attachment; filename*=UTF-8''Memo%20A.pdf", 'x.pdf'), 'Memo A.pdf');
  assert.equal(fileNameFromDisposition(null, 'x.pdf'), 'x.pdf');
});
