function date(value) {
  if (!value) return '';
  const [year, month, day] = String(value).split('-');
  return year && month && day ? `${month}/${day}/${year}` : String(value);
}

function time(value) {
  return String(value ?? '').replace(':', '');
}

export function ics214Values(data) {
  const values = {
    incident_name: data.incident?.name ?? '',
    operational_period_date_from: date(data.incident?.date_from),
    operational_period_date_to: date(data.incident?.date_to),
    operational_period_time_from: time(data.incident?.time_from),
    operational_period_time_to: time(data.incident?.time_to),
    unit_name: data.unit?.name ?? '',
    unit_ics_position: data.unit?.ics_position ?? '',
    unit_home_agency_unit: data.unit?.home_agency_unit ?? '',
    prepared_by_name: data.prepared_by?.name ?? '',
    prepared_by_position_title: data.prepared_by?.position_title ?? '',
    prepared_by_signature: data.prepared_by?.signature_text ?? '',
    prepared_by_date_time: `${date(data.prepared_by?.date)} ${time(data.prepared_by?.time)}`.trim(),
  };

  for (let index = 0; index < 8; index += 1) {
    const row = data.resources?.[index] ?? {};
    const number = String(index + 1).padStart(2, '0');
    values[`resource_${number}_name`] = row.name ?? '';
    values[`resource_${number}_ics_position`] = row.ics_position ?? '';
    values[`resource_${number}_home_agency_unit`] = row.home_agency_unit ?? '';
  }
  for (let index = 0; index < 24; index += 1) {
    const row = data.activities?.[index] ?? {};
    const number = String(index + 1).padStart(2, '0');
    values[`activity_${number}_date_time`] = `${date(row.date)} ${time(row.time)}`.trim();
    values[`activity_${number}_notable_activities`] = row.notable_activity ?? '';
  }

  return { values, calculatedTotals: null };
}
