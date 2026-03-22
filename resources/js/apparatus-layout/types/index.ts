// ===== Apparatus Layout Planner Types =====

// View sides for the apparatus
export type ApparatusSide = 'driver' | 'officer' | 'rear' | 'top' | 'cab';

// Equipment fit classification
export type FitClassification = 'fit' | 'tight' | 'fail' | 'unknown';

// Shelf types within compartments
export type ShelfType = 'fixed' | 'pull-out' | 'assisted' | 'split';

// ===== Compartment Types =====

export interface CompartmentDimensions {
  width: number;  // inches
  height: number; // inches
  depth: number;  // inches (usable)
}

export interface Compartment {
  id: string;
  label: string;           // e.g., "DS-1", "OS-2"
  side: ApparatusSide;
  dimensions: CompartmentDimensions;
  shelfType: ShelfType;
  shelfCount: number;
  hasPegboard: boolean;
  pegboardFaces?: ('front' | 'rear')[];
  notes?: string;
}

// ===== Equipment Types =====

export interface EquipmentDimensions {
  length: number;  // inches
  width: number;   // inches
  height: number;  // inches
  weight?: number;  // lbs
}

export interface Equipment {
  id: string;
  name: string;
  category: string;
  dimensions: EquipmentDimensions;
  canRotate: boolean;
  requiresClearance: boolean;
  clearanceDepth?: number;  // pull-out or swing clearance needed
  iconPath?: string;        // transparent PNG asset path
  color?: string;
  notes?: string;
}

// ===== Placement Types =====

export interface PlacementPosition {
  x: number;  // pixels on canvas
  y: number;  // pixels on canvas
  rotation: number;  // degrees (0, 90, 180, 270)
}

export interface EquipmentPlacement {
  id: string;
  equipmentId: string;
  compartmentId: string;
  shelfIndex: number;      // which shelf (0-indexed)
  position: PlacementPosition;
  fitClassification: FitClassification;
  notes?: string;
}

// ===== Snapshot Types =====

export interface LayoutSnapshot {
  id: string;
  name: string;
  apparatusId: string;
  apparatusName: string;
  placements: EquipmentPlacement[];
  createdAt: string;
  updatedAt: string;
  isAutoSave?: boolean;
}

// ===== Store Types =====

export interface LayoutState {
  // Current apparatus
  currentApparatusId: string | null;
  currentApparatusName: string;
  
  // View state
  currentSide: ApparatusSide;
  zoom: number;
  panOffset: { x: number; y: number };
  
  // Compartments for current apparatus
  compartments: Compartment[];
  
  // Equipment catalog
  equipmentCatalog: Equipment[];
  
  // Current placements
  placements: EquipmentPlacement[];
  
  // Selected items
  selectedCompartmentId: string | null;
  selectedPlacementId: string | null;
  
  // Snapshots
  snapshots: LayoutSnapshot[];
  currentSnapshotId: string | null;
  
  // UI state
  isDragging: boolean;
  showGrid: boolean;
  gridSize: number;  // pixels
  
  // Autosave
  lastAutoSave: string | null;
  hasUnsavedChanges: boolean;
}

export interface LayoutActions {
  // Apparatus
  setApparatus: (apparatusId: string, apparatusName: string, compartments: Compartment[]) => void;
  
  // View
  setSide: (side: ApparatusSide) => void;
  setZoom: (zoom: number) => void;
  setPanOffset: (offset: { x: number; y: number }) => void;
  
  // Equipment
  addEquipment: (equipment: Equipment) => void;
  updateEquipment: (id: string, updates: Partial<Equipment>) => void;
  removeEquipment: (id: string) => void;
  
  // Placements
  placeEquipment: (placement: Omit<EquipmentPlacement, 'id'>) => string;
  updatePlacement: (id: string, updates: Partial<EquipmentPlacement>) => void;
  removePlacement: (id: string) => void;
  movePlacement: (id: string, position: PlacementPosition) => void;
  
  // Selection
  selectCompartment: (id: string | null) => void;
  selectPlacement: (id: string | null) => void;
  
  // Snapshots
  saveSnapshot: (name: string) => string;
  loadSnapshot: (id: string) => void;
  deleteSnapshot: (id: string) => void;
  
  // UI
  setDragging: (isDragging: boolean) => void;
  toggleGrid: () => void;
  setGridSize: (size: number) => void;
  
  // Autosave
  triggerAutoSave: () => void;
  markSaved: () => void;
  
  // Reset
  reset: () => void;
}

export type LayoutStore = LayoutState & LayoutActions;

// ===== Pierce Ascendant 100' Compartment Data =====

export const PIERCE_ASCENDANT_COMPARTMENTS: Compartment[] = [
  // Driver Side
  {
    id: 'DS-1',
    label: 'DS-1',
    side: 'driver',
    dimensions: { width: 29.13, height: 28.25, depth: 25.13 },
    shelfType: 'pull-out',
    shelfCount: 1,
    hasPegboard: false,
    notes: 'Pull-out tray',
  },
  {
    id: 'DS-2',
    label: 'DS-2',
    side: 'driver',
    dimensions: { width: 84, height: 22.13, depth: 25.13 },
    shelfType: 'split',
    shelfCount: 2,
    hasPegboard: true,
    pegboardFaces: ['front', 'rear'],
    notes: 'Split shelf + pegboard both faces',
  },
  {
    id: 'DS-3',
    label: 'DS-3',
    side: 'driver',
    dimensions: { width: 41.25, height: 53.88, depth: 25.13 },
    shelfType: 'fixed',
    shelfCount: 4,
    hasPegboard: false,
    notes: '4 fixed shelves',
  },
  {
    id: 'DS-4',
    label: 'DS-4',
    side: 'driver',
    dimensions: { width: 18.13, height: 45.75, depth: 25.13 },
    shelfType: 'fixed',
    shelfCount: 2,
    hasPegboard: false,
    notes: '2 fixed shelves',
  },
  // Officer Side
  {
    id: 'OS-1',
    label: 'OS-1',
    side: 'officer',
    dimensions: { width: 18.38, height: 35.25, depth: 7.91 },
    shelfType: 'fixed',
    shelfCount: 1,
    hasPegboard: false,
    notes: 'Shallow compartment',
  },
  {
    id: 'OS-2',
    label: 'OS-2',
    side: 'officer',
    dimensions: { width: 29.13, height: 28.25, depth: 25.13 },
    shelfType: 'pull-out',
    shelfCount: 1,
    hasPegboard: false,
    notes: 'Pull-out tray',
  },
  {
    id: 'OS-3',
    label: 'OS-3',
    side: 'officer',
    dimensions: { width: 16.25, height: 20.06, depth: 25.13 },
    shelfType: 'fixed',
    shelfCount: 0,
    hasPegboard: false,
    notes: 'Open compartment',
  },
  {
    id: 'OS-4',
    label: 'OS-4',
    side: 'officer',
    dimensions: { width: 84, height: 22.13, depth: 25.13 },
    shelfType: 'pull-out',
    shelfCount: 2,
    hasPegboard: false,
    notes: '2 pull-out trays',
  },
  {
    id: 'OS-5',
    label: 'OS-5',
    side: 'officer',
    dimensions: { width: 41.25, height: 53.88, depth: 25.13 },
    shelfType: 'fixed',
    shelfCount: 4,
    hasPegboard: false,
    notes: '4 fixed shelves',
  },
  {
    id: 'OS-6',
    label: 'OS-6',
    side: 'officer',
    dimensions: { width: 18.13, height: 45.75, depth: 25.13 },
    shelfType: 'fixed',
    shelfCount: 2,
    hasPegboard: false,
    notes: '2 fixed shelves',
  },
  // Rear
  {
    id: 'R-1',
    label: 'R-1',
    side: 'rear',
    dimensions: { width: 37.25, height: 45.75, depth: 25.13 },
    shelfType: 'fixed',
    shelfCount: 2,
    hasPegboard: false,
    notes: '2 fixed shelves',
  },
];

// Default apparatus
export const DEFAULT_APPARATUS = {
  id: 'pierce-ascendant-100',
  name: 'Pierce Ascendant 100\'',
  compartments: PIERCE_ASCENDANT_COMPARTMENTS,
};