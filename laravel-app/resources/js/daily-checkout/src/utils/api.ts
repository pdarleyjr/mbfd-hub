import { Apparatus, ChecklistData, InspectionSubmission } from '../types';

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
}