import React from 'react';
import type { ShiftMode } from '../types';

interface ShiftModeToggleProps {
  mode: ShiftMode;
  onChange: (mode: ShiftMode) => void;
  disabled?: boolean;
}

const ShiftModeToggle: React.FC<ShiftModeToggleProps> = ({ mode, onChange, disabled = false }) => {
  return (
    <div className="bg-gray-800 rounded-lg p-4 shadow-lg border border-gray-700">
      <h3 className="text-gray-400 text-xs font-bold uppercase tracking-wider mb-3">Transfer Case</h3>
      
      <div className="flex gap-2">
        {/* Road Mode Button */}
        <button
          onClick={() => onChange('road')}
          disabled={disabled}
          className={`flex-1 py-3 px-4 rounded-lg font-bold text-sm transition-all duration-200 ${
            mode === 'road'
              ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-600/30'
              : 'bg-gray-700 text-gray-400 hover:bg-gray-600'
          } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
        >
          <div className="flex flex-col items-center gap-1">
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            <span>ROAD</span>
          </div>
        </button>

        {/* Pump Mode Button */}
        <button
          onClick={() => onChange('pump')}
          disabled={disabled}
          className={`flex-1 py-3 px-4 rounded-lg font-bold text-sm transition-all duration-200 ${
            mode === 'pump'
              ? 'bg-amber-600 text-white shadow-lg shadow-amber-600/30'
              : 'bg-gray-700 text-gray-400 hover:bg-gray-600'
          } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
        >
          <div className="flex flex-col items-center gap-1">
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
            </svg>
            <span>PUMP</span>
          </div>
        </button>
      </div>

      {/* Status indicator */}
      <div className="mt-3 flex items-center justify-center gap-2">
        <div className={`w-2 h-2 rounded-full ${mode === 'road' ? 'bg-emerald-500' : 'bg-amber-500'}`} />
        <span className="text-xs text-gray-400">
          {mode === 'road' ? 'Power to wheels' : 'Pump engaged'}
        </span>
      </div>
    </div>
  );
};

export default ShiftModeToggle;
