'use client';

import * as React from 'react';
import type { ColumnMapping, ColumnTarget } from '@mbfd-vacation/shared';

const TARGETS: { value: ColumnTarget; label: string }[] = [
  { value: 'employee_id', label: 'Employee ID' },
  { value: 'badge_number', label: 'Badge #' },
  { value: 'last_name', label: 'Last name' },
  { value: 'first_name', label: 'First name' },
  { value: 'rank', label: 'Rank' },
  { value: 'shift', label: 'Shift (A/B/C)' },
  { value: 'a_day_group', label: 'A-Day group' },
  { value: 'hire_date', label: 'Hire date' },
  { value: 'event_datetime', label: 'Event start datetime' },
  { value: 'event_end_datetime', label: 'Event end datetime' },
  { value: 'event_description', label: 'Event description' },
  { value: 'event_work_code', label: 'Event work code' },
  { value: 'ignore', label: '— ignore —' },
];

export function ColumnMapper({
  value,
  onChange,
}: {
  value: ColumnMapping;
  onChange: (next: ColumnMapping) => void;
}): React.JSX.Element {
  const setTarget = (idx: number, target: ColumnTarget): void => {
    const next: ColumnMapping = {
      columns: value.columns.map((c, i) => (i === idx ? { ...c, target } : c)),
    };
    onChange(next);
  };

  return (
    <div className="overflow-hidden rounded-lg border border-stone-200 bg-white">
      <div className="grid grid-cols-2 border-b border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-stone-600">
        <div>Source column</div>
        <div>Maps to</div>
      </div>
      <ul className="divide-y divide-stone-100">
        {value.columns.map((c, i) => (
          <li key={`${i}-${c.sourceHeader}`} className="grid grid-cols-2 items-center px-4 py-2">
            <span className="truncate font-mono text-sm text-stone-800">{c.sourceHeader}</span>
            <select
              value={c.target}
              onChange={(e) => setTarget(i, e.target.value as ColumnTarget)}
              className="rounded-md border border-stone-200 bg-white px-2 py-1 text-sm"
            >
              {TARGETS.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </select>
          </li>
        ))}
      </ul>
    </div>
  );
}
