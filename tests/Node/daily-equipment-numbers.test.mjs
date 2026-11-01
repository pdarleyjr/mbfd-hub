import assert from 'node:assert/strict';
import test from 'node:test';
import {
  boxesFor,
  equipmentFieldIds,
  joinNumbers,
  parseEquipmentNumbers,
  prefillCompartments,
  prefillFieldValues,
  slotsOnChecklist,
  splitNumbers,
  toExtraSubmissions,
} from '../../resources/js/daily-checkout/src/utils/equipmentNumbers.ts';

const fieldSlot = { slot: 'field:scba_1', kind: 'scba', label: 'SCBA #1', field_id: 'scba_1', compartment_id: null, item_id: null, boxes: 1, number: '2103' };
const itemSlot = { slot: 'item:compartment_d/compartment_d-item-4', kind: 'scba', label: 'SCBAs', field_id: null, compartment_id: 'compartment_d', item_id: 'compartment_d-item-4', boxes: 3, number: '2103 - 2161 - 2189' };
const compartments = [{ id: 'compartment_d', name: 'D', items: [{ id: 'compartment_d-item-4', name: 'SCBAs', status: 'Present', inputType: 'text' }, { id: 'other', name: 'Other', status: 'Present' }] }];

test('the ways crews actually typed several numbers in one box all come apart', () => {
  assert.deepEqual(splitNumbers('2103, 2161, 2189'), ['2103', '2161', '2189']);
  assert.deepEqual(splitNumbers('2103 - 2161 - 2189'), ['2103', '2161', '2189']);
  assert.deepEqual(splitNumbers('2189-2161-2103'), ['2189', '2161', '2103']);
  assert.deepEqual(splitNumbers('2175 2115 2184'), ['2175', '2115', '2184']);
  assert.deepEqual(splitNumbers('51-67-80'), ['51', '67', '80']);
  assert.deepEqual(splitNumbers('89=61-03'), ['89', '61', '03']);
  assert.deepEqual(splitNumbers(''), []);
});

test('an ID with letters is never broken apart, and commas the app wrote are trusted', () => {
  assert.deepEqual(splitNumbers('R-14'), ['R-14']);
  assert.deepEqual(splitNumbers('R-14, A 7'), ['R-14', 'A 7']);
  assert.equal(joinNumbers(['2103', '', ' 2161 ']), '2103, 2161');
});

test('a slot shows at least its expected boxes, and more when more numbers exist', () => {
  assert.deepEqual(boxesFor({ boxes: 3 }, '2103'), ['2103', '', '']);
  assert.deepEqual(boxesFor({ boxes: 3 }, '1, 2, 3, 4'), ['1', '2', '3', '4']);
  assert.deepEqual(boxesFor({ boxes: 1 }, 'Lt 44'), ['Lt 44']);
});

test('a new checkout starts with the numbers the last member left, and nothing is marked inspected', () => {
  const block = { slots: [fieldSlot, itemSlot], extras: [] };
  const fields = prefillFieldValues([{ id: 'scba_1', value: '' }, { id: 'tide', value: '' }], block);
  assert.deepEqual(fields, [{ id: 'scba_1', value: '2103' }, { id: 'tide', value: '' }]);

  const [area] = prefillCompartments(compartments, block);
  assert.equal(area.items[0].value, '2103, 2161, 2189');
  assert.equal(area.items[0].observed, undefined);
  assert.equal(area.items[1].value, undefined);
  assert.equal(prefillCompartments(compartments, undefined), compartments);
});

test('a slot with no number leaves the answer alone', () => {
  const block = { slots: [{ ...fieldSlot, number: '' }], extras: [] };
  assert.deepEqual(prefillFieldValues([{ id: 'scba_1', value: '' }], block), [{ id: 'scba_1', value: '' }]);
});

test('a session frozen on an older template only shows the entries it really has', () => {
  const block = { slots: [fieldSlot, itemSlot, { ...fieldSlot, slot: 'field:gone', field_id: 'gone' }], extras: [] };
  assert.deepEqual(slotsOnChecklist(block, [{ id: 'scba_1' }], compartments).map((slot) => slot.slot), ['field:scba_1', itemSlot.slot]);
  assert.deepEqual([...equipmentFieldIds(block)], ['scba_1', 'gone']);
});

test('an added number left blank is not sent, which removes it', () => {
  assert.deepEqual(toExtraSubmissions([
    { client_id: 'a', kind: 'radio', label: null, number: ' 1741 ' },
    { client_id: 'b', kind: 'scba', label: null, number: '   ' },
  ]), [{ client_id: 'a', kind: 'radio', label: null, number: '1741' }]);
});

test('a bad row from the server is dropped and the rest still work', () => {
  const parsed = parseEquipmentNumbers({
    slots: [fieldSlot, { slot: 'x', kind: 'nope', label: 'X', field_id: 'x' }, { slot: 'y', kind: 'radio', label: 'Y' }, null],
    extras: [{ client_id: 'a', kind: 'radio', number: '1741', extra: 'dropped' }, { kind: 'radio' }],
  });
  assert.deepEqual(parsed.slots, [fieldSlot]);
  assert.deepEqual(parsed.extras, [{ client_id: 'a', kind: 'radio', label: null, number: '1741' }]);
  assert.equal(parseEquipmentNumbers(undefined), undefined);
  assert.equal(parseEquipmentNumbers({ slots: 'no' }), undefined);
});
