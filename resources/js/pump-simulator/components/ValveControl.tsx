import React from 'react';

interface ValveControlProps {
  dischargeValveOpen: boolean;
  auxiliaryValveOpen: boolean;
  onDischargeValveChange: (open: boolean) => void;
  onAuxiliaryValveChange: (open: boolean) => void;
  disabled?: boolean;
}

const ValveControl: React.FC<ValveControlProps> = ({
  dischargeValveOpen,
  auxiliaryValveOpen,
  onDischargeValveChange,
  onAuxiliaryValveChange,
  disabled = false,
}) => {
  return (
    <div className="bg-gray-800 rounded-lg p-4 shadow-lg border border-gray-700">
      <h3 className="text-gray-400 text-xs font-bold uppercase tracking-wider mb-3">Valve Controls</h3>
      
      <div className="space-y-3">
        {/* Discharge Valve */}
        <div className="flex items-center justify-between">
          <span className="text-gray-300 text-sm font-medium">Master Discharge</span>
          <button
            onClick={() => onDischargeValveChange(!dischargeValveOpen)}
            disabled={disabled}
            className={`relative w-14 h-8 rounded-full transition-all duration-300 ${
              dischargeValveOpen ? 'bg-emerald-500' : 'bg-gray-600'
            } ${disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
          >
            <div
              className={`absolute top-1 w-6 h-6 bg-white rounded-full shadow-md transition-all duration-300 ${
                dischargeValveOpen ? 'left-7' : 'left-1'
              }`}
            />
          </button>
        </div>

        {/* Discharge Valve Status */}
        <div className="flex items-center gap-2 text-xs">
          <div className={`w-2 h-2 rounded-full ${dischargeValveOpen ? 'bg-emerald-500' : 'bg-gray-500'}`} />
          <span className={dischargeValveOpen ? 'text-emerald-400' : 'text-gray-500'}>
            {dischargeValveOpen ? 'OPEN - Pressurized' : 'CLOSED'}
          </span>
        </div>

        {/* Divider */}
        <div className="border-t border-gray-700 my-2" />

        {/* Auxiliary Valve */}
        <div className="flex items-center justify-between">
          <span className="text-gray-300 text-sm font-medium">Auxiliary</span>
          <button
            onClick={() => onAuxiliaryValveChange(!auxiliaryValveOpen)}
            disabled={disabled}
            className={`relative w-14 h-8 rounded-full transition-all duration-300 ${
              auxiliaryValveOpen ? 'bg-blue-500' : 'bg-gray-600'
            } ${disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
          >
            <div
              className={`absolute top-1 w-6 h-6 bg-white rounded-full shadow-md transition-all duration-300 ${
                auxiliaryValveOpen ? 'left-7' : 'left-1'
              }`}
            />
          </button>
        </div>

        {/* Auxiliary Valve Status */}
        <div className="flex items-center gap-2 text-xs">
          <div className={`w-2 h-2 rounded-full ${auxiliaryValveOpen ? 'bg-blue-500' : 'bg-gray-500'}`} />
          <span className={auxiliaryValveOpen ? 'text-blue-400' : 'text-gray-500'}>
            {auxiliaryValveOpen ? 'OPEN' : 'CLOSED'}
          </span>
        </div>
      </div>
    </div>
  );
};

export default ValveControl;
