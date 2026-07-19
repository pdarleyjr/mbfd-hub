import { frocTotals, laborHours, mileage } from '../lib/totals.mjs';

function date(value) {
  if (!value) return '';
  const [year, month, day] = String(value).split('-');
  return year && month && day ? `${month}/${day}/${year}` : String(value);
}

function getPath(value, path) {
  const normalized = path.replace(/\[(\d+)\]/g, '.$1');
  return normalized.split('.').reduce((current, key) => current?.[key], value);
}

export function frocValues(data, mapping) {
  const totals = frocTotals(data);
  const semantic = {
    header: {
      event_id: data.general_information?.event_id,
      applicant_name: data.general_information?.applicant_name,
      department: data.general_information?.department,
      date: date(data.general_information?.date),
    },
    employees: data.team_members ?? [],
    labor: (data.labor ?? []).map((row) => ({
      category: row.category,
      description_of_work: row.work_performed,
      work_location_gps: row.location_gps,
      start_time: String(row.start ?? '').replace(':', ''),
      end_time: String(row.end ?? '').replace(':', ''),
      labor_hours: laborHours(row),
      event_related: Boolean(row.event_related),
    })),
    labor_totals: {
      non_event_hours: totals.p2_total_non_event_hours,
      event_hours: totals.p2_total_event_hours,
    },
    equipment_hours: (data.equipment_hours ?? []).map((row) => ({
      category: row.category,
      equipment_id: row.equipment_id,
      operator: row.operator,
      vehicle_equipment_description: row.description,
      work_location_gps: row.location,
      hours: row.hours,
      event_related: Boolean(row.event_related),
    })),
    equipment_hours_totals: {
      non_event_hours: totals.p3_equipment_hours_total_non_event,
      event_hours: totals.p3_equipment_hours_total_event,
    },
    equipment_mileage: (data.vehicle_mileage ?? []).map((row) => ({
      category: row.category,
      equipment_id: row.equipment_id,
      operator: row.operator,
      destination: row.destination,
      start_odometer: row.start_odometer,
      end_odometer: row.end_odometer,
      miles: mileage(row),
      event_related: Boolean(row.event_related),
    })),
    equipment_mileage_totals: {
      non_event_miles: totals.p3_mileage_total_non_event,
      event_miles: totals.p3_mileage_total_event,
    },
    materials: (data.materials ?? []).map((row) => ({
      category: row.category,
      item_description: row.item,
      quantity: row.quantity,
      total_cost: row.cost,
      justification: row.justification,
      invoice_receipt_number: row.receipt_reference,
      from_stock: Boolean(row.from_stock),
    })),
    certification: {
      page2: {
        employee_signature_text: data.certification?.page2_employee_signature_text,
        reviewer_signature_text: data.certification?.page2_reviewer_signature_text,
      },
      final: {
        employee_signature_text: data.certification?.final_employee_signature_text,
        employee_signature_date: date(data.certification?.final_employee_signature_date),
        reviewer_signature_text: data.certification?.final_reviewer_signature_text,
        reviewer_signature_date: date(data.certification?.final_reviewer_signature_date),
      },
    },
    additional_notes: data.additional_notes ?? [],
  };

  const values = {};
  for (const field of mapping.fields) {
    const raw = getPath(semantic, field.semantic_path);
    values[field.name] = field.input_type === 'boolean_mark' ? Boolean(raw) : (raw ?? '');
  }

  return { values, calculatedTotals: totals };
}
