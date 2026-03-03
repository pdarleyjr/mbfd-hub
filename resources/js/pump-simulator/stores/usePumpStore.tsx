import { create } from 'zustand';
import type { ShiftMode, NozzleProfile, HoseLineConfig, PumpStore } from '../types';

// ===== Constants =====
const MAX_RPM = 3000;
const PUMP_EFFICIENCY = 0.85;
const RATED_CAPACITY_GPM = 1500; // Typical Pierce pumper

// ===== Nozzle Profiles =====
export const NOZZLE_PROFILES: Record<string, NozzleProfile> = {
  'smooth-bore-15/16': { name: 'Smooth Bore 15/16"', gpm: 185, tipSize: 0.9375, nozzlePressure: 50 },
  'smooth-bore-1': { name: 'Smooth Bore 1"', gpm: 210, tipSize: 1.0, nozzlePressure: 50 },
  'smooth-bore-1-1/8': { name: 'Smooth Bore 1\u215b"', gpm: 265, tipSize: 1.125, nozzlePressure: 50 },
  'smooth-bore-1-1/4': { name: 'Smooth Bore 1\u00bc"', gpm: 325, tipSize: 1.25, nozzlePressure: 50 },
  'fog-100': { name: 'Fog Nozzle 100 GPM', gpm: 100, tipSize: 0, nozzlePressure: 100 },
  'fog-150': { name: 'Fog Nozzle 150 GPM', gpm: 150, tipSize: 0, nozzlePressure: 100 },
  'fog-200': { name: 'Fog Nozzle 200 GPM', gpm: 200, tipSize: 0, nozzlePressure: 100 },
  'fog-250': { name: 'Fog Nozzle 250 GPM', gpm: 250, tipSize: 0, nozzlePressure: 100 },
  'master-stream': { name: 'Master Stream 500 GPM', gpm: 500, tipSize: 1.75, nozzlePressure: 80 },
  'booster-60': { name: 'Booster 60 GPM', gpm: 60, tipSize: 0, nozzlePressure: 100 },
};

// ===== Friction Loss Coefficients (per 100ft) =====
const FRICTION_COEFFICIENTS: Record<number, number> = {
  1.0: 150,
  1.75: 15.5,
  2.5: 2.0,
  3.0: 0.8,
  5.0: 0.08,
};

export function calculateFrictionLoss(gpm: number, diameter: number, lengthFt: number): number {
  const coeff = FRICTION_COEFFICIENTS[diameter] ?? 2.0;
  return coeff * Math.pow(gpm / 100, 2) * (lengthFt / 100);
}

export function calculateLinePressure(line: HoseLineConfig): number {
  if (!line.isOpen) return 0;
  const fl = calculateFrictionLoss(line.nozzle.gpm, line.diameter, line.length);
  return line.nozzle.nozzlePressure + fl;
}

function makeLine(id: string, label: string, diameter: number, length: number, nozzleKey: string): HoseLineConfig {
  const nozzle = NOZZLE_PROFILES[nozzleKey];
  return { id, label, diameter, length, nozzle, isOpen: false, flowRate: 0, frictionLoss: 0 };
}

const defaultLines = () => ({
  crosslay1: makeLine('crosslay1', 'Crosslay 1', 1.75, 200, 'fog-150'),
  crosslay2: makeLine('crosslay2', 'Crosslay 2', 1.75, 200, 'fog-150'),
  deckGun: makeLine('deckGun', 'Deck Gun', 2.5, 50, 'master-stream'),
  boosterLine: makeLine('boosterLine', 'Booster Line', 1.0, 200, 'booster-60'),
  discharge1: makeLine('discharge1', 'Discharge 1 (2\u00bd")', 2.5, 300, 'fog-250'),
  discharge2: makeLine('discharge2', 'Discharge 2 (2\u00bd")', 2.5, 300, 'smooth-bore-1-1/8'),
});

function recalc(state: any) {
  const lines = ['crosslay1', 'crosslay2', 'deckGun', 'boosterLine', 'discharge1', 'discharge2'] as const;
  let totalFlow = 0;
  const updates: Record<string, any> = {};

  for (const key of lines) {
    const line = state[key] as HoseLineConfig;
    if (line.isOpen && state.shiftMode === 'pump') {
      const fl = calculateFrictionLoss(line.nozzle.gpm, line.diameter, line.length);
      updates[key] = { ...line, flowRate: line.nozzle.gpm, frictionLoss: fl };
      totalFlow += line.nozzle.gpm;
    } else {
      updates[key] = { ...line, flowRate: 0, frictionLoss: 0 };
    }
  }

  const normalizedRpm = Math.min(state.engineRpm / MAX_RPM, 1);
  const throttleFactor = state.throttlePosition / 100;
  const pumpOutput = normalizedRpm * throttleFactor * PUMP_EFFICIENCY;

  let maxLinePressure = 0;
  for (const key of lines) {
    const line = updates[key] as HoseLineConfig;
    if (line.flowRate > 0) {
      const needed = line.nozzle.nozzlePressure + line.frictionLoss;
      maxLinePressure = Math.max(maxLinePressure, needed);
    }
  }

  const mdp = state.shiftMode === 'pump' ? pumpOutput * Math.max(maxLinePressure, 50) * (totalFlow > 0 ? 1 : 0.5) : 0;
  const clampedMdp = Math.min(mdp, 400);

  const isCavitating = state.shiftMode === 'pump' &&
    state.intakePressure < 0 &&
    clampedMdp > 150 &&
    throttleFactor > 0.4;

  const pumpCapacityPercent = Math.min((totalFlow / RATED_CAPACITY_GPM) * 100, 100);

  return {
    ...updates,
    masterDischargePressure: Math.round(clampedMdp * 10) / 10,
    totalFlowGPM: totalFlow,
    pumpCapacityPercent: Math.round(pumpCapacityPercent),
    isCavitating,
  };
}

export const usePumpStore = create<PumpStore>((set, get) => ({
  shiftMode: 'road' as ShiftMode,
  engineRpm: 0,
  intakePressure: 0,
  masterDischargePressure: 0,
  throttlePosition: 0,
  isCavitating: false,
  tankToPump: false,
  fiveInchIntake: false,
  threeInchPonySuction: false,
  ...defaultLines(),
  totalFlowGPM: 0,
  pumpCapacityPercent: 0,

  setShiftMode: (mode) => set((s) => ({ shiftMode: mode, ...recalc({ ...s, shiftMode: mode }) })),
  setEngineRpm: (rpm) => set((s) => {
    const clamped = Math.max(0, Math.min(rpm, MAX_RPM));
    return { engineRpm: clamped, ...recalc({ ...s, engineRpm: clamped }) };
  }),
  setIntakePressure: (pressure) => set((s) => ({ intakePressure: pressure, ...recalc({ ...s, intakePressure: pressure }) })),
  setThrottlePosition: (position) => set((s) => {
    const clamped = Math.max(0, Math.min(position, 100));
    return { throttlePosition: clamped, ...recalc({ ...s, throttlePosition: clamped }) };
  }),
  toggleTankToPump: () => set((s) => ({ tankToPump: !s.tankToPump })),
  toggleFiveInchIntake: () => set((s) => ({ fiveInchIntake: !s.fiveInchIntake })),
  toggleThreeInchPonySuction: () => set((s) => ({ threeInchPonySuction: !s.threeInchPonySuction })),
  toggleLine: (lineId) => set((s) => {
    const line = s[lineId as keyof PumpStore] as HoseLineConfig;
    if (!line || typeof line !== 'object' || !('isOpen' in line)) return {};
    const next = { ...s, [lineId]: { ...line, isOpen: !line.isOpen } };
    return { [lineId]: { ...line, isOpen: !line.isOpen }, ...recalc(next) };
  }),
  setLineLength: (lineId, length) => set((s) => {
    const line = s[lineId as keyof PumpStore] as HoseLineConfig;
    if (!line || typeof line !== 'object' || !('length' in line)) return {};
    const next = { ...s, [lineId]: { ...line, length } };
    return { [lineId]: { ...line, length }, ...recalc(next) };
  }),
  setLineNozzle: (lineId, nozzle) => set((s) => {
    const line = s[lineId as keyof PumpStore] as HoseLineConfig;
    if (!line || typeof line !== 'object' || !('nozzle' in line)) return {};
    const next = { ...s, [lineId]: { ...line, nozzle } };
    return { [lineId]: { ...line, nozzle }, ...recalc(next) };
  }),
  reset: () => set({
    shiftMode: 'road' as ShiftMode,
    engineRpm: 0, intakePressure: 0, masterDischargePressure: 0, throttlePosition: 0,
    isCavitating: false, tankToPump: false, fiveInchIntake: false, threeInchPonySuction: false,
    ...defaultLines(), totalFlowGPM: 0, pumpCapacityPercent: 0,
  }),
}));