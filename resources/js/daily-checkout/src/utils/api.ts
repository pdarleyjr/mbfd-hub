import {
  Apparatus, ChecklistData, ChecklistField, ChecklistInputType, InspectionSubmission, EmployeeOption, ScheduledChecklistTask, Station, StationDetail,
  Room, RoomAsset, RoomAudit, BigTicketRequest, BigTicketRequestFormData,
  StationInventorySubmission, InventorySubmissionItem, PINVerifyRequest, PINVerifyResponse,
  InventoryV2Response, SupplyRequest, UpdateItemRequest, CreateSupplyRequestRequest,
  StationInspectionSummary, FireEquipmentRequestSummary,
  SingleGasMeterSummary, StationRequestSummary, ApparatusServiceTicketSummary, StationActivityEntry, RoomProfile,
} from '../types';

const API_BASE = '/api';

// Default headers for all API requests
const DEFAULT_HEADERS = {
  'Accept': 'application/json',
  'Content-Type': 'application/json',
};

const mutationHeaders = (): Record<string, string> => {
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

export class ApiRequestError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code?: string,
  ) {
    super(message);
    this.name = 'ApiRequestError';
  }
}

export const isChecklistVersion = (value: unknown): value is string => (
  typeof value === 'string' && /^[a-f0-9]{64}$/i.test(value)
);

const responseMessage = async (response: Response, fallback: string): Promise<string> => {
  const payload = await response.json().catch(() => null);

  return typeof payload?.message === 'string' ? payload.message : fallback;
};

export class ApiClient {
  static async getApparatuses(): Promise<Apparatus[]> {
    const response = await fetch(`${API_BASE}/public/apparatuses`, {
    headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch apparatuses');
    }
    return response.json();
  }

  static async getEmployees(): Promise<EmployeeOption[]> {
    const response = await fetch(`${API_BASE}/public/employees/list`, {
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
    return this.fetchChecklist(
      apparatusId,
      'inspection-sessions',
      { inspection_session_start_key: issuanceKey },
      true,
    );
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
    const response = await fetch(
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
            };
          });
        })()
      : [];

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

    const checklist: ChecklistData = {
      checklist_version: checklistVersion.toLowerCase(),
      schema_version: schemaVersion,
      template_id: schemaVersion === 2 && typeof rawChecklist?.template_id === 'string' ? rawChecklist.template_id : undefined,
      template_version: schemaVersion === 2 && typeof rawChecklist?.template_version === 'string' ? rawChecklist.template_version : undefined,
      inspection_date: schemaVersion === 2 && typeof payload?.inspection_date === 'string' ? payload.inspection_date : undefined,
      inspection_date_field_id: schemaVersion === 2 && typeof rawChecklist?.inspectionDateFieldId === 'string' ? rawChecklist.inspectionDateFieldId : undefined,
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
                  notes: item?.notes ?? item?.note ?? '',
                  inputType: schemaVersion === 2 ? item.inputType : undefined,
                  expectedQuantity: schemaVersion === 2 ? item.expectedQuantity : undefined,
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
  ): Promise<{ review_status?: 'approved' | 'pending_review' }> {
    const response = await fetch(`${API_BASE}/public/apparatuses/${apparatusId}/inspections`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: mutationHeaders(),
      body: JSON.stringify(data),
    });

    if (!response.ok) {
      const payload = await response.json().catch(() => null);
      throw new ApiRequestError(
        typeof payload?.message === 'string' ? payload.message : 'Failed to submit inspection',
        response.status,
        typeof payload?.code === 'string' ? payload.code : undefined,
      );
    }

    return response.json();
  }

  // Station API methods
  static async getStations(): Promise<Station[]> {
    const response = await fetch(`${API_BASE}/public/stations`, {
      headers: { ...DEFAULT_HEADERS },
      cache: 'no-store',
    });
    if (!response.ok) {
      throw new Error('Failed to fetch stations');
    }
    const data = await response.json();
    return data.stations || data; // Extract stations array from response
  }

  static async getStation(id: number): Promise<StationDetail> {
    const response = await fetch(`${API_BASE}/public/stations/${id}`, {
      headers: { ...DEFAULT_HEADERS },
      cache: 'no-store',
    });
    if (!response.ok) {
      throw new Error('Failed to fetch station');
    }
    return response.json();
  }

  static async createStation(data: Partial<Station>): Promise<Station> {
    const response = await fetch(`${API_BASE}/admin/stations`, {
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
    const response = await fetch(`${API_BASE}/admin/stations/${id}`, {
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
    const response = await fetch(`${API_BASE}/admin/stations/${id}`, {
      method: 'DELETE',
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to delete station');
    }
  }

  // Alias methods for simpler usage in components
  static async getRoom(stationId: number, roomId: number): Promise<Room> {
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/rooms`, {
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
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/rooms/${roomId}/assets`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch room assets');
    }
    const payload = await response.json();
    return payload.assets || payload;
  }

  static async getRoomAudits(stationId: number, roomId: number): Promise<RoomAudit[]> {
    const response = await fetch(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/audits`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch room audits');
    }
    return response.json();
  }

  static async createRoomAsset(stationId: number, roomId: number, data: Partial<RoomAsset>): Promise<RoomAsset> {
    const response = await fetch(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/assets`, {
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
    const response = await fetch(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/audits`, {
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
    const response = await fetch(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/audits/${auditId}/complete`, {
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
    const response = await fetch(`${API_BASE}/big-ticket-requests`, {
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
    const response = await fetch(`${API_BASE}/stations/${stationId}/big-ticket-requests`, {
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
    const response = await fetch(`${API_BASE}/station-inventory/categories`, {
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
    const response = await fetch(`${API_BASE}/station-inventory-submissions`, {
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
    const response = await fetch(`${API_BASE}/station-inventory-submissions/${submissionId}/pdf`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to download PDF');
    }
    return response.blob();
  }

  static async getStationInventorySubmissions(stationId: number): Promise<StationInventorySubmission[]> {
    const response = await fetch(`${API_BASE}/stations/${stationId}/station-inventory-submissions`, {
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

  static async verifyPIN(request: PINVerifyRequest): Promise<PINVerifyResponse> {
    const response = await fetch(`${API_BASE}/v2/station-inventory/verify-pin`, {
      method: 'POST',
      headers: { ...DEFAULT_HEADERS },
      body: JSON.stringify(request),
    });
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Invalid PIN');
    }
    return response.json();
  }

  static async getInventoryV2(inventoryUrl: string): Promise<InventoryV2Response> {
    // inventoryUrl is a complete signed URL from the backend - use as-is
    const response = await fetch(inventoryUrl, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch inventory');
    }
    return response.json();
  }

  static async updateInventoryItem(
    inventoryUrl: string,
    stationInventoryItemId: number,
    data: UpdateItemRequest
  ): Promise<{ success: boolean; message: string }> {
    // Reuse the PIN-issued base signature and append the station inventory item.
    // The API validates that signature against the base URL server-side.
    const baseUrl = inventoryUrl.split('?')[0]; // Get everything before query string
    const queryString = inventoryUrl.split('?')[1]; // Get query string with signature
    const url = `${baseUrl}/item/${stationInventoryItemId}?${queryString}`;
    
    const response = await fetch(url, {
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

  static async getSupplyRequests(supplyRequestsUrl: string): Promise<{ success: boolean; requests: SupplyRequest[] }> {
    // supplyRequestsUrl is a complete signed URL from the backend - use as-is
    const response = await fetch(supplyRequestsUrl, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch supply requests');
    }
    return response.json();
  }

  // ============================================
  // Station Detail Sub-Resource API
  // ============================================

  static async getStationInspections(stationId: number): Promise<StationInspectionSummary[]> {
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/inspections`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch station inspections');
    }
    const data = await response.json();
    return data.inspections || [];
  }

  static async getEquipmentRequests(stationId: number): Promise<FireEquipmentRequestSummary[]> {
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/equipment-requests`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch equipment requests');
    }
    const data = await response.json();
    return data.equipment_requests || [];
  }

  static async getStationRequests(stationId: number, scope: 'open' | 'all' = 'all'): Promise<StationRequestSummary[]> {
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/requests?scope=${scope}`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch station requests');
    }
    const payload = await response.json();
    return payload.data || [];
  }

  static async getApparatusServiceTickets(stationId: number, scope: 'open' | 'all' = 'open'): Promise<ApparatusServiceTicketSummary[]> {
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/service-tickets?scope=${scope}&per_page=100`, {
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
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/service-tickets?scope=open&per_page=1`, {
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
    const response = await fetch(`${API_BASE}/public/apparatuses/${apparatusId}/service-notices`, {
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
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/activity`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch station activity');
    }
    const payload = await response.json();
    return payload.activity || [];
  }

  static async getRoomProfile(stationId: number, roomId: number): Promise<RoomProfile> {
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/rooms/${roomId}/profile`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch room profile');
    }
    return response.json();
  }

  static async getGasMeters(stationId: number): Promise<SingleGasMeterSummary[]> {
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/gas-meters`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch gas meters');
    }
    const data = await response.json();
    return data.gas_meters || [];
  }

  static async createSupplyRequest(
    supplyRequestsUrl: string,
    request: CreateSupplyRequestRequest
  ): Promise<{ success: boolean; message: string }> {
    // supplyRequestsUrl is a complete signed URL from the backend - use as-is for POST
    const response = await fetch(supplyRequestsUrl, {
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
