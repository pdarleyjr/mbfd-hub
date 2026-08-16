import type { Room } from '../types';
import stationOperations from '../data/stationOperations.json';

export const ROOM_AREA_OPTIONS = [
  { value: 'kitchen', label: 'Kitchen', prompt: 'Select a kitchen' },
  { value: 'common_area', label: 'TV room / area', prompt: 'Select a TV room or common area' },
  { value: 'office', label: 'Office', prompt: 'Select an office' },
  { value: 'dormitory', label: 'Dorm', prompt: 'Select a dorm' },
  { value: 'combat_apparatus_bay', label: 'Combat apparatus bay', prompt: 'Select a combat apparatus bay position' },
  { value: 'rescue_apparatus_bay', label: 'Rescue apparatus bay', prompt: 'Select a rescue apparatus bay position' },
  { value: 'support_apparatus_bay', label: 'Support apparatus bay', prompt: 'Select a support apparatus bay position' },
  { value: 'fireboat_apparatus_area', label: 'Fireboat berth / apparatus area', prompt: 'Select a fireboat apparatus area' },
  { value: 'restroom', label: 'Bathroom', prompt: 'Select a bathroom' },
  { value: 'laundry', label: 'Laundry', prompt: 'Select a laundry room' },
  { value: 'fitness', label: 'Fitness / workout room', prompt: 'Select a fitness or workout room' },
  { value: 'storage', label: 'Storage', prompt: 'Select a storage area' },
  { value: 'utility', label: 'Utility / mechanical', prompt: 'Select a utility or mechanical room' },
  { value: 'exterior', label: 'Exterior / grounds', prompt: 'Select an exterior area' },
] as const;

export type RoomArea = typeof ROOM_AREA_OPTIONS[number]['value'];

export interface RoomAreaGroup {
  key: string;
  label: string;
  rooms: Room[];
  dormPositions: number;
}

export function roomArea(room: Room): string {
  return room.type || room.room_type || 'other';
}

export function availableRoomAreas(rooms: Room[]) {
  const present = new Set(rooms.map(roomArea));
  return ROOM_AREA_OPTIONS.filter((option) => present.has(option.value));
}

export function roomAreaLabel(area: string): string {
  return ROOM_AREA_OPTIONS.find((option) => option.value === area)?.label
    ?? area.replaceAll('_', ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

export function roomDetailPrompt(area: string): string {
  return ROOM_AREA_OPTIONS.find((option) => option.value === area)?.prompt ?? 'Select a specific room or area';
}

export function roomsForArea(rooms: Room[], area: string): Room[] {
  return [...rooms]
    .filter((room) => roomArea(room) === area)
    .sort((left, right) => (left.sort_order ?? 1000) - (right.sort_order ?? 1000) || left.name.localeCompare(right.name));
}

export function groupRoomsByArea(rooms: Room[]): RoomAreaGroup[] {
  const orderedAreas = availableRoomAreas(rooms);
  const known: RoomAreaGroup[] = orderedAreas.map((area) => {
    const groupedRooms = roomsForArea(rooms, area.value);
    return {
      key: area.value,
      label: area.label,
      rooms: groupedRooms,
      dormPositions: groupedRooms.reduce((total, room) => total + (room.capacity ?? 0), 0),
    };
  });
  const knownKeys = new Set(orderedAreas.map((area) => area.value));
  const customRooms = rooms.filter((room) => !knownKeys.has(roomArea(room) as RoomArea));

  if (customRooms.length > 0) {
    known.push({ key: 'other', label: 'Other', rooms: customRooms, dormPositions: 0 });
  }

  return known;
}

type OperationsUnit = {
  label: string;
  personnel: number;
};

type OperationsStation = {
  units: OperationsUnit[];
  dorm_beds: number;
};

export function stationComplement(stationNumber: number | string) {
  const definitions = stationOperations.stations as Record<string, OperationsStation>;
  const definition = definitions[String(stationNumber)];

  if (!definition) return null;

  return {
    assignedUnits: definition.units.map((unit) => unit.label),
    assignedApparatusCount: definition.units.length,
    assignedPersonnelCount: definition.units.reduce((total, unit) => total + unit.personnel, 0),
    dormBedsCount: definition.dorm_beds,
  };
}
