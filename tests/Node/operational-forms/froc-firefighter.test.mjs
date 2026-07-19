import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { frocValues } from '../../../scripts/operational-forms/modules/froc-firefighter.mjs';

const mapping = JSON.parse(await readFile(
  new URL('../../../resources/forms/froc-log-001-ff/11/mapping.json', import.meta.url),
  'utf8',
));

test('F-ROC certification preserves an optional 24-hour signature time', () => {
  const { values } = frocValues({
    certification: {
      final_employee_signature_text: 'Victor White',
      final_employee_signature_date: '2026-07-19',
      final_employee_signature_time: '23:00',
    },
  }, mapping);

  assert.equal(values.p3_employee_signature_date, '07/19/2026 2300');
});
