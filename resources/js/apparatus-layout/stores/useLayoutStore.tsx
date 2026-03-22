import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type {
  ApparatusSide,
  Compartment,
  Equipment,
  EquipmentPlacement,
  LayoutSnapshot,
  PlacementPosition,
  LayoutStore,
} from '../types';
import { PIERCE_ASCENDANT_COMPARTMENTS } from '../types';

// ===== Initial State =====

const initialState = {
  currentApparatusId: null,
  currentApparatusName: '',
  currentSide: 'driver' as ApparatusSide,
  zoom: 1,
  panOffset: { x: 0, y: 0 },
  compartments: [] as Compartment[],
  equipmentCatalog: [] as Equipment[],
  placements: [] as EquipmentPlacement[],
  selectedCompartmentId: null as string | null,
  selectedPlacementId: null as string | null,
  snapshots: [] as LayoutSnapshot[],
  currentSnapshotId: null as string | null,
  isDragging: false,
  showGrid: true,
  gridSize: 10,
  lastAutoSave: null as string | null,
  hasUnsavedChanges: false,
};

// ===== Helper Functions =====

function generateId(): string {
  return `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
}

// ===== Zustand Store =====

export const useLayoutStore = create<LayoutStore>()(
  persist(
    (set, get) => ({
      ...initialState,

      // Apparatus
      setApparatus: (apparatusId, apparatusName, compartments) => set({
        currentApparatusId: apparatusId,
        currentApparatusName: apparatusName,
        compartments,
        placements: [],
        selectedCompartmentId: null,
        selectedPlacementId: null,
        hasUnsavedChanges: true,
      }),

      // View
      setSide: (side) => set({ currentSide: side }),
      setZoom: (zoom) => set({ zoom: Math.max(0.25, Math.min(zoom, 3)) }),
      setPanOffset: (offset) => set({ panOffset: offset }),

      // Equipment Catalog
      addEquipment: (equipment) => set((state) => ({
        equipmentCatalog: [...state.equipmentCatalog, { ...equipment, id: equipment.id || generateId() }],
      })),
      updateEquipment: (id, updates) => set((state) => ({
        equipmentCatalog: state.equipmentCatalog.map((e) =>
          e.id === id ? { ...e, ...updates } : e
        ),
      })),
      removeEquipment: (id) => set((state) => ({
        equipmentCatalog: state.equipmentCatalog.filter((e) => e.id !== id),
      })),

      // Placements
      placeEquipment: (placement) => {
        const id = generateId();
        set((state) => ({
          placements: [...state.placements, { ...placement, id }],
          hasUnsavedChanges: true,
        }));
        return id;
      },
      updatePlacement: (id, updates) => set((state) => ({
        placements: state.placements.map((p) =>
          p.id === id ? { ...p, ...updates } : p
        ),
        hasUnsavedChanges: true,
      })),
      removePlacement: (id) => set((state) => ({
        placements: state.placements.filter((p) => p.id !== id),
        selectedPlacementId: state.selectedPlacementId === id ? null : state.selectedPlacementId,
        hasUnsavedChanges: true,
      })),
      movePlacement: (id, position) => set((state) => ({
        placements: state.placements.map((p) =>
          p.id === id ? { ...p, position } : p
        ),
        hasUnsavedChanges: true,
      })),

      // Selection
      selectCompartment: (id) => set({ selectedCompartmentId: id }),
      selectPlacement: (id) => set({ selectedPlacementId: id }),

      // Snapshots
      saveSnapshot: (name) => {
        const id = generateId();
        const state = get();
        const snapshot: LayoutSnapshot = {
          id,
          name,
          apparatusId: state.currentApparatusId || 'unknown',
          apparatusName: state.currentApparatusName,
          placements: [...state.placements],
          createdAt: new Date().toISOString(),
          updatedAt: new Date().toISOString(),
        };
        set((state) => ({
          snapshots: [...state.snapshots, snapshot],
          currentSnapshotId: id,
          hasUnsavedChanges: false,
        }));
        return id;
      },
      loadSnapshot: (id) => {
        const state = get();
        const snapshot = state.snapshots.find((s) => s.id === id);
        if (snapshot) {
          set({
            placements: [...snapshot.placements],
            currentSnapshotId: id,
            hasUnsavedChanges: false,
          });
        }
      },
      deleteSnapshot: (id) => set((state) => ({
        snapshots: state.snapshots.filter((s) => s.id !== id),
        currentSnapshotId: state.currentSnapshotId === id ? null : state.currentSnapshotId,
      })),

      // UI
      setDragging: (isDragging) => set({ isDragging }),
      toggleGrid: () => set((state) => ({ showGrid: !state.showGrid })),
      setGridSize: (size) => set({ gridSize: size }),

      // Autosave
      triggerAutoSave: () => {
        const state = get();
        if (state.hasUnsavedChanges && state.placements.length > 0) {
          const autoSaveId = `autosave-${state.currentApparatusId || 'default'}`;
          const existingIndex = state.snapshots.findIndex((s) => s.id === autoSaveId);
          const snapshot: LayoutSnapshot = {
            id: autoSaveId,
            name: 'Auto-saved',
            apparatusId: state.currentApparatusId || 'unknown',
            apparatusName: state.currentApparatusName,
            placements: [...state.placements],
            createdAt: existingIndex >= 0 ? state.snapshots[existingIndex].createdAt : new Date().toISOString(),
            updatedAt: new Date().toISOString(),
            isAutoSave: true,
          };
          set((state) => ({
            snapshots: existingIndex >= 0
              ? state.snapshots.map((s) => s.id === autoSaveId ? snapshot : s)
              : [...state.snapshots, snapshot],
            lastAutoSave: snapshot.updatedAt,
            hasUnsavedChanges: false,
          }));
        }
      },
      markSaved: () => set({ hasUnsavedChanges: false }),

      // Reset
      reset: () => set({
        ...initialState,
        compartments: PIERCE_ASCENDANT_COMPARTMENTS,
        currentApparatusId: 'pierce-ascendant-100',
        currentApparatusName: 'Pierce Ascendant 100\'',
      }),
    }),
    {
      name: 'apparatus-layout-storage',
      partialize: (state) => ({
        currentApparatusId: state.currentApparatusId,
        currentApparatusName: state.currentApparatusName,
        placements: state.placements,
        snapshots: state.snapshots,
        lastAutoSave: state.lastAutoSave,
      }),
    }
  )
);

export function initializeStore() {
  const state = useLayoutStore.getState();
  if (state.compartments.length === 0) {
    useLayoutStore.setState({
      compartments: PIERCE_ASCENDANT_COMPARTMENTS,
    });
  }
  if (!state.currentApparatusId) {
    useLayoutStore.getState().setApparatus(
      'pierce-ascendant-100',
      'Pierce Ascendant 100\'',
      PIERCE_ASCENDANT_COMPARTMENTS
    );
  }
}