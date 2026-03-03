import React, { createContext, useContext, useState, useCallback, useEffect, ReactNode } from 'react';
import type { ShiftMode } from '../types';

const MAX_RPM = 3000;
const PUMP_EFFICIENCY = 0.85;

export interface TransientState {
  dischargePressure: number;
  isCavitating: boolean;
}

interface PumpState {
  shiftMode: ShiftMode;
  engineRpm: number;
  intakePressure: number;
  masterDischargePressure: number;
  throttlePosition: number;
  dischargeValveOpen: boolean;
  auxiliaryValveOpen: boolean;
}

interface PumpActions {
  setShiftMode: (mode: ShiftMode) => void;
  setEngineRpm: (rpm: number) => void;
  setIntakePressure: (pressure: number) => void;
  setThrottlePosition: (position: number) => void;
  setDischargeValveOpen: (open: boolean) => void;
  setAuxiliaryValveOpen: (open: boolean) => void;
  reset: () => void;
}

type PumpStore = PumpState & PumpActions;

const initialState: PumpState = {
  shiftMode: 'road',
  engineRpm: 0,
  intakePressure: 0,
  masterDischargePressure: 0,
  throttlePosition: 0,
  dischargeValveOpen: false,
  auxiliaryValveOpen: false,
};

const calculateDischargePressure = (
  engineRpm: number,
  throttlePosition: number,
  dischargeValveOpen: boolean
): number => {
  if (!dischargeValveOpen) return 0;
  const normalizedRpm = Math.min(engineRpm / MAX_RPM, 1);
  return normalizedRpm * throttlePosition * PUMP_EFFICIENCY * 100;
};

const checkCavitation = (
  intakePressure: number,
  dischargePressure: number,
  throttlePosition: number
): boolean => {
  return intakePressure > -5 && dischargePressure > 200 && throttlePosition > 50;
};

const PumpContext = createContext<PumpStore | null>(null);

export const PumpProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [state, setState] = useState<PumpState>(initialState);

  const calculateTransient = useCallback((): TransientState => {
    const pressure = calculateDischargePressure(
      state.engineRpm,
      state.throttlePosition,
      state.dischargeValveOpen
    );
    const isCavitating = checkCavitation(
      state.intakePressure,
      pressure,
      state.throttlePosition
    );
    return { dischargePressure: pressure, isCavitating };
  }, [state.engineRpm, state.throttlePosition, state.dischargeValveOpen, state.intakePressure]);

  const setShiftMode = useCallback((mode: ShiftMode) => {
    setState(prev => ({ ...prev, shiftMode: mode }));
  }, []);

  const setEngineRpm = useCallback((rpm: number) => {
    const clampedRpm = Math.max(0, Math.min(rpm, MAX_RPM));
    setState(prev => {
      const newPressure = calculateDischargePressure(clampedRpm, prev.throttlePosition, prev.dischargeValveOpen);
      return { ...prev, engineRpm: clampedRpm, masterDischargePressure: newPressure };
    });
  }, []);

  const setIntakePressure = useCallback((pressure: number) => {
    setState(prev => ({ ...prev, intakePressure: pressure }));
  }, []);

  const setThrottlePosition = useCallback((position: number) => {
    const clampedPosition = Math.max(0, Math.min(position, 100));
    setState(prev => {
      const newPressure = calculateDischargePressure(prev.engineRpm, clampedPosition, prev.dischargeValveOpen);
      return { ...prev, throttlePosition: clampedPosition, masterDischargePressure: newPressure };
    });
  }, []);

  const setDischargeValveOpen = useCallback((open: boolean) => {
    setState(prev => {
      const newPressure = calculateDischargePressure(prev.engineRpm, prev.throttlePosition, open);
      return { ...prev, dischargeValveOpen: open, masterDischargePressure: newPressure };
    });
  }, []);

  const setAuxiliaryValveOpen = useCallback((open: boolean) => {
    setState(prev => ({ ...prev, auxiliaryValveOpen: open }));
  }, []);

  const reset = useCallback(() => {
    setState(initialState);
  }, []);

  const store: PumpStore = {
    ...state,
    setShiftMode,
    setEngineRpm,
    setIntakePressure,
    setThrottlePosition,
    setDischargeValveOpen,
    setAuxiliaryValveOpen,
    reset,
  };

  return (
    <PumpContext.Provider value={store}>
      {children}
    </PumpContext.Provider>
  );
};

export const usePumpStore = (): PumpStore => {
  const context = useContext(PumpContext);
  if (!context) {
    throw new Error('usePumpStore must be used within a PumpProvider');
  }
  return context;
};

export const useTransientState = (): TransientState => {
  const { engineRpm, throttlePosition, dischargeValveOpen, intakePressure } = usePumpStore();
  
  const pressure = calculateDischargePressure(engineRpm, throttlePosition, dischargeValveOpen);
  const isCavitating = checkCavitation(intakePressure, pressure, throttlePosition);
  
  return { dischargePressure: pressure, isCavitating };
};
