import { VEHICLE_ID_PATTERN, normalizeVehicleId, sameVehicle } from '../../utils/vehicleConfirmation.ts';
import type {
  AlertAction,
  ChangeoutContext,
  ChangeoutDraft,
  ChangeoutSubmission,
  EquipmentLine,
  LineChoice,
  ResolvedTruck,
} from './types';

export const LINE_NOTE_MAX = 500;
export const CHANGEOUT_NOTE_MAX = 1000;

export const allLines = (context: ChangeoutContext): EquipmentLine[] => (
  context.equipment.compartments.flatMap((compartment) => compartment.items)
);

/** Controlled lines are never choices: they show by name and end waiting for verification. */
export const standardLines = (context: ChangeoutContext): EquipmentLine[] => allLines(context).filter((line) => !line.controlled);
export const controlledLines = (context: ChangeoutContext): EquipmentLine[] => allLines(context).filter((line) => line.controlled);

export const defaultChoice = (line: EquipmentLine): LineChoice => ({ handling: line.default_handling, quantity: line.default_quantity, condition: 'ok', note: line.default_note });

export const effectiveChoice = (line: EquipmentLine, exceptions: Record<string, LineChoice>): LineChoice => (
  exceptions[line.item_key] ?? defaultChoice(line)
);

/**
 * The server's default for a line depends on the truck: only a return starts from what happened last time. Without
 * this, the screen would say "transfer" for a line the server is about to record as left behind.
 */
export const applyTruckDefaults = (context: ChangeoutContext, truck: ResolvedTruck | null): ChangeoutContext => {
  if (!truck?.isReturn) return context;

  return {
    ...context,
    equipment: {
      ...context.equipment,
      compartments: context.equipment.compartments.map((compartment) => ({
        ...compartment,
        items: compartment.items.map((line) => {
          const handling = line.previous?.return_handling;
          if (line.controlled || !line.previous || !handling) return line;

          return {
            ...line,
            default_handling: handling,
            default_quantity: handling === 'transfer' ? line.previous.return_quantity : null,
            default_note: handling === 'transfer' ? '' : line.previous.note ?? '',
          };
        }),
      })),
    },
  };
};

/** Partial quantities only exist for a line being moved in good condition whose expected quantity is known. A full count is sent as none. */
export const normalizeChoice = (line: EquipmentLine, choice: LineChoice): LineChoice => {
  const expected = line.expected_quantity;
  if (expected === null || choice.handling !== 'transfer' || choice.condition !== 'ok' || choice.quantity === null) {
    return { ...choice, quantity: null };
  }
  const quantity = Math.max(1, Math.min(expected, Math.trunc(choice.quantity)));

  return { ...choice, quantity: quantity === expected ? null : quantity };
};

const isDefaultChoice = (line: EquipmentLine, choice: LineChoice): boolean => (
  choice.handling === line.default_handling && choice.quantity === line.default_quantity && choice.condition === 'ok' && choice.note.trim() === line.default_note.trim()
);

/** A line back at its default leaves the map, so the payload names only real exceptions. */
export const withLineChoice = (exceptions: Record<string, LineChoice>, line: EquipmentLine, choice: LineChoice): Record<string, LineChoice> => {
  const normalized = normalizeChoice(line, choice);
  const { [line.item_key]: _previous, ...others } = exceptions;

  return isDefaultChoice(line, normalized) ? others : { ...others, [line.item_key]: normalized };
};

/** Anything other than a plain full transfer. A return's "leave" default shows here even though the member did not change it. */
export const isAttentionLine = (line: EquipmentLine, exceptions: Record<string, LineChoice>): boolean => {
  const choice = effectiveChoice(line, exceptions);

  return choice.handling !== 'transfer' || choice.quantity !== null || choice.condition !== 'ok' || choice.note.trim() !== '';
};

export interface EquipmentCounts {
  total: number;
  moving: number;
  controlled: number;
}

export const equipmentCounts = (context: ChangeoutContext, exceptions: Record<string, LineChoice>): EquipmentCounts => {
  const choices = standardLines(context).map((line) => effectiveChoice(line, exceptions));

  return {
    total: choices.length,
    // A missing or damaged item is never moved: its issue stays with the truck it was on.
    moving: choices.filter((choice) => choice.handling === 'transfer' && choice.condition === 'ok').length,
    controlled: controlledLines(context).length,
  };
};

export const lineStateLabel = (line: EquipmentLine, choice: LineChoice): string => {
  if (choice.condition === 'missing') return 'Missing';
  if (choice.condition === 'damaged') return 'Damaged';
  if (choice.handling === 'leave') return 'Leave on truck';
  if (choice.handling === 'review') return 'Not sure';

  return choice.quantity !== null && line.expected_quantity !== null ? `Moving ${choice.quantity} of ${line.expected_quantity}` : 'Transfer';
};

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
      label: context.unit.designation,
      location: context.unit.station_label,
      source: 'current',
      direct: true,
      isReturn: false,
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
      label: candidate.label,
      location: candidate.location,
      source: 'candidate',
      direct: candidate.direct,
      isReturn: candidate.is_return,
    };
  }
  const other = context.other_vehicles.find(matches);
  if (other) {
    return {
      apparatusId: other.apparatus_id,
      vehicleNumber: other.vehicle_number,
      label: other.label,
      location: other.location,
      source: 'other',
      direct: other.direct,
      isReturn: false,
    };
  }
  if (choice.kind === 'listed' || !VEHICLE_ID_PATTERN.test(typed) || sameVehicle(typed, context.unit.vehicle_number)) return null;

  return { apparatusId: null, vehicleNumber: typed, label: null, location: null, source: 'typed', direct: false, isReturn: false };
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

/** The server's `direct` is the whole answer: it already covers Fleet managers, the schedule and eligibility. */
export const truckNeedsFleet = (truck: ResolvedTruck): boolean => {
  if (truck.source === 'current') return false;
  if (truck.source === 'typed') return true;

  return !truck.direct;
};

/** The member's exceptions are sorted so a retry of the same work is byte-for-byte the same payload. */
export const buildSubmission = (draft: ChangeoutDraft, context: ChangeoutContext): ChangeoutSubmission => {
  const truck = resolveTruck(draft, context);
  const lines = new Map(standardLines(context).map((line) => [line.item_key, line]));
  const note = draft.note.trim();
  // A different truck than Fleet planned is a different move: it goes to Fleet and leaves the planned alert open.
  const planned = context.alert?.planned_replacement_apparatus_id ?? null;
  const offPlan = planned !== null && truck?.apparatusId !== planned;

  return {
    client_submission_id: draft.clientSubmissionId,
    expected_vehicle_number: context.unit.vehicle_number,
    // A truck keeps its number after a move, so the unit is what proves nothing moved underneath this draft.
    expected_unit_slug: context.unit.slug,
    alert_id: offPlan ? null : draft.alertId,
    to_apparatus_id: truck?.apparatusId ?? null,
    to_vehicle_number: truck !== null && truck.apparatusId === null ? truck.vehicleNumber : null,
    checklist_version: context.equipment.checklist_version,
    exceptions: Object.keys(draft.exceptions)
      .filter((key) => lines.has(key))
      .sort()
      .map((key) => {
        const choice = draft.exceptions[key];
        const lineNote = choice.note.trim();

        return { item_key: key, handling: choice.handling, quantity: choice.quantity, condition: choice.condition, note: lineNote === '' ? null : lineNote };
      }),
    note: note === '' ? null : note,
  };
};

export interface ReconcileResult {
  draft: ChangeoutDraft;
  /** Names of choices that no longer apply to the fresh list. */
  dropped: string[];
  truckCleared: boolean;
}

/** Fit a saved draft onto a fresh context. Choices that still apply survive, and the rest are named for the member. */
export const reconcileDraft = (draft: ChangeoutDraft, context: ChangeoutContext, previous: ChangeoutContext | null): ReconcileResult => {
  const fresh = new Map(standardLines(applyTruckDefaults(context, resolveTruck(draft, context))).map((line) => [line.item_key, line]));
  const oldNames = new Map((previous ? allLines(previous) : []).map((line) => [line.item_key, line.name]));
  let exceptions: Record<string, LineChoice> = {};
  const dropped: string[] = [];
  for (const [key, choice] of Object.entries(draft.exceptions)) {
    const line = fresh.get(key);
    if (line) exceptions = withLineChoice(exceptions, line, choice);
    else dropped.push(oldNames.get(key) ?? key);
  }
  const truckCleared = context.mode === 'start' && draft.choice !== null && resolveTruck(draft, context) === null && draft.choice.kind === 'listed';

  return {
    draft: { ...draft, exceptions, choice: truckCleared ? null : draft.choice, step: truckCleared ? 'truck' : draft.step },
    dropped,
    truckCleared,
  };
};

export const hasWork = (draft: ChangeoutDraft): boolean => (
  draft.attempted
  || draft.choice !== null
  || draft.typedVehicle.trim() !== ''
  || draft.note.trim() !== ''
  || Object.keys(draft.exceptions).length > 0
);

/** Lines that will end waiting on someone: controlled lines, then anything left as Not sure. */
export const waitingNames = (context: ChangeoutContext, exceptions: Record<string, LineChoice>): string[] => [
  ...controlledLines(context).map((line) => line.name),
  ...standardLines(context).filter((line) => effectiveChoice(line, exceptions).handling === 'review').map((line) => line.name),
];

/** Router path for a summary's Daily route. The router's basename is /daily, so that prefix comes off. */
export const dailyRouterPath = (path: string | null): string | null => {
  if (path === null || !path.startsWith('/') || path.startsWith('//')) return null;
  if (path === '/daily' || path === '/daily/') return '/';

  return path.startsWith('/daily/') ? path.slice('/daily'.length) : path;
};

/** Only a station page is a valid way back from the wizard. */
export const stationReturnPath = (value: string | null, stationId: number | null): string => {
  const path = (value ?? '').replace(/^\/daily(?=\/)/, '');
  if (/^\/stations\/\d+$/.test(path)) return path;

  return stationId === null ? '/stations' : `/stations/${stationId}`;
};

export const alertActionPath = (action: AlertAction | null, stationId: number): string | null => {
  const returnTo = `return_to=${encodeURIComponent(`/stations/${stationId}`)}`;
  if (action === null) return null;
  if ((action.kind === 'start' || action.kind === 'handoff') && action.apparatus_id !== null) {
    const alert = action.alert_id === null ? '' : `alert_id=${action.alert_id}&`;

    return `/changeouts/new/${action.apparatus_id}?${alert}${returnTo}`;
  }
  if (action.kind === 'view' && action.changeout_id !== null) return `/changeouts/${action.changeout_id}?${returnTo}`;

  return null;
};
