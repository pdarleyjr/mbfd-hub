import { motion } from 'framer-motion';
import React, { useEffect } from 'react';
import Gauge from './Gauge';
import ShiftModeToggle from './ShiftModeToggle';
import ValveControl from './ValveControl';
import { usePumpStore, useTransientState } from '../stores/usePumpStore';

const PumpPanel: React.FC = () => {
  const {
    shiftMode,
    engineRpm,
    intakePressure,
    masterDischargePressure,
    throttlePosition,
    dischargeValveOpen,
    auxiliaryValveOpen,
    setShiftMode,
    setEngineRpm,
    setIntakePressure,
    setThrottlePosition,
    setDischargeValveOpen,
    setAuxiliaryValveOpen,
    reset,
  } = usePumpStore();

  const { isCavitating } = useTransientState();

  // Calculate transient state for cavitation detection
  const cavitationDetected = 
    intakePressure > -5 && 
    masterDischargePressure > 200 && 
    throttlePosition > 50;

  // Throttle slider change handler
  const handleThrottleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setThrottlePosition(Number(e.target.value));
  };

  // RPM slider change handler  
  const handleRpmChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setEngineRpm(Number(e.target.value));
  };

  // Intake pressure slider change handler
  const handleIntakeChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setIntakePressure(Number(e.target.value));
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 p-4 lg:p-8">
      {/* Header */}
      <div className="max-w-7xl mx-auto mb-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl lg:text-3xl font-bold text-white">Pump Simulator</h1>
            <p className="text-gray-400 text-sm">Fire Pump Operations Training</p>
          </div>
          <button
            onClick={reset}
            className="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg text-sm font-medium transition-colors"
          >
            Reset Panel
          </button>
        </div>
      </div>

      {/* Main Content */}
      <div className="max-w-7xl mx-auto">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          
          {/* Left Column - Gauges */}
          <div className="lg:col-span-2">
            <motion.div
              animate={cavitationDetected ? {
                x: [0, -2, 2, -2, 2, 0],
                y: [0, 2, -2, 2, -2, 0],
              } : {}}
              transition={cavitationDetected ? {
                duration: 0.15,
                repeat: Infinity,
                repeatDelay: 0.1,
              } : {}}
            >
              <div className="bg-gray-800 rounded-xl p-6 shadow-xl border border-gray-700">
                <h2 className="text-gray-400 text-xs font-bold uppercase tracking-wider mb-6">Pressure Gauges</h2>
                
                <div className="flex flex-wrap justify-center gap-8">
                  {/* Intake Pressure Gauge */}
                  <Gauge
                    value={intakePressure}
                    min={-30}
                    max={30}
                    label="Intake"
                    unit="PSI"
                    warningThreshold={-5}
                    dangerThreshold={-10}
                  />

                  {/* Master Discharge Pressure Gauge */}
                  <Gauge
                    value={masterDischargePressure}
                    min={0}
                    max={400}
                    label="Master Discharge"
                    unit="PSI"
                    warningThreshold={250}
                    dangerThreshold={350}
                    isCavitating={cavitationDetected}
                  />
                </div>

                {/* Cavitation Warning */}
                {cavitationDetected && (
                  <motion.div
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="mt-4 p-3 bg-red-900/50 border border-red-500 rounded-lg flex items-center gap-3"
                  >
                    <motion.div
                      animate={{ scale: [1, 1.2, 1] }}
                      transition={{ duration: 0.5, repeat: Infinity }}
                      className="w-3 h-3 bg-red-500 rounded-full"
                    />
                    <span className="text-red-200 text-sm font-medium">
                      CAVITATION WARNING - Low intake pressure with high discharge pressure
                    </span>
                  </motion.div>
                )}
              </div>
            </motion.div>

            {/* Engine Controls */}
            <div className="mt-6 bg-gray-800 rounded-xl p-6 shadow-xl border border-gray-700">
              <h2 className="text-gray-400 text-xs font-bold uppercase tracking-wider mb-4">Engine Controls</h2>
              
              {/* Throttle */}
              <div className="mb-6">
                <div className="flex justify-between mb-2">
                  <label className="text-gray-300 text-sm font-medium">Throttle</label>
                  <span className="text-amber-400 font-bold">{throttlePosition}%</span>
                </div>
                <input
                  type="range"
                  min="0"
                  max="100"
                  value={throttlePosition}
                  onChange={handleThrottleChange}
                  className="w-full h-3 bg-gray-700 rounded-lg appearance-none cursor-pointer accent-amber-500"
                />
              </div>

              {/* RPM */}
              <div>
                <div className="flex justify-between mb-2">
                  <label className="text-gray-300 text-sm font-medium">Engine RPM</label>
                  <span className="text-emerald-400 font-bold">{engineRpm} RPM</span>
                </div>
                <input
                  type="range"
                  min="0"
                  max="3000"
                  value={engineRpm}
                  onChange={handleRpmChange}
                  className="w-full h-3 bg-gray-700 rounded-lg appearance-none cursor-pointer accent-emerald-500"
                />
              </div>
            </div>

            {/* Intake Pressure Control */}
            <div className="mt-6 bg-gray-800 rounded-xl p-6 shadow-xl border border-gray-700">
              <h2 className="text-gray-400 text-xs font-bold uppercase tracking-wider mb-4">Intake Conditions</h2>
              
              <div>
                <div className="flex justify-between mb-2">
                  <label className="text-gray-300 text-sm font-medium">Intake Vacuum/Pressure</label>
                  <span className={intakePressure < -5 ? 'text-red-400 font-bold' : 'text-blue-400 font-bold'}>
                    {intakePressure} PSI
                  </span>
                </div>
                <input
                  type="range"
                  min="-30"
                  max="10"
                  value={intakePressure}
                  onChange={handleIntakeChange}
                  className="w-full h-3 bg-gray-700 rounded-lg appearance-none cursor-pointer accent-blue-500"
                />
                <p className="text-gray-500 text-xs mt-2">
                  Negative values represent vacuum (sucking water). Keep above -5 PSI to avoid cavitation.
                </p>
              </div>
            </div>
          </div>

          {/* Right Column - Controls */}
          <div className="space-y-6">
            {/* Shift Mode Toggle */}
            <ShiftModeToggle
              mode={shiftMode}
              onChange={setShiftMode}
            />

            {/* Valve Controls */}
            <ValveControl
              dischargeValveOpen={dischargeValveOpen}
              auxiliaryValveOpen={auxiliaryValveOpen}
              onDischargeValveChange={setDischargeValveOpen}
              onAuxiliaryValveChange={setAuxiliaryValveOpen}
            />

            {/* Status Panel */}
            <div className="bg-gray-800 rounded-lg p-4 shadow-lg border border-gray-700">
              <h3 className="text-gray-400 text-xs font-bold uppercase tracking-wider mb-3">System Status</h3>
              
              <div className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-gray-500">Mode</span>
                  <span className={shiftMode === 'pump' ? 'text-amber-400 font-medium' : 'text-emerald-400 font-medium'}>
                    {shiftMode.toUpperCase()}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Discharge</span>
                  <span className={dischargeValveOpen ? 'text-emerald-400' : 'text-gray-500'}>
                    {dischargeValveOpen ? 'PRESSURIZED' : 'CLOSED'}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Auxiliary</span>
                  <span className={auxiliaryValveOpen ? 'text-blue-400' : 'text-gray-500'}>
                    {auxiliaryValveOpen ? 'OPEN' : 'CLOSED'}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Cavitation Risk</span>
                  <span className={cavitationDetected ? 'text-red-400 font-medium' : 'text-emerald-400'}>
                    {cavitationDetected ? 'HIGH' : 'NORMAL'}
                  </span>
                </div>
              </div>
            </div>

            {/* Instructions */}
            <div className="bg-gray-800/50 rounded-lg p-4 border border-gray-700/50">
              <h3 className="text-gray-400 text-xs font-bold uppercase tracking-wider mb-2">How to Use</h3>
              <ol className="text-gray-500 text-xs space-y-1 list-decimal list-inside">
                <li>Switch to PUMP mode to engage pump</li>
                <li>Open Master Discharge valve</li>
                <li>Increase RPM and Throttle</li>
                <li>Monitor discharge pressure</li>
                <li>Avoid cavitation (low intake vacuum)</li>
              </ol>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default PumpPanel;
