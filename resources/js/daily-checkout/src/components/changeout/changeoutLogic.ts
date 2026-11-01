import { VEHICLE_ID_PATTERN, normalizeVehicleId, sameVehicle } from '../../utils/vehicleConfirmation.ts';
import type {
  AlertAction,
  ChangeoutContext,
  ChangeoutDraft,
  ChangeoutSubmission,
  EquipmentCompartment,
  EquipmentLine,
  LeftItem,
  ResolvedTruck,
  TruckChoice,
} from './types';

export const LINE_NOTE_MAX = 500;
export const CHANGEOUT_NOTE_MAX = 1000;

/** The old truck's status after a changeout. The server applies it; this is what the member is told beforehand. */
export const OLD_TRUCK_STATUS_AFTER = 'Out of Service';

export const allLines = (context: ChangeoutContext): EquipmentLine[] => (
  context.equipment.compartments.flatMap((compartment) => compartment.items)
);

type LeftMap = Record<string, string>;

/** Tap a row: Took becomes Left, and Left goes back to Took. */
export const toggleLeft = (left: LeftMap, key: string): LeftMap => {
  if (key in left) {
    const { [key]: _removed, ...others } = left;

    return others;
  }

  return { ...left, [key]: '' };
};

export const withLeftNote = (left: LeftMap, key: string, note: string): LeftMap => (key in left ? { ...left, [key]: note } : left);

/** Notes already typed on items in the compartment stay. */
export const leaveCompartment = (left: LeftMap, compartment: EquipmentCompartment): LeftMap => (
  compartment.items.reduce((next, line) => (line.item_key in next ? next : { ...next, [line.item_key]: '' }), left)
);

export const takeCompartment = (left: LeftMap, compartment: EquipmentCompartment): LeftMap => {
  const keys = new Set(compartment.items.map((line) => line.item_key));

  return Object.fromEntries(Object.entries(left).filter(([key]) => !keys.has(key)));
};

export interface EquipmentCounts {
  total: number;
  took: number;
  left: number;
}

/** A saved Left choice for an item no longer on the list does not count. */
export const equipmentCounts = (context: ChangeoutContext, left: LeftMap): EquipmentCounts => {
  const lines = allLines(context);
  const leftCount = lines.filter((line) => line.item_key in left).length;

  return { total: lines.length, took: lines.length - leftCount, left: leftCount };
};

export const leftLines = (context: ChangeoutContext, left: LeftMap): EquipmentLine[] => allLines(context).filter((line) => line.item_key in left);

export const leftItems = (context: ChangeoutContext, left: LeftMap): LeftItem[] => leftLines(context, left).map((line) => {
  const note = left[line.item_key].trim();

  return { name: line.name, note: note === '' ? null : note };
});

/** A plain "Reserve" or "Spare" label is not a unit, so nothing else moves when that truck is taken. */
export const realUnitLabel = (listedAs: string | null): string | null => (
  listedAs !== null && !/^(reserve|spare|none|unassigned)$/i.test(listedAs.trim()) ? listedAs : null
);

export const matchesSearch = (line: EquipmentLine, query: string): boolean => {
  const terms = query.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
  const name = line.name.toLocaleLowerCase();

  return terms.every((term) => name.includes(term));
};

/** The truck a draft points at, or null when it does not point at a usable one. */
export const resolveTruck = (draft: Pick<ChangeoutDraft, 'choice' | 'typedVehicle'>, context: ChangeoutContext): ResolvedTruck | null => {
  if (context.mode === 'handoff_only') {
    return context.unit.vehicle_number === null ? null : {
      apparatusId: context.unit.apparatus_id,
      vehicleNumber: context.unit.vehicle_number,
      location: context.unit.station_label,
      source: 'current',
      isReturn: false,
      listedAs: null,
    };
  }
  const { choice } = draft;
  if (choice === null) return null;
  const typed = choice.kind === 'typed' ? normalizeVehicleId(draft.typedVehicle) : '';
  const matches = (vehicle: { apparatus_id: number; vehicle_number: string }): boolean => (
    choice.kind === 'listed' ? vehicle.apparatus_id === choice.apparatusId : sameVehicle(vehicle.vehicle_number, typed)
  );

  const candidate = context.candidates.find(matches);
  if (candidate) {
    return {
      apparatusId: candidate.apparatus_id,
      vehicleNumber: candidate.vehicle_number,
      location: candidate.location,
      source: 'candidate',
      isReturn: candidate.is_return,
      listedAs: null,
    };
  }
  const other = context.other_vehicles.find(matches);
  if (other) {
    return {
      apparatusId: other.apparatus_id,
      vehicleNumber: other.vehicle_number,
      location: other.location,
      source: 'other',
      isReturn: false,
      listedAs: realUnitLabel(other.listed_as),
    };
  }
  if (choice.kind === 'listed' || !VEHICLE_ID_PATTERN.test(typed) || sameVehicle(typed, context.unit.vehicle_number)) return null;

  return { apparatusId: null, vehicleNumber: typed, location: null, source: 'typed', isReturn: false, listedAs: null };
};

/** What is wrong with the truck step, in words for the member. Null when it can continue. */
export const truckProblem = (draft: Pick<ChangeoutDraft, 'choice' | 'typedVehicle'>, context: ChangeoutContext): string | null => {
  if (context.mode === 'handoff_only') return null;
  if (draft.choice === null) return 'Choose the truck you are moving to.';
  if (draft.choice.kind === 'typed') {
    const typed = normalizeVehicleId(draft.typedVehicle);
    if (typed === '') return 'Enter the truck number.';
    if (sameVehicle(typed, context.unit.vehicle_number)) return `That is the truck ${context.unit.designation} is on now.`;
    if (!VEHICLE_ID_PATTERN.test(typed)) return 'Enter the number shown on the truck. Letters, numbers, spaces and . / # - only.';
  }

  return resolveTruck(draft, context) ? null : 'That truck is no longer on the list. Choose another.';
};

/** A listed truck named by the plan or by a link, when it is one this unit can move to. */
export const presetChoice = (context: ChangeoutContext, vehicleNumber: string | null): TruckChoice | null => {
  if (context.mode === 'handoff_only') return null;
  const listed = [...context.candidates, ...context.other_vehicles];
  const named = vehicleNumber === null ? undefined : listed.find((vehicle) => sameVehicle(vehicle.vehicle_number, vehicleNumber));
  const planned = context.alert?.planned_replacement_apparatus_id ?? null;
  const vehicle = named ?? listed.find((candidate) => candidate.apparatus_id === planned);

  return vehicle ? { kind: 'listed', apparatusId: vehicle.apparatus_id, vehicleNumber: vehicle.vehicle_number } : null;
};

/** Left items are sorted so a retry of the same work is byte-for-byte the same payload. */
export const buildSubmission = (draft: ChangeoutDraft, context: ChangeoutContext): ChangeoutSubmission => {
  const truck = resolveTruck(draft, context);
  const lines = new Set(allLines(context).map((line) => line.item_key));
  const note = draft.note.trim();

  return {
    client_submission_id: draft.clientSubmissionId,
    expected_vehicle_number: context.unit.vehicle_number,
    // A truck keeps its number after a move, so the unit is what proves nothing moved underneath this draft.
    expected_unit_slug: context.unit.slug,
    alert_id: draft.alertId,
    to_apparatus_id: truck?.apparatusId ?? null,
    to_vehicle_number: truck !== null && truck.apparatusId === null ? truck.vehicleNumber : null,
    checklist_version: context.equipment.checklist_version,
    exceptions: Object.keys(draft.left)
      .filter((key) => lines.has(key))
      .sort()
      .map((key) => {
        const lineNote = draft.left[key].trim();

        return { item_key: key, handling: 'leave', note: lineNote === '' ? null : lineNote };
      }),
    note: note === '' ? null : note,
  };
};

export interface ReconcileResult {
  draft: ChangeoutDraft;
  /** Names of Left choices that no longer apply to the fresh list. */
  dropped: string[];
  truckCleared: boolean;
}

/** Fit a saved draft onto a fresh context. Left choices that still exist survive, and the rest are named for the member. */
export const reconcileDraft = (draft: ChangeoutDraft, context: ChangeoutContext, previous: ChangeoutContext | null): ReconcileResult => {
  const fresh = new Set(allLines(context).map((line) => line.item_key));
  const oldNames = new Map((previous ? allLines(previous) : []).map((line) => [line.item_key, line.name]));
  const entries = Object.entries(draft.left);
  const dropped = entries.filter(([key]) => !fresh.has(key)).map(([key]) => oldNames.get(key) ?? key);
  const truckCleared = context.mode === 'start' && draft.choice !== null && resolveTruck(draft, context) === null && draft.choice.kind === 'listed';

  return {
    draft: {
      ...draft,
      left: Object.fromEntries(entries.filter(([key]) => fresh.has(key))),
      choice: truckCleared ? null : draft.choice,
      step: truckCleared ? 'truck' : draft.step,
    },
    dropped,
    truckCleared,
  };
};

export const hasWork = (draft: ChangeoutDraft): boolean => (
  draft.attempted
  || (!draft.preselected && (draft.choice !== null || draft.typedVehicle.trim() !== ''))
  || draft.note.trim() !== ''
  || Object.keys(draft.left).length > 0
);

/** Router path for a summary's Daily route. The router's basename is /daily, so that prefix comes off. */
export const dailyRouterPath = (path: string | null): string | null => {
  if (path === null || !path.startsWith('/') || path.startsWith('//')) return null;
  if (path === '/daily' || path === '/daily/') return '/';

  return path.startsWith('/daily/') ? path.slice('/daily'.length) : path;
};

/** Only a station page is a way back to the station. */
export const stationReturnPath = (value: string | null, stationId: number | null): string => {
  const path = (value ?? '').replace(/^\/daily(?=\/)/, '');
  if (/^\/stations\/\d+$/.test(path)) return path;

  return stationId === null ? '/stations' : `/stations/${stationId}`;
};

export const alertActionPath = (action: AlertAction | null, stationId: number): string | null => {
  if (action === null || action.apparatus_id === null || (action.kind !== 'start' && action.kind !== 'handoff')) return null;
  const alert = action.alert_id === null ? '' : `alert_id=${action.alert_id}&`;

  return `/changeouts/new/${action.apparatus_id}?${alert}return_to=${encodeURIComponent(`/stations/${stationId}`)}`;
};
