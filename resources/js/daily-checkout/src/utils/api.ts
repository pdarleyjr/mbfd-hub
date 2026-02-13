import { Apparatus, ChecklistData, InspectionSubmission, Station, StationDetail, Room, RoomAsset, RoomAudit, BigTicketRequest, BigTicketRequestFormData, StationInventorySubmission, InventorySubmissionItem, PINVerifyRequest, PINVerifyResponse, InventoryV2Response, SupplyRequest, UpdateItemRequest, CreateSupplyRequestRequest } from '../types';

const API_BASE = '/api';

// Default headers for all API requests
const DEFAULT_HEADERS = {
  'Accept': 'application/json',
  'Content-Type': 'application/json',
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

  static async getChecklist(apparatusId: number): Promise<ChecklistData> {
    const response = await fetch(`${API_BASE}/public/apparatuses/${apparatusId}/checklist`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch checklist');
    }
    return response.json();
  }

  static async submitInspection(apparatusId: number, data: InspectionSubmission): Promise<{ success: boolean; message: string }> {
    const response = await fetch(`${API_BASE}/public/apparatuses/${apparatusId}/inspections`, {
      method: 'POST',
      headers: { ...DEFAULT_HEADERS },
      body: JSON.stringify(data),
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to submit inspection');
    }

    return response.json();
  }

  // Station API methods
  static async getStations(): Promise<Station[]> {
    const response = await fetch(`${API_BASE}/public/stations`, {
      headers: { ...DEFAULT_HEADERS },
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
    });
    if (!response.ok) {
      throw new Error('Failed to fetch station');
    }
    return response.json();
  }

  static async createStation(data: Partial<Station>): Promise<Station> {
    const response = await fetch(`${API_BASE}/admin/stations`, {
      method: 'POST',
      headers: { ...DEFAULT_HEADERS },
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
    const rooms = await response.json();
    return rooms.find((r: Room) => r.id === roomId);
  }

  static async getRoomAssets(stationId: number, roomId: number): Promise<RoomAsset[]> {
    const response = await fetch(`${API_BASE}/public/stations/${stationId}/rooms/${roomId}/assets`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch room assets');
    }
    return response.json();
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
      headers: { ...DEFAULT_HEADERS },
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

  static async getInventoryV2(stationId: number, token: string): Promise<InventoryV2Response> {
    const response = await fetch(`${API_BASE}/v2/station-inventory/${stationId}${token}`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch inventory');
    }
    return response.json();
  }

  static async updateInventoryItem(
    stationId: number,
    itemId: number,
    data: UpdateItemRequest,
    token: string
  ): Promise<{ success: boolean; message: string }> {
    const response = await fetch(`${API_BASE}/v2/station-inventory/${stationId}/item/${itemId}${token}`, {
      method: 'PUT',
      headers: { ...DEFAULT_HEADERS },
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to update item');
    }
    return response.json();
  }

  static async getSupplyRequests(stationId: number, token: string): Promise<SupplyRequest[]> {
    const response = await fetch(`${API_BASE}/v2/station-inventory/${stationId}/supply-requests${token}`, {
      headers: { ...DEFAULT_HEADERS },
    });
    if (!response.ok) {
      throw new Error('Failed to fetch supply requests');
    }
    const data = await response.json();
    return data.data || data;
  }

  static async createSupplyRequest(
    stationId: number,
    request: CreateSupplyRequestRequest,
    token: string
  ): Promise<{ success: boolean; message: string }> {
    const response = await fetch(`${API_BASE}/v2/station-inventory/${stationId}/supply-requests${token}`, {
      method: 'POST',
      headers: { ...DEFAULT_HEADERS },
      body: JSON.stringify(request),
    });
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to create supply request');
    }
    return response.json();
  }
}