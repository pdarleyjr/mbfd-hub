import type { ChecklistData, FleetVehicle, PhysicalVehicleReport, VehicleConfirmation } from '../types';

/**
 * Fire Boat 6 has no fleet vehicle ID, so nothing about a physical vehicle is asked, required or sent for it. The checklist
 * type covers a checkout saved on this device before the server sent the flag. Every other unit still confirms its truck.
 */
export const checklistUsesVehicleId = (checklist: Pick<ChecklistData, 'vehicle_id_required' | 'checklist_type'> | null | undefined): boolean => (
  checklist?.vehicle_id_required !== false && checklist?.checklist_type !== 'fireboat6'
);

/** Select value for a truck the fleet list does not have. */
export const NOT_LISTED = '__not_listed__';

export const MAX_VEHICLE_ID_LENGTH = 32;

/** Letters, digits and the few separators fleet labels use. Mirrors the server rule. */
export const VEHICLE_ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9 ./#-]{0,31}$/;

export const normalizeVehicleId = (value: string | null | undefined): string => (value ?? '').replace(/\s+/g, ' ').trim();

export const sameVehicle = (first: string | null | undefined, second: string | null | undefined): boolean => {
  const a = normalizeVehicleId(first);
  const b = normalizeVehicleId(second);

  return a !== '' && b !== '' && a.toLowerCase() === b.toLowerCase();
};

/** The normal case starts here: the dropdown is preset to the unit's assigned vehicle. */
export const defaultVehicleConfirmation = (assignedVehicle: string): VehicleConfirmation => ({
  assignedVehicle,
  choice: assignedVehicle,
  typedVehicle: '',
  confirmed: false,
});

/** The vehicle ID the member says they are standing at. */
export const reportedVehicle = (confirmation: VehicleConfirmation): string => (
  confirmation.choice === NOT_LISTED ? normalizeVehicleId(confirmation.typedVehicle) : normalizeVehicleId(confirmation.choice)
);

/**
 * The member says they are at another truck than the unit's own, so the change has to be recorded first. A unit with no
 * vehicle number on record has nothing to differ from: its crew is naming the vehicle.
 */
export const isVehicleMismatch = (confirmation: VehicleConfirmation): boolean => {
  if (normalizeVehicleId(confirmation.assignedVehicle) === '') return false;
  const reported = reportedVehicle(confirmation);

  return confirmation.choice === NOT_LISTED ? !sameVehicle(reported, confirmation.assignedVehicle) : reported !== '' && !sameVehicle(reported, confirmation.assignedVehicle);
};

/**
 * A saved draft only counts if it was confirmed against the vehicle assigned today. A draft from the old app can hold a
 * swap answer and a note, which mean nothing now and are dropped. One that names another truck comes back unconfirmed,
 * asking the member to record the change.
 */
export const reconcileVehicleConfirmation = (
  saved: VehicleConfirmation | undefined,
  assignedVehicle: string,
): VehicleConfirmation => {
  if (!saved || assignedVehicle === '' || !sameVehicle(saved.assignedVehicle, assignedVehicle)) return defaultVehicleConfirmation(assignedVehicle);
  const restored: VehicleConfirmation = {
    assignedVehicle: saved.assignedVehicle,
    choice: saved.choice,
    typedVehicle: saved.typedVehicle ?? '',
    confirmed: saved.confirmed,
  };

  return isVehicleMismatch(restored) ? { ...restored, confirmed: false } : restored;
};

/** Confirmed, a usable ID, and the truck the unit is actually on. */
export const vehicleConfirmationComplete = (confirmation: VehicleConfirmation | undefined): boolean => {
  if (!confirmation || !confirmation.confirmed || isVehicleMismatch(confirmation)) return false;

  return VEHICLE_ID_PATTERN.test(reportedVehicle(confirmation));
};

/** Value for the paper "vehicle_num" field: the assigned ID, or the vehicle named for a unit with none on record. */
export const paperVehicleValue = (confirmation: VehicleConfirmation): string => (
  confirmation.assignedVehicle !== '' ? confirmation.assignedVehicle : reportedVehicle(confirmation)
);

/** What is queued with the checkout and replayed idempotently. Always the assigned truck: a different one is recorded first. */
export const toPhysicalVehicleReport = (confirmation: VehicleConfirmation): PhysicalVehicleReport => ({
  vehicle_number: paperVehicleValue(confirmation),
});

export const fleetVehicleLabel = (vehicle: FleetVehicle): string => {
  const role = vehicle.role === 'reserve' ? 'Reserve' : vehicle.role === 'out_of_service' ? 'Out of service' : 'Frontline';

  return `${vehicle.vehicle_number}${vehicle.designation ? ` · ${vehicle.designation}` : ''} (${role})`;
};

/** Fleet list for the dropdown, always including the assigned vehicle (the list may be stale offline). */
export const dropdownVehicles = (fleet: FleetVehicle[], assignedVehicle: string, assignedDesignation?: string): FleetVehicle[] => (
  assignedVehicle !== '' && !fleet.some((vehicle) => sameVehicle(vehicle.vehicle_number, assignedVehicle))
    ? [{ vehicle_number: assignedVehicle, designation: assignedDesignation ?? null, role: 'frontline' }, ...fleet]
    : fleet
);
