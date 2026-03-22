export type ShiftMode = 'road' | 'pump';

export interface NozzleProfile {
  name: string;
  gpm: number;
  tipSize: number;
  nozzlePressure: number;
}

export interface HoseLineConfig {
  id: string;
  label: string;
  diameter: number;
  length: number;
  nozzle: NozzleProfile;
  isOpen: boolean;
  flowRate: number;
  frictionLoss: number;
}

export interface PumpState {
  shiftMode: ShiftMode;
  engineRpm: number;
  intakePressure: number;
  masterDischargePressure: number;
  throttlePosition: number;
  isCavitating: boolean;
  tankToPump: boolean;
  fiveInchIntake: boolean;
  threeInchPonySuction: boolean;
  crosslay1: HoseLineConfig;
  crosslay2: HoseLineConfig;
  deckGun: HoseLineConfig;
  boosterLine: HoseLineConfig;
  discharge1: HoseLineConfig;
  discharge2: HoseLineConfig;
  totalFlowGPM: number;
  pumpCapacityPercent: number;
  // Index signature for dynamic line access
  [key: string]: any;
}

export interface PumpActions {
  setShiftMode: (mode: ShiftMode) => void;
  setEngineRpm: (rpm: number) => void;
  setIntakePressure: (pressure: number) => void;
  setThrottlePosition: (position: number) => void;
  toggleTankToPump: () => void;
  toggleFiveInchIntake: () => void;
  toggleThreeInchPonySuction: () => void;
  toggleLine: (lineId: string) => void;
  setLineLength: (lineId: string, length: number) => void;
  setLineNozzle: (lineId: string, nozzle: NozzleProfile) => void;
  reset: () => void;
}

export type PumpStore = PumpState & PumpActions;
