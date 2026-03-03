export type ShiftMode = 'road' | 'pump';

export interface PumpState {
  shiftMode: ShiftMode;
  engineRpm: number;
  intakePressure: number;
  masterDischargePressure: number;
  throttlePosition: number;
  dischargeValveOpen: boolean;
  auxiliaryValveOpen: boolean;
}

export interface PumpActions {
  setShiftMode: (mode: ShiftMode) => void;
  setEngineRpm: (rpm: number) => void;
  setIntakePressure: (pressure: number) => void;
  setMasterDischargePressure: (pressure: number) => void;
  setThrottlePosition: (position: number) => void;
  setDischargeValveOpen: (open: boolean) => void;
  setAuxiliaryValveOpen: (open: boolean) => void;
  reset: () => void;
}

export type PumpStore = PumpState & PumpActions;
