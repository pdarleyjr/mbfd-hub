import { Apparatus, ChecklistData, InspectionSubmission, Station, StationDetail, Room, RoomAsset, RoomAudit } from '../types';

const API_BASE = '/api';

export class ApiClient {
  static async getApparatuses(): Promise<Apparatus[]> {
    const response = await fetch(`${API_BASE}/public/apparatuses`);
    if (!response.ok) {
      throw new Error('Failed to fetch apparatuses');
    }
    return response.json();
  }

  static async getChecklist(apparatusId: number): Promise<ChecklistData> {
    const response = await fetch(`${API_BASE}/public/apparatuses/${apparatusId}/checklist`);
    if (!response.ok) {
      throw new Error('Failed to fetch checklist');
    }
    return response.json();
  }

  static async submitInspection(apparatusId: number, data: InspectionSubmission): Promise<{ success: boolean; message: string }> {
    const response = await fetch(`${API_BASE}/public/apparatuses/${apparatusId}/inspections`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
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
    const response = await fetch(`${API_BASE}/admin/stations`);
    if (!response.ok) {
      throw new Error('Failed to fetch stations');
    }
    return response.json();
  }

  static async getStation(id: number): Promise<StationDetail> {
    const response = await fetch(`${API_BASE}/admin/stations/${id}`);
    if (!response.ok) {
      throw new Error('Failed to fetch station');
    }
    return response.json();
  }

  static async createStation(data: Partial<Station>): Promise<Station> {
    const response = await fetch(`${API_BASE}/admin/stations`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
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
      headers: {
        'Content-Type': 'application/json',
      },
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
    });
    if (!response.ok) {
      throw new Error('Failed to delete station');
    }
  }

  // Alias methods for simpler usage in components
  static async getRoom(stationId: number, roomId: number): Promise<Room> {
    const response = await fetch(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}`);
    if (!response.ok) {
      throw new Error('Failed to fetch room');
    }
    return response.json();
  }

  static async getRoomAssets(stationId: number, roomId: number): Promise<RoomAsset[]> {
    const response = await fetch(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/assets`);
    if (!response.ok) {
      throw new Error('Failed to fetch room assets');
    }
    return response.json();
  }

  static async getRoomAudits(stationId: number, roomId: number): Promise<RoomAudit[]> {
    const response = await fetch(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/audits`);
    if (!response.ok) {
      throw new Error('Failed to fetch room audits');
    }
    return response.json();
  }

  static async createRoomAsset(stationId: number, roomId: number, data: Partial<RoomAsset>): Promise<RoomAsset> {
    const response = await fetch(`${API_BASE}/admin/stations/${stationId}/rooms/${roomId}/assets`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
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
      headers: {
        'Content-Type': 'application/json',
      },
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
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      throw new Error('Failed to complete room audit');
    }
    return response.json();
  }
}