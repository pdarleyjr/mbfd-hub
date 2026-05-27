import { describe, expect, it } from 'vitest';
import { inferColumnMapping } from '../../../apps/worker/src/parse/infer-mapping.js';

describe('inferColumnMapping', () => {
  it('maps obvious Telestaff headers correctly', () => {
    const m = inferColumnMapping([
      'Emp ID',
      'Last Name',
      'First Name',
      'Rank',
      'Shift',
      'A-Day Group',
      'Hire Date',
      'Start DateTime',
      'End DateTime',
      'Work Code',
      'Description',
    ]);
    const get = (h: string) => m.columns.find((c) => c.sourceHeader === h)?.target;
    expect(get('Emp ID')).toBe('employee_id');
    expect(get('Last Name')).toBe('last_name');
    expect(get('First Name')).toBe('first_name');
    expect(get('Rank')).toBe('rank');
    expect(get('Shift')).toBe('shift');
    expect(get('A-Day Group')).toBe('a_day_group');
    expect(get('Hire Date')).toBe('hire_date');
    expect(get('Start DateTime')).toBe('event_datetime');
    expect(get('End DateTime')).toBe('event_end_datetime');
    expect(get('Work Code')).toBe('event_work_code');
    expect(get('Description')).toBe('event_description');
  });

  it('ignores junk columns', () => {
    const m = inferColumnMapping(['SomeUnknownColumn', 'XYZ', 'completely-random']);
    for (const c of m.columns) expect(c.target).toBe('ignore');
  });

  it('only assigns each target to one column', () => {
    const m = inferColumnMapping(['Emp ID', 'Employee Number', 'Pernr']);
    const assigned = m.columns.filter((c) => c.target === 'employee_id');
    expect(assigned.length).toBe(1);
  });

  it('does not crash on empty headers', () => {
    const m = inferColumnMapping([]);
    expect(m.columns).toEqual([]);
  });
});
