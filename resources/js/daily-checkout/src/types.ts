export type ApparatusType = 'engine' | 'ladder1' | 'ladder3' | 'rescue' | 'rope';

export interface Apparatus {
  id: number;
  name: string;
  type: ApparatusType;
  vehicle_number: string;
  slug: string;
}

export type Rank = 'Chief' | 'Deputy Chief' | 'Captain' | 'Lieutenant' | 'Sergeant' | 'Corporal' | 'Firefighter';

export type Shift = 'A' | 'B' | 'C';

export interface OfficerInfo {
  name: string;
  rank: Rank;
  shift: Shift;
  unitNumber: string;
}

export type ItemStatus = 'Present' | 'Missing' | 'Damaged';

export interface ChecklistItem {
  id: string;
  name: string;
  status: ItemStatus;
  notes?: string;
}

export interface Compartment {
  id: string;
  name: string;
  items: ChecklistItem[];
}

export interface ChecklistData {
  compartments: Compartment[];
}

export interface InspectionData {
  officer: OfficerInfo;
  compartments: Compartment[];
}