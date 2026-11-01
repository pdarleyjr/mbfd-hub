import type {
  AlertAction,
  ChangeoutCandidate,
  ChangeoutConflictDetails,
  ChangeoutContext,
  ChangeoutHandoff,
  ChangeoutLine,
  ChangeoutSubmitResult,
  ChangeoutSummary,
  EquipmentCompartment,
  EquipmentLine,
  LeftItem,
  OperationalAlert,
  OtherVehicle,
  PreviousChangeout,
} from './types';

type Row = Record<string, unknown>;

const isRow = (value: unknown): value is Row => typeof value === 'object' && value !== null && !Array.isArray(value);
const rows = (value: unknown): Row[] => (Array.isArray(value) ? value.filter(isRow) : []);

const text = (value: unknown): string | null => (typeof value === 'string' && value.trim() !== '' ? value : null);
/** Vehicle numbers are labels. A number from the wire is kept as its digits, and leading zeros are never touched. */
const label = (value: unknown): string | null => (typeof value === 'number' ? String(value) : text(value));
const id = (value: unknown): number | null => (Number.isInteger(value) ? value as number : null);
const count = (value: unknown): number => {
  if (Array.isArray(value)) return value.length;

  return Number.isInteger(value) && (value as number) >= 0 ? value as number : 0;
};

const leftItems = (value: unknown): LeftItem[] => rows(value).flatMap((row) => {
  const name = text(row.name) ?? text(row.item_name);

  return name ? [{ name, note: text(row.note) }] : [];
});

const parseAction = (value: unknown): AlertAction | null => {
  if (!isRow(value)) return null;
  const { kind } = value;
  if (kind !== 'start' && kind !== 'handoff' && kind !== 'none') return null;

  return { kind, label: text(value.label) ?? '', apparatus_id: id(value.apparatus_id), alert_id: id(value.alert_id) };
};

const parseAlert = (row: Row): OperationalAlert | null => {
  const alertId = id(row.id);
  const title = text(row.title);
  if (alertId === null || title === null) return null;
  const workflow = isRow(row.workflow) && text(row.workflow.label)
    ? { state: text(row.workflow.state) ?? '', label: text(row.workflow.label) ?? '' }
    : null;

  return {
    id: alertId,
    category: text(row.category) ?? 'general',
    category_label: text(row.category_label) ?? '',
    title,
    message: text(row.message),
    reason: text(row.reason),
    instructions: text(row.instructions),
    unit_designation: text(row.unit_designation) ?? '',
    vehicle_number: label(row.vehicle_number),
    current_vehicle_number: label(row.current_vehicle_number),
    current_apparatus_id: id(row.current_apparatus_id),
    schedule_label: text(row.schedule_label),
    date_passed: row.date_passed === true,
    workflow,
    action: parseAction(row.action),
  };
};

export const parseAlerts = (payload: unknown): OperationalAlert[] => (
  isRow(payload) ? rows(payload.alerts).flatMap((row) => parseAlert(row) ?? []) : []
);

/** An old cached checklist has no key, and that is the same as nothing left behind. */
export const parseChangeoutHandoff = (value: unknown): ChangeoutHandoff | null => {
  if (!isRow(value)) return null;
  const leftBehind = leftItems(value.left_behind);

  return leftBehind.length === 0 ? null : {
    from_vehicle_number: label(value.from_vehicle_number),
    effective_at: text(value.effective_at),
    left_behind: leftBehind,
  };
};

const parseVehicle = (row: Row) => {
  const apparatusId = id(row.apparatus_id);
  const vehicleNumber = label(row.vehicle_number);
  if (apparatusId === null || vehicleNumber === null) return null;

  return {
    apparatus_id: apparatusId,
    vehicle_number: vehicleNumber,
    label: text(row.label),
    location: text(row.location),
    status: text(row.status),
  };
};

const parseOtherVehicle = (row: Row): OtherVehicle | null => {
  const vehicle = parseVehicle(row);

  return vehicle ? { ...vehicle, listed_as: text(row.listed_as) } : null;
};

const parseCandidate = (row: Row): ChangeoutCandidate | null => {
  const vehicle = parseVehicle(row);

  return vehicle ? { ...vehicle, is_return: row.is_return === true } : null;
};

const parseLine = (row: Row): EquipmentLine | null => {
  const key = text(row.item_key);
  const name = text(row.name);
  if (key === null || name === null) return null;

  return {
    item_key: key,
    name,
    previous: isRow(row.previous) ? { handling: text(row.previous.handling), note: text(row.previous.note) } : null,
  };
};

const parseCompartments = (value: unknown): EquipmentCompartment[] => rows(value).map((row, index) => ({
  id: text(row.id) ?? `compartment-${index}`,
  // Templates differ: v1 compartments use `title`, v2 use `name`.
  name: text(row.name) ?? text(row.title) ?? 'Equipment',
  items: rows(row.items).flatMap((item) => parseLine(item) ?? []),
}));

const parsePrevious = (value: unknown): PreviousChangeout | null => {
  if (!isRow(value)) return null;
  const previousId = id(value.id);
  if (previousId === null) return null;

  return {
    id: previousId,
    from_apparatus_id: id(value.from_apparatus_id),
    from_vehicle_number: label(value.from_vehicle_number),
    to_vehicle_number: label(value.to_vehicle_number),
    effective_at: text(value.effective_at),
    moved: count(value.moved),
    left_behind: leftItems(value.left_behind),
    submitted_by_name: text(value.submitted_by_name),
  };
};

/** Throws when the unit is unusable: the wizard treats that as a failed load, never as an empty form. */
export const parseContext = (payload: unknown): ChangeoutContext => {
  if (!isRow(payload) || !isRow(payload.unit) || id(payload.unit.apparatus_id) === null) {
    throw new Error('The server sent an unreadable changeout.');
  }
  const { unit } = payload;
  const equipment = isRow(payload.equipment) ? payload.equipment : {};
  const alert = isRow(payload.alert) && id(payload.alert.id) !== null
    ? {
      id: id(payload.alert.id) as number,
      title: text(payload.alert.title),
      reason: text(payload.alert.reason),
      schedule_label: text(payload.alert.schedule_label),
      vehicle_number: label(payload.alert.vehicle_number),
      planned_replacement_apparatus_id: id(payload.alert.planned_replacement_apparatus_id),
    }
    : null;

  return {
    unit: {
      apparatus_id: id(unit.apparatus_id) as number,
      designation: text(unit.designation) ?? 'This unit',
      slug: text(unit.slug),
      vehicle_number: label(unit.vehicle_number),
      station_id: id(unit.station_id),
      station_label: text(unit.station_label),
      status: text(unit.status),
    },
    alert,
    mode: payload.mode === 'handoff_only' ? 'handoff_only' : 'start',
    candidates: rows(payload.candidates).flatMap((row) => parseCandidate(row) ?? []),
    other_vehicles: rows(payload.other_vehicles).flatMap((row) => parseOtherVehicle(row) ?? []),
    equipment: {
      checklist_version: text(equipment.checklist_version),
      usable: equipment.usable === true,
      compartments: parseCompartments(equipment.compartments),
    },
    open_issues_on_truck: count(payload.open_issues_on_truck),
    previous_changeout: parsePrevious(payload.previous_changeout),
  };
};

const parseSummaryLine = (row: Row): ChangeoutLine | null => {
  const lineId = id(row.id);
  const name = text(row.name) ?? text(row.item_name);
  if (lineId === null || name === null) return null;

  return {
    id: lineId,
    item_key: text(row.item_key) ?? String(lineId),
    name,
    compartment: text(row.compartment) ?? text(row.compartment_name),
    handling: text(row.handling),
    custody_status: text(row.custody_status) ?? text(row.status) ?? '',
    note: text(row.note),
  };
};

/** A line the member left on the old truck. Every other line went with them. */
export const isLeftLine = (line: ChangeoutLine): boolean => line.handling === 'leave' || line.custody_status === 'left_behind';

/** The unit may come as its designation or as a small record. */
const parseDisplaced = (value: unknown): string | null => {
  if (!isRow(value)) return text(value);

  return text(value.designation) ?? text(value.unit_designation) ?? text(value.name);
};

/** Accepts `{ changeout: {...} }` or the bare summary. Throws when there is no record id. */
export const parseSummary = (payload: unknown): ChangeoutSummary => {
  const row = isRow(payload) && isRow(payload.changeout) ? payload.changeout : payload;
  if (!isRow(row) || id(row.id) === null) throw new Error('The server sent an unreadable changeout.');
  const lines = rows(row.lines).flatMap((line) => parseSummaryLine(line) ?? []);
  const named = leftItems(row.left_behind);
  const left = named.length > 0 ? named : lines.filter(isLeftLine).map((line) => ({ name: line.name, note: line.note }));

  return {
    id: id(row.id) as number,
    status: text(row.status) ?? 'completed',
    unit_designation: text(row.unit_designation) ?? 'This unit',
    from_vehicle_number: label(row.from_vehicle_number),
    to_vehicle_number: label(row.to_vehicle_number),
    to_apparatus_id: id(row.to_apparatus_id),
    station_id: id(row.station_id),
    effective_at: text(row.effective_at),
    old_truck_status: text(row.old_truck_status),
    alert_completed: row.alert_completed === true,
    return_of_changeout_id: id(row.return_of_changeout_id),
    submitted_by_name: text(row.submitted_by_name),
    moved: count(row.moved),
    left_behind: left,
    leftCount: Math.max(count(row.left_behind), left.length),
    next_daily_checkout_path: text(row.next_daily_checkout_path),
    message: text(row.message),
    note: text(row.note),
    new_truck_record: row.new_truck_record === true,
    displaced_unit: parseDisplaced(row.displaced_unit),
    lines,
  };
};

export const parseSubmitResult = (payload: unknown): ChangeoutSubmitResult => ({
  changeout: parseSummary(payload),
  replayed: isRow(payload) && payload.replayed === true,
});

export const parseConflictDetails = (payload: unknown): ChangeoutConflictDetails => {
  const body = isRow(payload) ? payload : {};
  const context = isRow(body.context) ? body.context : {};
  const pick = (key: string): unknown => body[key] ?? context[key];
  const changeout = pick('changeout');
  const current = pick('current');

  return {
    current_vehicle_number: label(pick('current_vehicle_number')) ?? (isRow(current) ? label(current.vehicle_number) : null),
    current_apparatus_id: id(pick('current_apparatus_id')) ?? (isRow(current) ? id(current.apparatus_id) : null),
    changeout_id: id(pick('changeout_id')) ?? (isRow(changeout) ? id(changeout.id) : null),
  };
};
