import React from 'react';
import { motion } from 'framer-motion';
import type { ShiftMode } from '../types';

interface ShiftModeToggleProps {
  mode: ShiftMode;
  onChange: (mode: ShiftMode) => void;
}

const ShiftModeToggle: React.FC<ShiftModeToggleProps> = ({ mode, onChange }) => {
  return (
    <div className="metal-card" style={{ padding: 16 }}>
      <h3 style={{ color: '#888', fontSize: 10, fontWeight: 700, letterSpacing: 1, textTransform: 'uppercase', marginBottom: 12 }}>
        Transfer Case
      </h3>
      <div style={{ display: 'flex', gap: 8 }}>
        <motion.button
          onClick={() => onChange('road')}
          style={{
            flex: 1, padding: '14px 12px', borderRadius: 8, border: 'none', cursor: 'pointer',
            fontWeight: 700, fontSize: 13, color: mode === 'road' ? '#fff' : '#888',
            background: mode === 'road' ? 'linear-gradient(135deg, #059669, #047857)' : 'linear-gradient(135deg, #1e1e2e, #2a2a3a)',
            boxShadow: mode === 'road' ? '0 4px 12px rgba(5,150,105,0.3)' : 'none',
          }}
          whileTap={{ scale: 0.96 }}
        >
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            ROAD
          </div>
        </motion.button>
        <motion.button
          onClick={() => onChange('pump')}
          style={{
            flex: 1, padding: '14px 12px', borderRadius: 8, border: 'none', cursor: 'pointer',
            fontWeight: 700, fontSize: 13, color: mode === 'pump' ? '#fff' : '#888',
            background: mode === 'pump' ? 'linear-gradient(135deg, #d97706, #b45309)' : 'linear-gradient(135deg, #1e1e2e, #2a2a3a)',
            boxShadow: mode === 'pump' ? '0 4px 12px rgba(217,119,6,0.3)' : 'none',
          }}
          whileTap={{ scale: 0.96 }}
        >
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707" />
            </svg>
            PUMP
          </div>
        </motion.button>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 10 }}>
        <div style={{ width: 8, height: 8, borderRadius: '50%', background: mode === 'road' ? '#22c55e' : '#f59e0b', boxShadow: `0 0 8px ${mode === 'road' ? '#22c55e' : '#f59e0b'}` }} />
        <span style={{ fontSize: 11, color: '#888' }}>{mode === 'road' ? 'Power to wheels' : 'Pump engaged \u2014 water flowing'}</span>
      </div>
    </div>
  );
};

export default ShiftModeToggle;