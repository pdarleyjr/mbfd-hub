import React from 'react';
import { motion } from 'framer-motion';
import Gauge from './Gauge';
import ShiftModeToggle from './ShiftModeToggle';
import ValveControl from './ValveControl';
import { usePumpStore } from '../stores/usePumpStore';

const PumpPanel: React.FC = () => {
  const store = usePumpStore();

  return (
    <div className="pump-panel-bg" style={{ padding: 16 }}>
      <div style={{ maxWidth: 1200, margin: '0 auto 16px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div>
          <h1 style={{ color: '#fff', fontSize: 22, fontWeight: 800, margin: 0, letterSpacing: -0.5 }}>PUMP SIMULATOR</h1>
          <p style={{ color: '#666', fontSize: 12, margin: '2px 0 0' }}>Pierce Fire Apparatus \u2014 Pump Operations Training</p>
        </div>
        <motion.button onClick={store.reset}
          style={{ padding: '8px 16px', background: 'linear-gradient(135deg, #333, #222)', border: '1px solid #444', borderRadius: 6, color: '#ccc', fontSize: 12, fontWeight: 600, cursor: 'pointer' }}
          whileTap={{ scale: 0.95 }}>RESET PANEL</motion.button>
      </div>

      <div style={{ maxWidth: 1200, margin: '0 auto', display: 'grid', gridTemplateColumns: '1fr 320px', gap: 16 }}>
        <div>
          <motion.div className={store.isCavitating ? 'cavitation-active' : ''}>
            <div className="metal-card" style={{ padding: 20, marginBottom: 16 }}>
              <h2 style={{ color: '#888', fontSize: 10, fontWeight: 700, letterSpacing: 1, textTransform: 'uppercase', marginBottom: 16 }}>Pressure Gauges</h2>
              <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'center', gap: 24 }}>
                <Gauge value={store.intakePressure} min={-30} max={30} label="Intake" unit="PSI" warningThreshold={-10} dangerThreshold={-20} />
                <Gauge value={store.masterDischargePressure} min={0} max={400} label="Discharge" unit="PSI" warningThreshold={250} dangerThreshold={350} isCavitating={store.isCavitating} />
                <Gauge value={store.engineRpm} min={0} max={3000} label="Tachometer" unit="RPM" warningThreshold={2400} dangerThreshold={2800} />
              </div>
              {store.isCavitating && (
                <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }}
                  style={{ marginTop: 16, padding: 12, background: 'rgba(220,38,38,0.15)', border: '1px solid #ef4444', borderRadius: 8, display: 'flex', alignItems: 'center', gap: 10 }}>
                  <div className="warning-blink" style={{ width: 10, height: 10, borderRadius: '50%', background: '#ef4444', boxShadow: '0 0 10px #ef4444' }} />
                  <span style={{ color: '#fca5a5', fontSize: 13, fontWeight: 600 }}>\u26a0 CAVITATION \u2014 Reduce throttle or increase intake pressure</span>
                </motion.div>
              )}
            </div>
          </motion.div>

          <div className="metal-card" style={{ padding: 20, marginBottom: 16 }}>
            <h2 style={{ color: '#888', fontSize: 10, fontWeight: 700, letterSpacing: 1, textTransform: 'uppercase', marginBottom: 12 }}>Engine Controls</h2>
            <div style={{ marginBottom: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                <label style={{ color: '#ccc', fontSize: 12, fontWeight: 600 }}>Throttle</label>
                <span style={{ color: '#f59e0b', fontWeight: 700, fontFamily: 'monospace', fontSize: 14 }}>{store.throttlePosition}%</span>
              </div>
              <input type="range" min="0" max="100" value={store.throttlePosition} onChange={(e) => store.setThrottlePosition(Number(e.target.value))} style={{ width: '100%' }} />
            </div>
            <div style={{ marginBottom: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                <label style={{ color: '#ccc', fontSize: 12, fontWeight: 600 }}>Engine RPM</label>
                <span style={{ color: '#22c55e', fontWeight: 700, fontFamily: 'monospace', fontSize: 14 }}>{store.engineRpm} RPM</span>
              </div>
              <input type="range" min="0" max="3000" step="50" value={store.engineRpm} onChange={(e) => store.setEngineRpm(Number(e.target.value))} style={{ width: '100%' }} />
            </div>
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                <label style={{ color: '#ccc', fontSize: 12, fontWeight: 600 }}>Intake Vacuum/Pressure</label>
                <span style={{ color: store.intakePressure < -10 ? '#ef4444' : '#3b82f6', fontWeight: 700, fontFamily: 'monospace', fontSize: 14 }}>{store.intakePressure} PSI</span>
              </div>
              <input type="range" min="-30" max="30" value={store.intakePressure} onChange={(e) => store.setIntakePressure(Number(e.target.value))} style={{ width: '100%' }} />
              <p style={{ color: '#555', fontSize: 10, marginTop: 4 }}>Negative = vacuum. Keep above -10 PSI to prevent cavitation.</p>
            </div>
          </div>

          <div className="metal-card" style={{ padding: 16 }}>
            <h3 style={{ color: '#888', fontSize: 10, fontWeight: 700, letterSpacing: 1, textTransform: 'uppercase', marginBottom: 8 }}>Hydraulics Reference</h3>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, fontSize: 10, color: '#888' }}>
              <div><strong style={{ color: '#aaa' }}>Friction Loss Formula:</strong><br />FL = C \u00d7 (GPM/100)\u00b2 \u00d7 (L/100)</div>
              <div><strong style={{ color: '#aaa' }}>Coefficients (C):</strong><br />1\u00be\" = 15.5 | 2\u00bd\" = 2.0 | 3\" = 0.8 | 5\" = 0.08</div>
            </div>
          </div>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <ShiftModeToggle mode={store.shiftMode} onChange={store.setShiftMode} />
          <ValveControl />
          <div className="metal-card" style={{ padding: 16 }}>
            <h3 style={{ color: '#888', fontSize: 10, fontWeight: 700, letterSpacing: 1, textTransform: 'uppercase', marginBottom: 8 }}>System Status</h3>
            <div style={{ fontSize: 12 }}>
              {[
                { label: 'Mode', value: store.shiftMode.toUpperCase(), color: store.shiftMode === 'pump' ? '#f59e0b' : '#22c55e' },
                { label: 'Discharge PSI', value: store.masterDischargePressure.toFixed(1), color: '#3b82f6' },
                { label: 'Total Flow', value: `${store.totalFlowGPM} GPM`, color: '#3b82f6' },
                { label: 'Pump Load', value: `${store.pumpCapacityPercent}%`, color: store.pumpCapacityPercent > 90 ? '#ef4444' : '#22c55e' },
                { label: 'Cavitation', value: store.isCavitating ? 'DANGER' : 'OK', color: store.isCavitating ? '#ef4444' : '#22c55e' },
              ].map((row) => (
                <div key={row.label} style={{ display: 'flex', justifyContent: 'space-between', padding: '3px 0' }}>
                  <span style={{ color: '#666' }}>{row.label}</span>
                  <span style={{ color: row.color, fontWeight: 600, fontFamily: 'monospace' }}>{row.value}</span>
                </div>
              ))}
            </div>
          </div>
          <div className="metal-card" style={{ padding: 16, opacity: 0.8 }}>
            <h3 style={{ color: '#888', fontSize: 10, fontWeight: 700, letterSpacing: 1, textTransform: 'uppercase', marginBottom: 6 }}>How to Use</h3>
            <ol style={{ color: '#666', fontSize: 10, paddingLeft: 16, margin: 0, lineHeight: 1.6 }}>
              <li>Switch to PUMP mode</li>
              <li>Open Tank to Pump valve</li>
              <li>Select and open discharge lines</li>
              <li>Increase RPM and Throttle</li>
              <li>Monitor discharge pressure and flow</li>
              <li>Watch for cavitation warnings</li>
            </ol>
          </div>
        </div>
      </div>
      <style>{`@media (max-width: 768px) { div[style*="grid-template-columns: 1fr 320px"] { grid-template-columns: 1fr !important; } }`}</style>
    </div>
  );
};

export default PumpPanel;