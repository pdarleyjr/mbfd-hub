import { Apparatus, ChecklistData, InspectionData } from '../types';

const API_BASE = '/api';

export interface ApiApparatus {
  id: number;
  unit_id: string;
  make: string;
  model: string;
  year: number;
  status: string;
  mileage: number;
}

export interface InspectionData {
  officer: {
    name: string;
    rank: string;
    shift: string;
    unitNumber: string;
  };
  compartments: any[];
}

export class ApiClient {
  static async getApparatuses(): Promise<ApiApparatus[]> {
    const response = await fetch(`${API_BASE}/public/apparatuses`);
    if (!response.ok) {
      throw new Error('Failed to fetch apparatuses');
    }
    return response.json();
  }

  static async getChecklist(apparatusId: number): Promise<any> {
    const response = await fetch(`${API_BASE}/public/apparatuses/${apparatusId}/checklist`);
    if (!response.ok) {
      throw new Error('Failed to fetch checklist');
    }
    return response.json();
  }

  static async submitInspection(apparatusId: number, data: InspectionData): Promise<any> {
    const response = await fetch(`${API_BASE}/public/apparatuses/${apparatusId}/inspections`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        operator_name: data.officer.name,
        rank: data.officer.rank,
        shift: data.officer.shift,
        unit_number: data.officer.unitNumber,
        defects: [],
      }),
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to submit inspection');
    }

    return response.json();
  }
}