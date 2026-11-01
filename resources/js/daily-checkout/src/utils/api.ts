import {
  Apparatus, CommandOverview, ChecklistData, ChecklistField, ChecklistInputType, ChecklistItem, PendingInventoryCorrection, InspectionSubmission, InspectionRevision, EmployeeOption, ScheduledChecklistTask, Station, StationDetail,
  Room, RoomAsset, RoomAudit, BigTicketRequest, BigTicketRequestFormData,
  StationInventorySubmission, InventorySubmissionItem, Shift,
  InventoryV2Response, SupplyRequest, UpdateItemRequest, CreateSupplyRequestRequest,
  StationInspectionSummary, FireEquipmentRequestSummary,
  SingleGasMeterSummary, StationRequestSummary, ApparatusServiceTicketSummary, StationActivityEntry, RoomProfile, FleetVehicle,
  VehicleIdentification, VehicleIdentificationSubmission,
} from '../types';
import { purgeOnSessionEnd } from '../pwa/userCaches.ts';
import { parseVehicleIdentification, parseVehicleIdentificationResponse } from './vehicleIdentification';
import { parseChangeoutHandoff } from '../components/changeout/changeoutParse';

import type { StationCheckoutDetail, StationOverview, StationRoster } from '../types';

const API_BASE = '/api';

declare global {
  interface Window {
    __MBFD_CANONICAL_ORIGIN__?: string;
  }
}

// Default headers for all API requests
const DEFAULT_HEADERS = {
  'Accept': 'application/json',
  'Content-Type': 'application/json',
};

export const mutationHeaders = (): Record<string, string> => {
  const encoded = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))?.slice(11);

  return {
    ...DEFAULT_HEADERS,
    ...(encoded ? { 'X-XSRF-TOKEN': decodeURIComponent(encoded) } : {}),
  };
};

const normalizeItemStatus = (status?: string): 'Present' | 'Missing' | 'Damaged' => {
  if (status === 'Missing' || status === 'Damaged' || status === 'Present') {
    return status;
  }

  return 'Present';
};

const isChecklistInputType = (value: unknown): value is ChecklistInputType => (
  value === 'text'
  || value === 'number'
  || value === 'date'
  || value === 'checkbox'
  || value === 'percentage'
);

const nonEmptyString = (value: unknown): value is string => typeof value === 'string' && value.trim() !== '';

/** Display-only item details; anything that is not text is dropped. */
const optionalItemText = (item: Record<string, unknown>): Pick<ChecklistItem, 'manufacturer' | 'model' | 'description'> => ({
  ...(nonEmptyString(item.manufacturer) ? { manufacturer: item.manufacturer } : {}),
  ...(nonEmptyString(item.model) ? { model: item.model } : {}),
  ...(nonEmptyString(item.description) ? { description: item.description } : {}),
});

/** The server list is advisory display data: unusable entries are skipped instead of failing the checklist. */
const parsePendingCorrections = (value: unknown): PendingInventoryCorrection[] => (Array.isArray(value) ? value : []).flatMap((entry): PendingInventoryCorrection[] => {
  if (!entry || typeof entry !== 'object') return [];
  const row = entry as Record<string, unknown>;
  // Another member's report arrives as a bare marker: no id and no reason, only its status and location.
  if (typeof row.status !== 'string') return [];
  return [{
    id: typeof row.id === 'number' ? row.id : null,
    compartment_id: nonEmptyString(row.compartment_id) ? row.compartment_id : null,
    item_id: nonEmptyString(row.item_id) ? row.item_id : null,
    reason: typeof row.reason === 'string' ? row.reason : null,
    reason_label: typeof row.reason_label === 'string' ? row.reason_label : undefined,
    status: row.status,
    reported_at: typeof row.reported_at === 'string' ? row.reported_at : undefined,
    mine: row.mine === true,
  }];
});

export class ApiRequestError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code?: string,
    /** Delay requested by a 429/503 `Retry-After` header, when present. */
    readonly retryAfterMs?: number,
  ) {
    super(message);
    this.name = 'ApiRequestError';
  }
}

/**
 * The request did not finish in time. For a submission the outcome is
 * unknown: the server may have stored it, so the caller must keep the durable
 * payload and replay it with the same client_submission_id.
 */
export class ApiTimeoutError extends Error {
  constructor(message = 'The server did not respond in time.') {
    super(message);
    this.name = 'ApiTimeoutError';
  }
}

/** Reads and small mutations. */
export const READ_TIMEOUT_MS = 20_000;
/**
 * A Daily Checkout payload carries base64 photos and a signature (often a few
 * MB). Allow a slow cellular upload to finish before declaring the outcome
 * unknown; the queued record is replayed idempotently afterwards.
 */
export const SUBMISSION_TIMEOUT_MS = 60_000;

/**
 * `fetch` with an overall deadline. The deadline also covers reading the body:
 * the timer is not cleared when headers arrive, and aborting a fully consumed
 * response has no effect.
 */
export const fetchWithTimeout = async (
  input: string,
  init: RequestInit = {},
  timeoutMs: number = (init.method ?? 'GET').toUpperCase() === 'GET' ? READ_TIMEOUT_MS : SUBMISSION_TIMEOUT_MS,
): Promise<Response> => {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(input, { ...init, signal: controller.signal });
  } catch (error) {
    clearTimeout(timer);
    if (controller.signal.aborted) throw new ApiTimeoutError();
    throw error;
  }
};

const STATION_REQUEST_PAGE_SIZE = 200;
/** Safety bound: at most 2,000 requests of station history. */
const STATION_REQUEST_MAX_PAGES = 10;

/** Parse `Retry-After` (delta seconds or HTTP date), capped at 15 minutes. */
export const parseRetryAfter = (value: string | null, now = Date.now()): number | undefined => {
  if (!value) return undefined;
  const trimmed = value.trim();
  const milliseconds = /^\d+$/.test(trimmed) ? Number(trimmed) * 1000 : Date.parse(trimmed) - now;

  return Number.isFinite(milliseconds) ? Math.min(Math.max(milliseconds, 0), 15 * 60 * 1000) : undefined;
};

let sessionExpiryRedirectStarted = false;

export function isApiAuthenticationError(error: unknown): error is ApiRequestError {
  return error instanceof ApiRequestError && error.status === 401;
}

export function redirectToLoginAfterSessionExpiry(): void {
  if (
    typeof window === 'undefined'
    || sessionExpiryRedirectStarted
    || window.location.pathname === '/login'
  ) {
    return;
  }

  sessionExpiryRedirectStarted = true;
  // Signed out: drop the cached station/apparatus responses first. The worker message is
  // posted synchronously, so it still goes out as the page navigates to sign-in.
  void purgeOnSessionEnd();
  const configuredOrigin = window.__MBFD_CANONICAL_ORIGIN__;
  let loginUrl = new URL('/login', window.location.origin);

  if (typeof configuredOrigin === 'string' && configuredOrigin.trim() !== '') {
    try {
      loginUrl = new URL('/login', configuredOrigin);
    } catch {
      // Fall back to the current origin when runtime configuration is absent or invalid.
    }
  }

  loginUrl.searchParams.set('session_expired', '1');
  window.location.replace(loginUrl.toString());
}

export const isChecklistVersion = (value: unknown): value is string => (
  typeof value === 'string' && /^[a-f0-9]{64}$/i.test(value)
);

const responseMessage = async (response: Response, fallback: string): Promise<string> => {
  const payload = await response.json().catch(() => null);

  return typeof payload?.message === 'string' ? payload.message : fallback;
};

const sessionStartsInFlight = new Map<string, Promise<ChecklistData>>();

export class ApiClient {
  static async getInspectionRevisions(): Promise<InspectionRevision[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/inspection-revisions`, { headers: DEFAULT_HEADERS, cache: 'no-store' });
    if (!response.ok) throw new Error('Requested clarifications could not be loaded. Reconnect and try again.');
    return (await response.json()).data;
  }

  static async submitInspectionRevision(id: number, data: { reason: string; value?: number }): Promise<void> {
    const response = await fetchWithTimeout(`${API_BASE}/public/inspection-exceptions/${id}/revision`, { method: 'POST', headers: mutationHeaders(), body: JSON.stringify(data) });
    if (!response.ok) throw new Error(await responseMessage(response, 'Your clarification was not sent. Your draft is saved on this device; try again.'));
  }
  static async getApparatuses(): Promise<Apparatus[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/apparatuses`, {
    headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch apparatuses');
    }
    return response.json();
  }

  /**
   * Every physical vehicle ID (frontline and reserve) for the checkout's
   * "confirm the physical vehicle" dropdown. The last good list is kept on
   * this device so the dropdown still works offline; with neither, the caller
   * falls back to the assigned vehicle plus "Not listed".
   */
  static async getFleetVehicles(): Promise<FleetVehicle[]> {
    const cacheKey = 'mbfd.daily.fleetVehicles.v1';
    const valid = (value: unknown): FleetVehicle[] => (Array.isArray(value) ? value : []).filter((entry): entry is FleetVehicle => (
      typeof entry === 'object' && entry !== null && typeof (entry as FleetVehicle).vehicle_number === 'string' && (entry as FleetVehicle).vehicle_number !== ''
    )).map((entry) => ({
      vehicle_number: entry.vehicle_number,
      designation: typeof entry.designation === 'string' ? entry.designation : null,
      role: entry.role === 'reserve' || entry.role === 'out_of_service' ? entry.role : 'frontline',
    }));
    try {
      const response = await fetchWithTimeout(`${API_BASE}/public/fleet-vehicles`, { headers: { ...DEFAULT_HEADERS } });
      if (!response.ok) throw new Error('Failed to fetch fleet vehicles');
      const vehicles = valid(((await response.json()) as { vehicles?: unknown }).vehicles);
      try { localStorage.setItem(cacheKey, JSON.stringify(vehicles)); } catch { /* storage unavailable */ }

      return vehicles;
    } catch {
      try { return valid(JSON.parse(localStorage.getItem(cacheKey) ?? '[]')); } catch { return []; }
    }
  }

  static async getEmployees(): Promise<EmployeeOption[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/employees/list`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      return [];
    }
    return response.json();
  }

  static async getChecklist(apparatusId: number): Promise<ChecklistData> {
    return this.fetchChecklist(apparatusId, 'checklist');
  }

  static async startInspectionSession(apparatusId: number, issuanceKey: string): Promise<ChecklistData> {
    // Overlapping loads (a queue change or remount mid-request) share one
    // issuance request per local key instead of sending a duplicate POST.
    const flightKey = `${apparatusId}:${issuanceKey}`;
    const existing = sessionStartsInFlight.get(flightKey);
    if (existing) return existing;
    const request = this.fetchChecklist(
      apparatusId,
      'inspection-sessions',
      { inspection_session_start_key: issuanceKey },
      true,
    ).finally(() => sessionStartsInFlight.delete(flightKey));
    sessionStartsInFlight.set(flightKey, request);
    return request;
  }

  static async abandonInspectionSession(
    apparatusId: number,
    sessionId: string,
    token: string,
    replayKey: string,
    transitionKey: string,
  ): Promise<ChecklistData> {
    return this.fetchChecklist(
      apparatusId,
      `inspection-sessions/${sessionId}/abandon`,
      {
        inspection_session_token: token,
        inspection_session_replay_key: replayKey,
        inspection_session_transition_key: transitionKey,
      },
      true,
    );
  }

  private static async fetchChecklist(
    apparatusId: number,
    endpoint: string,
    body?: Record<string, string>,
    includesInspectionSession = false,
  ): Promise<ChecklistData> {
    const response = await fetchWithTimeout(
      `${API_BASE}/public/apparatuses/${apparatusId}/${endpoint}`,
      {
        ...(body ? {
          method: 'POST',
          body: JSON.stringify(body),
          credentials: 'same-origin' as const,
        } : {}),
        headers: body ? mutationHeaders() : { ...DEFAULT_HEADERS },
      },
    );
    if (!response.ok) {
      throw new ApiRequestError(
        await responseMessage(response, 'The Daily Checkout checklist is unavailable.'),
        response.status,
      );
    }

    const payload = await response.json();
    const checklistVersion = payload?.checklist_version;
    if (!isChecklistVersion(checklistVersion)) {
      throw new ApiRequestError(
        'The Daily Checkout checklist version is unavailable. Contact an officer before continuing.',
        503,
      );
    }

    const rawChecklist = payload?.checklist ?? payload;
    const rawSchemaVersion = rawChecklist?.schema_version;
    if (rawSchemaVersion !== undefined && rawSchemaVersion !== 1 && rawSchemaVersion !== 2) {
      throw new ApiRequestError('The Daily Checkout checklist schema is unavailable. Contact an officer before continuing.', 503);
    }

    const schemaVersion: 1 | 2 = rawSchemaVersion === 2 ? 2 : 1;
    const rawCompartments = Array.isArray(rawChecklist?.compartments) ? rawChecklist.compartments : [];
    const v2Unavailable = (): never => {
      throw new ApiRequestError('The Daily Checkout checklist contract is unavailable. Contact an officer before continuing.', 503);
    };
    const parseTask = (task: any): ScheduledChecklistTask => {
      if (
        !task
        || typeof task.id !== 'string'
        || typeof task.name !== 'string'
        || !task.recurrence
        || (task.recurrence.type !== 'weekday' && task.recurrence.type !== 'monthly_day')
      ) {
        return v2Unavailable();
      }

      if (task.recurrence.type === 'weekday' && typeof task.recurrence.weekday !== 'string') {
        return v2Unavailable();
      }

      if (task.recurrence.type === 'monthly_day' && (!Number.isInteger(task.recurrence.day) || task.recurrence.day < 1 || task.recurrence.day > 31)) {
        return v2Unavailable();
      }

      return {
        id: task.id,
        name: task.name,
        instructions: typeof task.instructions === 'string' ? task.instructions : undefined,
        recurrence: task.recurrence,
      };
    };

    const fields: ChecklistField[] = schemaVersion === 2
      ? (() => {
          if (!Array.isArray(rawChecklist?.fields)) return v2Unavailable();

          return rawChecklist.fields.map((field: any) => {
            if (!field || typeof field.id !== 'string' || typeof field.name !== 'string' || !isChecklistInputType(field.inputType)) {
              return v2Unavailable();
            }

            return {
              id: field.id,
              name: field.name,
              inputType: field.inputType,
              required: field.required === true,
              multiline: field.multiline === true,
            };
          });
        })()
      : (Array.isArray(rawChecklist?.officerChecklist) ? rawChecklist.officerChecklist : []).map((field: any) => ({
          id: field.id,
          name: field.name,
          inputType: isChecklistInputType(field.inputType) ? field.inputType : 'text',
          required: field.required === true,
          multiline: field.multiline === true,
        }));

    const dueTasks = schemaVersion === 2
      ? (() => {
          if (!Array.isArray(payload?.due_tasks)) return v2Unavailable();

          return payload.due_tasks.map(parseTask);
        })()
      : [];

    const inspectionSession = schemaVersion === 2 && includesInspectionSession
      ? (() => {
          const session = payload?.inspection_session;
          if (
            !session
            || typeof session.id !== 'string'
            || !/^[a-f0-9-]{36}$/i.test(session.id)
            || typeof session.token !== 'string'
            || !/^[a-f0-9]{64}$/i.test(session.token)
            || typeof session.issued_at !== 'string'
            || typeof session.expires_at !== 'string'
            || typeof session.duty_date !== 'string'
            || session.duty_date !== payload?.inspection_date
            || typeof session.checklist_template_id !== 'string'
            || typeof session.checklist_template_version !== 'string'
            || !isChecklistVersion(session.checklist_hash)
            || session.checklist_hash.toLowerCase() !== checklistVersion.toLowerCase()
            || !isChecklistVersion(session.due_tasks_hash)
            || typeof session.replay_key !== 'string'
            || !/^[a-f0-9-]{36}$/i.test(session.replay_key)
            || !Array.isArray(session.due_tasks)
            || JSON.stringify(session.due_tasks.map(parseTask)) !== JSON.stringify(dueTasks)
          ) {
            return v2Unavailable();
          }

          return {
            id: session.id,
            token: session.token,
            issued_at: session.issued_at,
            expires_at: session.expires_at,
            duty_date: session.duty_date,
            checklist_template_id: session.checklist_template_id,
            checklist_template_version: session.checklist_template_version,
            checklist_hash: session.checklist_hash.toLowerCase(),
            due_tasks: dueTasks,
            due_tasks_hash: session.due_tasks_hash.toLowerCase(),
            replay_key: session.replay_key,
          };
        })()
      : undefined;

    const checklistBaseVersion = [payload?.checklist_base_version, rawChecklist?.checklist_base_version].find(nonEmptyString);
    // Sent next to the checklist (and on the Fire Boat session start), never inside the hashed contract.
    const vehicleIdentification = parseVehicleIdentification(payload?.vehicle_identification);
    const changeoutHandoff = parseChangeoutHandoff(payload?.changeout_handoff);
    const checklist: ChecklistData = {
      open_findings: Array.isArray(payload?.open_findings) ? payload.open_findings : [],
      ...(checklistBaseVersion ? { checklist_base_version: checklistBaseVersion } : {}),
      pending_corrections: parsePendingCorrections(payload?.pending_corrections),
      ...(vehicleIdentification ? { vehicle_identification: vehicleIdentification } : {}),
      ...(payload?.vehicle_id_required === false ? { vehicle_id_required: false } : {}),
      ...(changeoutHandoff ? { changeout_handoff: changeoutHandoff } : {}),
      checklist_version: checklistVersion.toLowerCase(),
      checklist_type: typeof payload?.checklist_type === 'string' ? payload.checklist_type : undefined,
      schema_version: schemaVersion,
      template_id: schemaVersion === 2 && typeof rawChecklist?.template_id === 'string' ? rawChecklist.template_id : undefined,
      template_version: schemaVersion === 2 && typeof rawChecklist?.template_version === 'string' ? rawChecklist.template_version : undefined,
      inspection_date: typeof payload?.inspection_date === 'string' ? payload.inspection_date : undefined,
      inspection_date_field_id: typeof rawChecklist?.inspectionDateFieldId === 'string' ? rawChecklist.inspectionDateFieldId : fields.some(field => field.id === 'inspection_date') ? 'inspection_date' : undefined,
      fields,
      due_tasks: dueTasks,
      inspection_session: inspectionSession,
      compartments: rawCompartments.map((compartment: any, compartmentIndex: number) => {
        if (schemaVersion === 2 && (!compartment || typeof compartment.id !== 'string' || typeof (compartment.name ?? compartment.title) !== 'string')) {
          return v2Unavailable();
        }

        const compartmentId = schemaVersion === 2 ? compartment.id : compartment?.id ?? `compartment-${compartmentIndex + 1}`;

        return {
          id: compartmentId,
          name: schemaVersion === 2 ? compartment.name ?? compartment.title : compartment?.name ?? compartment?.title ?? `Compartment ${compartmentIndex + 1}`,
          items: Array.isArray(compartment?.items)
            ? compartment.items.map((item: any, itemIndex: number) => {
                if (schemaVersion === 2 && (!item || typeof item.id !== 'string' || typeof item.name !== 'string' || !isChecklistInputType(item.inputType))) {
                  return v2Unavailable();
                }

                if (schemaVersion === 2 && item.expectedQuantity !== undefined && (!Number.isInteger(item.expectedQuantity) || item.expectedQuantity < 1)) {
                  return v2Unavailable();
                }

                return {
                  id: schemaVersion === 2 ? item.id : item?.id ?? `${compartmentId}-item-${itemIndex + 1}`,
                  name: schemaVersion === 2 ? item.name : item?.name ?? `Item ${itemIndex + 1}`,
                  status: normalizeItemStatus(item?.status),
                  notes: '',
                  instructions: item?.notes ?? item?.note,
                  inputType: isChecklistInputType(item.inputType) ? item.inputType : undefined,
                  valueRequired: item.valueRequired !== false,
                  expectedQuantity: typeof item.expectedQuantity === 'number' ? item.expectedQuantity : undefined,
                  ...optionalItemText(item),
                };
              })
            : [],
        };
      }),
    };

    if (schemaVersion === 2 && (
      !checklist.template_id
      || !checklist.template_version
      || !checklist.inspection_date
      || !checklist.inspection_date_field_id
      || !checklist.fields.some((field) => field.id === checklist.inspection_date_field_id && field.inputType === 'date')
    )) {
      return v2Unavailable();
    }

    if (!checklist.compartments.some((compartment) => compartment.items.length > 0)) {
      throw new ApiRequestError(
        'The Daily Checkout checklist is unavailable. Contact an officer before continuing.',
        503,
      );
    }

    return checklist;
  }

  static async submitInspection(
    apparatusId: number,
    data: InspectionSubmission,
  ): Promise<{ review_status?: 'approved' | 'pending_review'; processing_status?: 'accepted' | 'accepted_with_exception' }> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), SUBMISSION_TIMEOUT_MS);
    try {
      const response = await fetch(`${API_BASE}/public/apparatuses/${apparatusId}/inspections`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: mutationHeaders(),
        body: JSON.stringify(data),
        signal: controller.signal,
      });

      if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new ApiRequestError(
          typeof payload?.message === 'string' ? payload.message : 'Failed to submit inspection',
          response.status,
          typeof payload?.code === 'string' ? payload.code : undefined,
          parseRetryAfter(response.headers.get('Retry-After')),
        );
      }

      return await response.json();
    } catch (error) {
      if (controller.signal.aborted) {
        throw new ApiTimeoutError('The server did not confirm this inspection in time. It is saved on this device and will be sent again; the server ignores a duplicate.');
      }
      throw error;
    } finally {
      clearTimeout(timer);
    }
  }

  /**
   * Save one-time vehicle information outside a checkout (the Captain 5 page). The same
   * client_submission_id may be sent again after a lost response; the server ignores the duplicate.
   * Returns the refreshed block, or undefined when nothing is open any more.
   */
  static async submitVehicleIdentification(
    apparatusId: number,
    body: { client_submission_id: string } & VehicleIdentificationSubmission,
  ): Promise<VehicleIdentification | undefined> {
    const response = await fetchWithTimeout(`${API_BASE}/public/apparatuses/${apparatusId}/vehicle-identification`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: mutationHeaders(),
      body: JSON.stringify(body),
    });
    if (!response.ok) {
      throw new ApiRequestError(await responseMessage(response, 'The vehicle information could not be saved.'), response.status);
    }

    return parseVehicleIdentificationResponse(await response.json().catch(() => null));
  }

  // Station API methods
  static async getStations(): Promise<Station[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations`, {
      headers: { ...DEFAULT_HEADERS },
      cache: 'no-store',
    });
    if (!response.ok) {
      throw new ApiRequestError(await responseMessage(response, 'Failed to fetch stations'), response.status);
    }
    const data = await response.json();
    return data.stations || data; // Extract stations array from response
  }

  /** Read-only, server-shaped overview of every active station for the 300 Command profile. */
  static async getCommandOverview(): Promise<CommandOverview> {
    const response = await fetchWithTimeout(`${API_BASE}/public/command/overview`, {
      headers: { ...DEFAULT_HEADERS },
      cache: 'no-store',
    });
    if (!response.ok) {
      throw new ApiRequestError(await responseMessage(response, 'Failed to load the station overview'), response.status);
    }
    return response.json();
  }

  static async getStation(id: number): Promise<StationDetail> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${id}`, {
      headers: { ...DEFAULT_HEADERS },
      cache: 'no-store',
    });
    if (!response.ok) {
      throw new ApiRequestError(await responseMessage(response, 'Failed to fetch station'), response.status);
    }
    return response.json();
  }

  static async createStation(data: Partial<Station>): Promise<Station> {
    const response = await fetchWithTimeout(`${API_BASE}/admin/stations`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: mutationHeaders(),
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      throw new Error('Failed to create station');
    }
    return response.json();
  }

  static async updateStation(id: number, data: Partial<Station>): Promise<Station> {
    const response = await fetchWithTimeout(`${API_BASE}/admin/stations/${id}`, {
      method: 'PUT',
      headers: { ...DEFAULT_HEADERS },
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      throw new Error('Failed to update station');
    }
    return response.json();
  }

  static async deleteStation(id: number): Promise<void> {
    const response = await fetchWithTimeout(`${API_BASE}/admin/stations/${id}`, {
      method: 'DELETE',
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to delete station');
    }
  }

  // Alias methods for simpler usage in components
  static async getRoom(stationId: number, roomId: number): Promise<Room> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/rooms`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch room');
    }
    const payload = await response.json();
    const rooms: Room[] = payload.rooms || payload;
    const room = rooms.find((entry: Room) => entry.id === roomId);
    if (!room) throw new Error('Room not found');
    return room;
  }

  static async getRoomAssets(stationId: number, roomId: number): Promise<RoomAsset[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/rooms/${roomId}/assets`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch room assets');
    }
    const payload = await response.json();
    return payload.assets || payload;
  }

  static async getRoomAudits(stationId: number, roomId: number): Promise<RoomAudit[]> {
    const response = await fetchWithTimeout(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/audits`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch room audits');
    }
    return response.json();
  }

  static async createRoomAsset(stationId: number, roomId: number, data: Partial<RoomAsset>): Promise<RoomAsset> {
    const response = await fetchWithTimeout(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/assets`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: mutationHeaders(),
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      throw new Error('Failed to create room asset');
    }
    return response.json();
  }

  static async createRoomAudit(stationId: number, roomId: number, data: Partial<RoomAudit>): Promise<RoomAudit> {
    const response = await fetchWithTimeout(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/audits`, {
      method: 'POST',
      headers: { ...DEFAULT_HEADERS },
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      throw new Error('Failed to create room audit');
    }
    return response.json();
  }

  static async completeRoomAudit(stationId: number, roomId: number, auditId: number, data: { items: any[] }): Promise<RoomAudit> {
    const response = await fetchWithTimeout(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/audits/${auditId}/complete`, {
      method: 'POST',
      headers: { ...DEFAULT_HEADERS },
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      throw new Error('Failed to complete room audit');
    }
    return response.json();
  }

  // ============================================
  // Big Ticket Request API
  // ============================================

  static async submitBigTicketRequest(data: BigTicketRequestFormData): Promise<BigTicketRequest> {
    const response = await fetchWithTimeout(`${API_BASE}/big-ticket-requests`, {
      method: 'POST',
      headers: { ...DEFAULT_HEADERS },
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to submit big ticket request');
    }
    return response.json();
  }

  static async getBigTicketRequests(stationId: number): Promise<BigTicketRequest[]> {
    const response = await fetchWithTimeout(`${API_BASE}/stations/${stationId}/big-ticket-requests`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch big ticket requests');
    }
    return response.json();
  }

  // ============================================
  // Station Inventory API
  // ============================================

  static async getInventoryCategories(): Promise<{ id: string; name: string; items: { id: string; name: string; unit: string; max_quantity: number }[] }[]> {
    const response = await fetchWithTimeout(`${API_BASE}/station-inventory/categories`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch inventory categories');
    }
    const data = await response.json();
    return data.data || data;
  }

  static async submitStationInventory(
    stationId: number, 
    items: InventorySubmissionItem[], 
    notes?: string,
    employeeName?: string,
    shift?: string
  ): Promise<StationInventorySubmission> {
    const response = await fetchWithTimeout(`${API_BASE}/station-inventory-submissions`, {
      method: 'POST',
      headers: { ...DEFAULT_HEADERS },
      body: JSON.stringify({ 
        station_id: stationId, 
        items, 
        notes,
        employee_name: employeeName,
        shift 
      }),
    });
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to submit station inventory');
    }
    return response.json();
  }

  static async downloadInventoryPdf(submissionId: number): Promise<Blob> {
    const response = await fetchWithTimeout(`${API_BASE}/station-inventory-submissions/${submissionId}/pdf`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to download PDF');
    }
    return response.blob();
  }

  static async getStationInventorySubmissions(stationId: number): Promise<StationInventorySubmission[]> {
    const response = await fetchWithTimeout(`${API_BASE}/stations/${stationId}/station-inventory-submissions`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch inventory submissions');
    }
    const data = await response.json();
    return data.data || data;
  }

  // ============================================
  // Station Inventory V2 API
  // ============================================

  static async getInventoryV2(stationId: number): Promise<InventoryV2Response> {
    const response = await fetchWithTimeout(`${API_BASE}/v2/station-inventory/${stationId}`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch inventory');
    }
    return response.json();
  }

  static async updateInventoryItem(
    stationId: number,
    stationInventoryItemId: number,
    data: UpdateItemRequest
  ): Promise<{ success: boolean; message: string }> {
    const response = await fetchWithTimeout(`${API_BASE}/v2/station-inventory/${stationId}/item/${stationInventoryItemId}`, {
      method: 'PUT',
      credentials: 'same-origin',
      headers: mutationHeaders(),
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to update item');
    }
    return response.json();
  }

  static async getSupplyRequests(stationId: number): Promise<{ success: boolean; requests: SupplyRequest[] }> {
    const response = await fetchWithTimeout(`${API_BASE}/v2/station-inventory/${stationId}/supply-requests`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch supply requests');
    }
    return response.json();
  }

  static async submitInventoryRecord(stationId: number, data: { actor_shift: Shift; client_submission_id: string; notes: string }): Promise<{ success: boolean; submission_id: number }> {
    const response = await fetchWithTimeout(`${API_BASE}/v2/station-inventory/${stationId}/submissions`, {
      method: 'POST', credentials: 'same-origin', headers: mutationHeaders(), body: JSON.stringify(data),
    });
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'The inventory record could not be submitted. Your saved counts are unchanged.');
    }
    return response.json();
  }

  // ============================================
  // Station Detail Sub-Resource API
  // ============================================

  static async getStationInspections(stationId: number): Promise<StationInspectionSummary[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/inspections`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch station inspections');
    }
    const data = await response.json();
    return data.inspections || [];
  }

  static async getEquipmentRequests(stationId: number): Promise<FireEquipmentRequestSummary[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/equipment-requests`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch equipment requests');
    }
    const data = await response.json();
    return data.equipment_requests || [];
  }

  static async getStationRequests(stationId: number, scope: 'open' | 'all' = 'all'): Promise<StationRequestSummary[]> {
    // Complete history is paginated; follow `meta` up to a safety bound. An
    // older server without `meta` returns its single page as before.
    const requests: StationRequestSummary[] = [];
    for (let page = 1; page <= STATION_REQUEST_MAX_PAGES; page += 1) {
      const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/requests?scope=${scope}&per_page=${STATION_REQUEST_PAGE_SIZE}&page=${page}`, {
        headers: { ...DEFAULT_HEADERS },
      });
      if (!response.ok) {
        throw new Error('Failed to fetch station requests');
      }
      const payload = await response.json();
      requests.push(...(Array.isArray(payload?.data) ? payload.data as StationRequestSummary[] : []));
      const currentPage = Number(payload?.meta?.current_page);
      const lastPage = Number(payload?.meta?.last_page);
      if (!Number.isInteger(currentPage) || !Number.isInteger(lastPage) || currentPage >= lastPage) {
        break;
      }
    }

    return requests;
  }

  static async getApparatusServiceTickets(stationId: number, scope: 'open' | 'all' = 'open'): Promise<ApparatusServiceTicketSummary[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/service-tickets?scope=${scope}&per_page=100`, {
      cache: 'no-store',
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch apparatus service tickets');
    }
    const payload = await response.json();
    return payload.data || [];
  }

  static async getOpenApparatusServiceTicketCount(stationId: number): Promise<number> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/service-tickets?scope=open&per_page=1`, {
      cache: 'no-store',
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch apparatus service ticket count');
    }
    const payload = await response.json();
    return Number(payload.meta?.total ?? payload.data?.length ?? 0);
  }

  static async getApparatusServiceNotices(apparatusId: number): Promise<ApparatusServiceTicketSummary[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/apparatuses/${apparatusId}/service-notices`, {
      cache: 'no-store',
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch apparatus service notices');
    }
    const payload = await response.json();
    return payload.data || [];
  }

  static async getStationActivity(stationId: number): Promise<StationActivityEntry[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/activity`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch station activity');
    }
    const payload = await response.json();
    return payload.activity || [];
  }

  /** `background` refreshes leave the idle clock alone, so an unattended shared screen still signs out. */
  static async getStationOverview(stationId: number, background = false): Promise<StationOverview> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/overview`, {
      headers: { ...DEFAULT_HEADERS, ...(background ? { 'X-Hub-Background-Refresh': '1' } : {}) },
      cache: 'no-store',
    });
    if (!response.ok) {
      throw new ApiRequestError(await responseMessage(response, 'The station overview could not be loaded'), response.status);
    }
    const payload = await response.json() as Partial<StationOverview>;
    if (!Array.isArray(payload.units) || !Array.isArray(payload.checkouts) || !Array.isArray(payload.requests) || !payload.shift_window || !payload.alert) {
      throw new Error('The station overview could not be loaded');
    }
    return payload as StationOverview;
  }

  static async getStationCheckout(stationId: number, checkoutId: number): Promise<StationCheckoutDetail> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/checkouts/${checkoutId}`, {
      headers: { ...DEFAULT_HEADERS },
      cache: 'no-store',
    });
    if (!response.ok) {
      throw new ApiRequestError(await responseMessage(response, 'The checkout could not be loaded.'), response.status);
    }
    const payload = await response.json() as { checkout?: StationCheckoutDetail };
    if (!Array.isArray(payload.checkout?.compartments)) {
      throw new Error('The checkout could not be loaded.');
    }
    return payload.checkout;
  }

  static async getRoomProfile(stationId: number, roomId: number): Promise<RoomProfile> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/rooms/${roomId}/profile`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch room profile');
    }
    return response.json();
  }

  static async getStationRoster(stationId: number, background = false): Promise<StationRoster> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/roster`, {
      headers: { ...DEFAULT_HEADERS, ...(background ? { 'X-Hub-Background-Refresh': '1' } : {}) },
      cache: 'no-store',
    });
    if (!response.ok) {
      throw new ApiRequestError(await responseMessage(response, "Today's roster could not be loaded"), response.status);
    }
    const payload = await response.json() as Partial<StationRoster>;
    if (typeof payload.available !== 'boolean' || !Array.isArray(payload.sections)) {
      throw new Error("Today's roster could not be loaded");
    }
    return payload as StationRoster;
  }

  static async getGasMeters(stationId: number): Promise<SingleGasMeterSummary[]> {
    const response = await fetchWithTimeout(`${API_BASE}/public/stations/${stationId}/gas-meters`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch gas meters');
    }
    const data = await response.json();
    return data.gas_meters || [];
  }

  static async createSupplyRequest(
    stationId: number,
    request: CreateSupplyRequestRequest
  ): Promise<{ success: boolean; message: string }> {
    const response = await fetchWithTimeout(`${API_BASE}/v2/station-inventory/${stationId}/supply-requests`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: mutationHeaders(),
      body: JSON.stringify(request),
    });
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to create supply request');
    }
    return response.json();
  }
}
