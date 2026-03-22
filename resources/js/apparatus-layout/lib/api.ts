import type { Equipment, EquipmentPlacement, LayoutSnapshot } from '../types';

const API_BASE = '/api/public/apparatus-layout';
const API_AUTH_BASE = '/api/admin/apparatus-layout';

// ===== Public (unauthenticated) endpoints =====

export async function fetchTools(): Promise<Record<string, Equipment[]>> {
  const res = await fetch(`${API_BASE}/tools`);
  if (!res.ok) throw new Error(`Failed to fetch tools: ${res.status}`);
  const json = await res.json();
  return json.data ?? {};
}

export async function fetchCompartments(apparatusId: number | string) {
  const res = await fetch(`${API_BASE}/compartments/${apparatusId}`);
  if (!res.ok) throw new Error(`Failed to fetch compartments: ${res.status}`);
  const json = await res.json();
  return json.data ?? {};
}

export async function fetchSnapshots(apparatusId: number | string): Promise<LayoutSnapshot[]> {
  const res = await fetch(`${API_BASE}/snapshots/${apparatusId}`);
  if (!res.ok) throw new Error(`Failed to fetch snapshots: ${res.status}`);
  const json = await res.json();
  return json.data ?? [];
}

// ===== Authenticated endpoints =====

export async function saveSnapshot(payload: {
  apparatus_id: number | string;
  name: string;
  placements: EquipmentPlacement[];
  notes?: string;
}): Promise<{ id: string }> {
  const res = await fetch(`${API_AUTH_BASE}/snapshots`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify(payload),
  });
  if (!res.ok) throw new Error(`Failed to save snapshot: ${res.status}`);
  const json = await res.json();
  return json.data;
}

export async function deleteSnapshotApi(snapshotId: string): Promise<void> {
  const res = await fetch(`${API_AUTH_BASE}/snapshots/${snapshotId}`, {
    method: 'DELETE',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  if (!res.ok) throw new Error(`Failed to delete snapshot: ${res.status}`);
}

export async function autoSaveApi(payload: {
  apparatus_id: number | string;
  placements: EquipmentPlacement[];
}): Promise<{ id: string; updatedAt: string }> {
  const res = await fetch(`${API_AUTH_BASE}/autosave`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify(payload),
  });
  if (!res.ok) throw new Error(`Failed to autosave: ${res.status}`);
  const json = await res.json();
  return json.data;
}
