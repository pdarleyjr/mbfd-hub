import assert from 'node:assert/strict';
import test from 'node:test';
import { inspectionReadiness } from '../../resources/js/daily-checkout/src/utils/inspectionProgress.ts';
import { checklistUsesVehicleId, defaultVehicleConfirmation } from '../../resources/js/daily-checkout/src/utils/vehicleConfirmation.ts';
import { optionalIdentification, toVehicleIdentificationSubmission } from '../../resources/js/daily-checkout/src/utils/vehicleIdentification.ts';

const compartments = [{ id: 'cab', name: 'Cab', items: [{ id: 'siren', name: 'Siren', status: 'Present', observed: true, inputType: 'checkbox' }] }];
const officer = { name: 'Member', rank: 'Firefighter', shift: 'A', unitNumber: '', vehicleConfirmation: defaultVehicleConfirmation('') };
const meters = { engine_hours: null, miles: null };
const question = (field) => ({ field, label: field, hint: null, input: 'text', max: 32, current_value: null, confirm: false });
const block = {
  note: '',
  can_answer: true,
  kind: 'boat',
  questions_version: 'v1',
  questions: [question('vehicle_number'), question('vin'), question('plate')],
  reasons: [{ code: 'other', label: 'Other', needs_note: true }],
};
const checklist = (extra) => ({ checklist_version: 'a'.repeat(64), schema_version: 2, fields: [], due_tasks: [], compartments, ...extra });
const ready = (data, member = officer, answers = {}) => inspectionReadiness(data, compartments, [], [], member, meters, answers);

test('Fire Boat 6 reaches Review & Sign with no vehicle chosen and no question answered', () => {
  const boat = checklist({ checklist_type: 'fireboat6', vehicle_id_required: false, vehicle_identification: block });

  const readiness = ready(boat);

  assert.equal(readiness.missingVehicle, false);
  assert.equal(readiness.missingIdentification, false);
  assert.equal(readiness.nextStep, 'submit');
  assert.equal(readiness.ready, true);
});

test('a draft saved on the phone before the server sent the flag is still treated as a boat', () => {
  const savedSnapshot = checklist({ checklist_type: 'fireboat6', vehicle_identification: block });

  assert.equal(checklistUsesVehicleId(savedSnapshot), false);
  assert.equal(ready(savedSnapshot).ready, true);
});

test('a real fleet vehicle still has to be confirmed and still answers its open questions', () => {
  const engine = checklist({ checklist_type: 'engine', schema_version: 1 });

  assert.equal(checklistUsesVehicleId(engine), true);
  assert.equal(checklistUsesVehicleId(null), true);
  const unconfirmed = ready(engine, { ...officer, vehicleConfirmation: defaultVehicleConfirmation('17503') });
  assert.equal(unconfirmed.missingVehicle, true);
  assert.equal(unconfirmed.nextStep, 'officer');

  const noNumberOnRecord = ready(engine);
  assert.equal(noNumberOnRecord.missingVehicle, true);

  const asked = checklist({ checklist_type: 'engine', vehicle_identification: { ...block, kind: 'vehicle' } });
  const confirmed = { ...officer, vehicleConfirmation: { ...defaultVehicleConfirmation('17503'), confirmed: true } };
  assert.equal(ready(asked, confirmed).missingIdentification, true);
});

test('the optional boat details never ask for a fleet number and need no reason', () => {
  const details = optionalIdentification(block);

  assert.deepEqual(details.questions.map((entry) => entry.field), ['vin', 'plate']);
  assert.deepEqual(details.reasons, []);
  assert.equal(optionalIdentification({ ...block, questions: [question('vehicle_number')] }), undefined);
  assert.equal(optionalIdentification({ ...block, can_answer: false }), undefined);
  assert.equal(optionalIdentification(undefined), undefined);
});

test('only what the member typed is sent, and a fleet number from an old draft is not', () => {
  const details = optionalIdentification(block);
  const answers = {
    vehicle_number: { mode: 'value', value: 'FB6-1', reason: '', note: '' },
    plate: { mode: 'value', value: 'fl1234ab', reason: '', note: '' },
  };

  assert.deepEqual(toVehicleIdentificationSubmission(details, answers), {
    questions_version: 'v1',
    answers: [{ field: 'plate', value: 'FL1234AB' }],
  });
  assert.deepEqual(toVehicleIdentificationSubmission(details, {}).answers, []);
});
