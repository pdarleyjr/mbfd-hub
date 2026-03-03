import React from 'react';
import { motion } from 'framer-motion';
import { usePumpStore, NOZZLE_PROFILES } from '../stores/usePumpStore';
import type { HoseLineConfig, NozzleProfile } from '../types';

const LINE_IDS = ['crosslay1', 'crosslay2', 'deckGun', 'boosterLine', 'discharge1', 'discharge2'] as const;

const ValveToggle: React.FC<{ label: string; isOpen: boolean; onToggle: () => void; color: string }> = ({
  label, isOpen, onToggle, color,
}) => (
  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '6px 0' }}>
    <span style={{ color: '#ccc', fontSize: 12, fontWeight: 600, letterSpacing: 0.5 }}>{label}</span>
    <button
      onClick={onToggle}
      style={{
        position: 'relative', width: 48, height: 26, borderRadius: 13,
        border: 'none', cursor: 'pointer',
        background: isOpen ? color : '#333', transition: 'background 0.3s',
      }}
    >
      <motion.div
        style={{
          position: 'absolute', top: 3, width: 20, height: 20, borderRadius: '50%',
          background: 'radial-gradient(circle at 35% 35%, #fff, #ccc)',
          boxShadow: '0 2px 4px rgba(0,0,0,0.3)',
        }}
        animate={{ left: isOpen ? 25 : 3 }}
        transition={{ type: 'spring', stiffness: 300, damping: 25 }}
      />
    </button>
  </div>
);

const LineDetail: React.FC<{ lineId: string; line: HoseLineConfig }> = ({ lineId, line }) => {
  const { setLineLength, setLineNozzle } = usePumpStore();
  return (
    <div style={{ background: 'rgba(255,255,255,0.03)', borderRadius: 6, padding: '6px 8px', margin: '4px 0' }}>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
        <label style={{ color: '#888', fontSize: 10, fontWeight: 600 }}>
          {line.diameter}" \u00d7{' '}
          <input type="number" value={line.length}
            onChange={(e) => setLineLength(lineId, Number(e.target.value))}
            style={{ width: 50, background: '#1a1a2a', border: '1px solid #333', borderRadius: 4, color: '#ddd', padding: '2px 4px', fontSize: 11, marginLeft: 4 }}
          /> ft
        </label>
        <select value={line.nozzle.name}
          onChange={(e) => {
            const nozzle = (Object.values(NOZZLE_PROFILES) as NozzleProfile[]).find((n: NozzleProfile) => n.name === e.target.value);
            if (nozzle) setLineNozzle(lineId, nozzle);
          }}
          style={{ background: '#1a1a2a', border: '1px solid #333', borderRadius: 4, color: '#ddd', padding: '2px 4px', fontSize: 10, flex: 1, minWidth: 100 }}
        >
          {(Object.values(NOZZLE_PROFILES) as NozzleProfile[]).map((n: NozzleProfile) => (
            <option key={n.name} value={n.name}>{n.name}</option>
          ))}
        </select>
      </div>
      {line.isOpen && line.flowRate > 0 && (
        <div style={{ display: 'flex', gap: 12, marginTop: 4, fontSize: 10 }}>
          <span style={{ color: '#3b82f6' }}>{line.flowRate} GPM</span>
          <span style={{ color: '#f59e0b' }}>FL: {line.frictionLoss.toFixed(1)} PSI</span>
          <span style={{ color: '#22c55e' }}>NP: {line.nozzle.nozzlePressure} PSI</span>
        </div>
      )}
    </div>
  );
};

const ValveControl: React.FC = () => {
  const store = usePumpStore();
  return (
    <div className="metal-card" style={{ padding: 16 }}>
      <h3 style={{ color: '#888', fontSize: 10, fontWeight: 700, letterSpacing: 1, textTransform: 'uppercase', marginBottom: 12 }}>Intake Controls</h3>
      <ValveToggle label="Tank to Pump" isOpen={store.tankToPump} onToggle={store.toggleTankToPump} color="#3b82f6" />
      <ValveToggle label='5\" LDH Intake' isOpen={store.fiveInchIntake} onToggle={store.toggleFiveInchIntake} color="#6366f1" />
      <ValveToggle label='3\" Pony Suction' isOpen={store.threeInchPonySuction} onToggle={store.toggleThreeInchPonySuction} color="#8b5cf6" />
      <div style={{ borderTop: '1px solid rgba(255,255,255,0.06)', margin: '12px 0' }} />
      <h3 style={{ color: '#888', fontSize: 10, fontWeight: 700, letterSpacing: 1, textTransform: 'uppercase', marginBottom: 8 }}>Discharge Lines</h3>
      {LINE_IDS.map((id) => {
        const line = store[id] as HoseLineConfig;
        return (
          <div key={id}>
            <ValveToggle label={line.label} isOpen={line.isOpen} onToggle={() => store.toggleLine(id)} color={line.isOpen ? '#22c55e' : '#333'} />
            <LineDetail lineId={id} line={line} />
          </div>
        );
      })}
      <div style={{ borderTop: '1px solid rgba(255,255,255,0.06)', marginTop: 12, paddingTop: 8 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11 }}>
          <span style={{ color: '#888' }}>Total Flow</span>
          <span style={{ color: '#3b82f6', fontWeight: 700 }}>{store.totalFlowGPM} GPM</span>
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, marginTop: 4 }}>
          <span style={{ color: '#888' }}>Pump Capacity</span>
          <span style={{ color: store.pumpCapacityPercent > 90 ? '#ef4444' : store.pumpCapacityPercent > 70 ? '#f59e0b' : '#22c55e', fontWeight: 700 }}>
            {store.pumpCapacityPercent}%
          </span>
        </div>
        <div style={{ width: '100%', height: 4, background: '#1a1a2a', borderRadius: 2, marginTop: 6, overflow: 'hidden' }}>
          <motion.div
            style={{ height: '100%', borderRadius: 2, background: store.pumpCapacityPercent > 90 ? '#ef4444' : store.pumpCapacityPercent > 70 ? '#f59e0b' : '#22c55e' }}
            animate={{ width: `${store.pumpCapacityPercent}%` }}
            transition={{ type: 'spring', stiffness: 200, damping: 20 }}
          />
        </div>
      </div>
    </div>
  );
};

export default ValveControl;