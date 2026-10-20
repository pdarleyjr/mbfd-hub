import type { FleetVehicle, PhysicalVehicleReport, SwapAnswer, VehicleConfirmation } from '../types';

/** Select value that lets the member type a vehicle ID the fleet list does not have. */
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
  swapAnswer: '',
  note: '',
  confirmed: false,
});

/** A saved draft only counts if it was confirmed against the vehicle assigned today. */
export const reconcileVehicleConfirmation = (
  saved: VehicleConfirmation | undefined,
  assignedVehicle: string,
): VehicleConfirmation => (
  saved && sameVehicle(saved.assignedVehicle, assignedVehicle) && assignedVehicle !== ''
    ? saved
    : defaultVehicleConfirmation(assignedVehicle)
);

/** The vehicle ID the member says they are standing at. */
export const reportedVehicle = (confirmation: VehicleConfirmation): string => (
  confirmation.choice === NOT_LISTED ? normalizeVehicleId(confirmation.typedVehicle) : normalizeVehicleId(confirmation.choice)
);

export const isVehicleMismatch = (confirmation: VehicleConfirmation): boolean => {
  const reported = reportedVehicle(confirmation);

  return reported !== '' && !sameVehicle(reported, confirmation.assignedVehicle);
};

/** Confirmed, a usable ID, and for a different vehicle an answer to the swap question. */
export const vehicleConfirmationComplete = (confirmation: VehicleConfirmation | undefined): boolean => {
  if (!confirmation || !confirmation.confirmed) return false;
  const reported = reportedVehicle(confirmation);
  if (!VEHICLE_ID_PATTERN.test(reported)) return false;

  return !isVehicleMismatch(confirmation) || confirmation.swapAnswer !== '';
};

/** Value for the paper "vehicle_num" field: exactly the assigned ID when unchanged. */
export const paperVehicleValue = (confirmation: VehicleConfirmation): string => (
  isVehicleMismatch(confirmation) ? reportedVehicle(confirmation) : confirmation.assignedVehicle
);

/** What is queued with the checkout and replayed idempotently. */
export const toPhysicalVehicleReport = (confirmation: VehicleConfirmation): PhysicalVehicleReport => {
  const note = confirmation.note.trim();

  return isVehicleMismatch(confirmation)
    ? {
      vehicle_number: reportedVehicle(confirmation),
      swap_answer: confirmation.swapAnswer as SwapAnswer,
      ...(note !== '' ? { note } : {}),
    }
    : { vehicle_number: confirmation.assignedVehicle };
};

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
