import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fieldGroups, fieldValueOutOfRange, normalizeTaskStatus, taskIsCompleted, taskStatusLabel } from '../../resources/js/daily-checkout/src/utils/checklistFields.ts';
import { MAX_PHOTO_BYTES, PHOTO_FORMAT_MESSAGE, PHOTO_REJECTED_MESSAGE, photoProblem } from '../../resources/js/daily-checkout/src/utils/photoFile.ts';
import { inspectionReadiness } from '../../resources/js/daily-checkout/src/utils/inspectionProgress.ts';

const template = JSON.parse(readFileSync(new URL('../../storage/checklists/fireboat6_checklist.json', import.meta.url), 'utf8'));
const byId = new Map(template.fields.map((field) => [field.id, field]));

test('every requested reading and identifier is on the boat under a heading a member would look for', () => {
  const groupOf = (id) => byId.get(id)?.group;
  for (const id of ['fb6-port-engine-hours', 'fb6-star-engine-hours', 'fb6-generator-hours', 'fb6-port-engine-voltage', 'fb6-star-engine-voltage', 'fb6-port-engine-temperature', 'fb6-star-engine-temperature']) {
    assert.equal(groupOf(id), 'Engine & Generator', id);
  }
  assert.equal(groupOf('fb6-fuel-level'), 'Fuel & Operating Readings');
  assert.equal(byId.get('fb6-fuel-level').inputType, 'percentage');
  for (const id of ['fb6-scba-id-1', 'fb6-scba-id-4', 'fb6-scuba-ids', 'fb6-driver-radio-number', 'fb6-engineer-radio-number', 'fb6-officer-radio-number', 'fb6-deck-hand-radio-number', 'fb6-driver-radio-last-four-serial', 'fb6-engineer', 'fb6-officer', 'fb6-deck-hand']) {
    assert.equal(groupOf(id), 'Crew Equipment & Radios', id);
  }
});

test('stable ids survive, and nothing but the date is required', () => {
  assert.equal(new Set(template.fields.map((field) => field.id)).size, template.fields.length);
  for (const id of ['fb6-high-low-tide', 'fb6-driver-raymarine', 'fb6-mbfd-radio-test', 'fb6-vhf-radio-test', 'fb6-driver-crew-signoff']) assert.ok(byId.has(id), id);
  assert.deepEqual(template.fields.filter((field) => field.required).map((field) => field.id), ['inspection_date']);
  assert.notEqual(byId.get('fb6-scuba-ids').name, byId.get('fb6-scba-id-1').name);
});

test('no operating limit is invented: readings refuse only what cannot be a reading', () => {
  for (const field of template.fields) {
    if (field.inputType === 'number') {
      assert.equal(field.min, 0, field.id);
      assert.equal(field.max, undefined, field.id);
    }
  }
  // In the name, so the unit shows wherever the reading does: entry, review, member history and the admin record.
  assert.equal(byId.get('fb6-port-engine-temperature').name, 'Port engine running temperature (°F)');
  assert.equal(byId.get('fb6-star-engine-temperature').name, 'Starboard engine running temperature (°F)');
});

test('groups keep the template order and leave ungrouped fields for the collapsed section', () => {
  const groups = fieldGroups(template.fields);
  assert.deepEqual(groups.map((group) => group.name), ['Engine & Generator', 'Fuel & Operating Readings', 'Electronics & Radio Checks', 'Crew Equipment & Radios']);
  assert.deepEqual(groups[1].fields.map((field) => field.id), ['fb6-fuel-level', 'fb6-high-low-tide']);
  assert.ok(!groups.flatMap((group) => group.fields).some((field) => field.id === 'fb6-driver-crew-signoff'));
  assert.deepEqual(fieldGroups([{ id: 'a', name: 'A', inputType: 'text', required: false }]), [], 'a session issued before the headings has none');
});

test('only a photo the server can store is accepted, before the member signs', () => {
  assert.equal(photoProblem({ type: 'image/jpeg', size: 1000 }), null);
  assert.equal(photoProblem({ type: 'image/webp', size: 1000 }), null);
  assert.equal(photoProblem({ type: 'image/heic', size: 1000 }), PHOTO_FORMAT_MESSAGE);
  assert.equal(photoProblem({ type: 'image/tiff', size: 1000 }), PHOTO_FORMAT_MESSAGE);
  assert.equal(photoProblem({ type: 'application/pdf', size: 1000 }), PHOTO_REJECTED_MESSAGE);
  assert.equal(photoProblem({ type: 'image/png', size: MAX_PHOTO_BYTES + 1 }), PHOTO_REJECTED_MESSAGE);
});

test('a duty on an older draft is sent as what the screen shows, so it cannot file an equipment defect', () => {
  assert.equal(normalizeTaskStatus('Missing'), 'Not Completed');
  assert.equal(normalizeTaskStatus('Damaged'), 'Not Completed');
  assert.equal(normalizeTaskStatus('Present'), 'Completed');
  assert.equal(normalizeTaskStatus('Not Completed'), 'Not Completed');
});

test('a duty reads as completed or not, including one answered on an older draft', () => {
  assert.equal(taskStatusLabel('Completed'), 'Completed');
  assert.equal(taskStatusLabel('Present'), 'Completed');
  assert.equal(taskStatusLabel('Not Completed'), 'Not Completed');
  assert.equal(taskStatusLabel('Missing'), 'Not Completed');
  assert.equal(taskStatusLabel('Damaged'), 'Not Completed');
  assert.equal(taskIsCompleted('Not Completed'), false);
});

test('an impossible reading holds the member on Details, and a blank one does not', () => {
  const number = byId.get('fb6-port-engine-voltage');
  const percent = byId.get('fb6-fuel-level');
  assert.equal(fieldValueOutOfRange(number, -1), true);
  assert.equal(fieldValueOutOfRange(number, 0), false);
  assert.equal(fieldValueOutOfRange(number, null), false);
  assert.equal(fieldValueOutOfRange(percent, 101), true);
  assert.equal(fieldValueOutOfRange(percent, 100), false);

  const compartments = [{ id: 'a', name: 'A', items: [{ id: 'i', name: 'I', status: 'Present', observed: true, inputType: 'checkbox' }] }];
  const checklist = { checklist_type: 'fireboat6', vehicle_id_required: false, checklist_version: 'a'.repeat(64), schema_version: 2, fields: [number, percent], due_tasks: [], compartments };
  const officer = { name: 'M', rank: 'Firefighter', shift: 'A', unitNumber: '' };
  const meters = { engine_hours: null, miles: null };
  const blank = [{ id: number.id, value: null }, { id: percent.id, value: null }];
  assert.equal(inspectionReadiness(checklist, compartments, blank, [], officer, meters).ready, true);
  const wrong = [{ id: number.id, value: -5 }, { id: percent.id, value: null }];
  assert.equal(inspectionReadiness(checklist, compartments, wrong, [], officer, meters).nextStep, 'details');
});
