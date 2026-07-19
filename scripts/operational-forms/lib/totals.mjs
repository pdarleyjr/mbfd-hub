function hundredths(value = '0') {
  const match = String(value).trim().match(/^(-?)(\d+)(?:\.(\d{1,2}))?$/);
  if (!match) throw new Error('Invalid decimal value in FROC calculation.');
  const amount = Number.parseInt(match[2], 10) * 100 + Number.parseInt((match[3] ?? '').padEnd(2, '0') || '0', 10);
  return match[1] === '-' ? -amount : amount;
}

function formatted(value) {
  const sign = value < 0 ? '-' : '';
  const absolute = Math.abs(value);
  return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}

function timeMinutes(value) {
  const match = String(value ?? '').match(/^(\d{1,2}):(\d{2})$/);
  if (!match) throw new Error('Invalid time in FROC labor calculation.');
  const hours = Number.parseInt(match[1], 10);
  const minutes = Number.parseInt(match[2], 10);
  if (hours > 23 || minutes > 59) throw new Error('FROC labor time is outside the valid range.');
  return hours * 60 + minutes;
}

export function laborHours(row) {
  if (row.manual_override_hours !== undefined && row.manual_override_hours !== null && String(row.manual_override_hours).trim() !== '') {
    if (!String(row.override_reason ?? '').trim()) throw new Error('Manual labor override is missing its reason.');
    return formatted(hundredths(row.manual_override_hours));
  }
  const start = timeMinutes(row.start);
  let end = timeMinutes(row.end);
  if (end < start) end += 24 * 60;
  return formatted(Math.floor(((end - start) * 100 + 30) / 60));
}

export function mileage(row) {
  const difference = hundredths(row.end_odometer) - hundredths(row.start_odometer);
  if (difference < 0 && !String(row.correction_reason ?? '').trim()) {
    throw new Error('Negative FROC mileage is missing its correction reason.');
  }
  const hasManualMileage = row.manual_miles !== undefined
    && row.manual_miles !== null
    && String(row.manual_miles).trim() !== '';
  return formatted(hasManualMileage ? hundredths(row.manual_miles) : difference);
}

export function frocTotals(data) {
  const sums = {
    labor: { event: 0, nonEvent: 0 },
    equipment: { event: 0, nonEvent: 0 },
    mileage: { event: 0, nonEvent: 0 },
  };

  for (const row of data.labor ?? []) {
    sums.labor[row.event_related ? 'event' : 'nonEvent'] += hundredths(laborHours(row));
  }
  for (const row of data.equipment_hours ?? []) {
    sums.equipment[row.event_related ? 'event' : 'nonEvent'] += hundredths(row.hours ?? '0');
  }
  for (const row of data.vehicle_mileage ?? []) {
    sums.mileage[row.event_related ? 'event' : 'nonEvent'] += hundredths(mileage(row));
  }

  return {
    p2_total_non_event_hours: formatted(sums.labor.nonEvent),
    p2_total_event_hours: formatted(sums.labor.event),
    p3_equipment_hours_total_non_event: formatted(sums.equipment.nonEvent),
    p3_equipment_hours_total_event: formatted(sums.equipment.event),
    p3_mileage_total_non_event: formatted(sums.mileage.nonEvent),
    p3_mileage_total_event: formatted(sums.mileage.event),
  };
}
