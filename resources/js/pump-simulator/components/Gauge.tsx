import React, { useMemo } from 'react';
import { motion } from 'framer-motion';

interface GaugeProps {
  value: number;
  min: number;
  max: number;
  label: string;
  unit: string;
  size?: number;
  warningThreshold?: number;
  dangerThreshold?: number;
  isCavitating?: boolean;
}

const Gauge: React.FC<GaugeProps> = ({
  value, min, max, label, unit, size = 180,
  warningThreshold, dangerThreshold, isCavitating = false,
}) => {
  const normalizedValue = Math.max(min, Math.min(max, value));
  const percentage = (normalizedValue - min) / (max - min);
  const rotation = -135 + percentage * 270;

  const ticks = useMemo(() => {
    const range = max - min;
    const step = range <= 60 ? 10 : range <= 200 ? 25 : range <= 500 ? 50 : 100;
    const result = [];
    for (let v = min; v <= max; v += step) {
      const pct = (v - min) / (max - min);
      const angle = -135 + pct * 270;
      const rad = (angle * Math.PI) / 180;
      const cx = 100, cy = 100, r1 = 72, r2 = 80, rLabel = 62;
      result.push({
        value: v,
        x1: cx + r1 * Math.cos(rad - Math.PI / 2),
        y1: cy + r1 * Math.sin(rad - Math.PI / 2),
        x2: cx + r2 * Math.cos(rad - Math.PI / 2),
        y2: cy + r2 * Math.sin(rad - Math.PI / 2),
        lx: cx + rLabel * Math.cos(rad - Math.PI / 2),
        ly: cy + rLabel * Math.sin(rad - Math.PI / 2),
      });
    }
    return result;
  }, [min, max]);

  const makeArc = (startPct: number, endPct: number) => {
    const r = 76;
    const cx = 100, cy = 100;
    const startAngle = (-135 + startPct * 270 - 90) * (Math.PI / 180);
    const endAngle = (-135 + endPct * 270 - 90) * (Math.PI / 180);
    const x1 = cx + r * Math.cos(startAngle);
    const y1 = cy + r * Math.sin(startAngle);
    const x2 = cx + r * Math.cos(endAngle);
    const y2 = cy + r * Math.sin(endAngle);
    const largeArc = endPct - startPct > 0.5 ? 1 : 0;
    return `M ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2}`;
  };

  const warnPct = warningThreshold ? (warningThreshold - min) / (max - min) : 0.7;
  const dangerPct = dangerThreshold ? (dangerThreshold - min) / (max - min) : 0.9;

  const cavitationAnim = isCavitating
    ? { rotate: [rotation - 3, rotation + 2, rotation - 4, rotation + 3, rotation - 1, rotation] }
    : { rotate: rotation };

  const cavitationTransition = isCavitating
    ? { duration: 0.15, repeat: Infinity, ease: 'easeInOut' as const }
    : { type: 'spring' as const, stiffness: 100, damping: 10 };

  return (
    <div style={{ width: size, height: size, position: 'relative', flexShrink: 0 }} className="gauge-container">
      <svg viewBox="0 0 200 200" width={size} height={size}>
        <defs>
          <linearGradient id={`bezel-${label}`} x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor="#888" />
            <stop offset="20%" stopColor="#ccc" />
            <stop offset="40%" stopColor="#666" />
            <stop offset="60%" stopColor="#bbb" />
            <stop offset="80%" stopColor="#555" />
            <stop offset="100%" stopColor="#999" />
          </linearGradient>
          <radialGradient id={`face-${label}`} cx="40%" cy="35%">
            <stop offset="0%" stopColor="#f5f0e8" />
            <stop offset="60%" stopColor="#e0d8c8" />
            <stop offset="100%" stopColor="#c8bfa5" />
          </radialGradient>
        </defs>
        <circle cx="100" cy="100" r="98" fill={`url(#bezel-${label})`} />
        <circle cx="100" cy="100" r="90" fill="#1a1a2a" />
        <circle cx="100" cy="100" r="85" fill={`url(#face-${label})`} />
        <path d={makeArc(0, warnPct)} fill="none" stroke="#22c55e" strokeWidth="4" opacity="0.7" />
        <path d={makeArc(warnPct, dangerPct)} fill="none" stroke="#f59e0b" strokeWidth="4" opacity="0.7" />
        <path d={makeArc(dangerPct, 1)} fill="none" stroke="#ef4444" strokeWidth="4" opacity="0.7" />
        {ticks.map((t, i) => (
          <g key={i}>
            <line x1={t.x1} y1={t.y1} x2={t.x2} y2={t.y2} stroke="#333" strokeWidth="1.5" />
            <text x={t.lx} y={t.ly} textAnchor="middle" dominantBaseline="central"
              fontSize="9" fill="#444" fontWeight="600" fontFamily="monospace">{t.value}</text>
          </g>
        ))}
        <text x="100" y="130" textAnchor="middle" fontSize="10" fill="#555" fontWeight="700" fontFamily="monospace" letterSpacing="1">{unit}</text>
        <text x="100" y="145" textAnchor="middle" fontSize="8" fill="#777" fontWeight="600" letterSpacing="0.5">{label.toUpperCase()}</text>
        <circle cx="100" cy="100" r="8" fill={`url(#bezel-${label})`} />
        <circle cx="100" cy="100" r="5" fill="#333" />
      </svg>
      <motion.div
        style={{
          position: 'absolute', left: '50%', bottom: '50%',
          width: 3, height: size * 0.35, marginLeft: -1.5,
          background: 'linear-gradient(to top, #cc0000, #ff3333)',
          borderRadius: '2px 2px 0 0', transformOrigin: 'bottom center',
          filter: 'drop-shadow(0 0 4px rgba(255,0,0,0.5))', zIndex: 5,
        }}
        animate={cavitationAnim}
        transition={cavitationTransition}
      />
      <div style={{
        position: 'absolute', bottom: size * 0.08, left: '50%',
        transform: 'translateX(-50%)', background: 'rgba(0,0,0,0.75)',
        borderRadius: 4, padding: '2px 8px', zIndex: 6,
      }}>
        <span style={{
          fontFamily: 'monospace', fontSize: 14, fontWeight: 700,
          color: dangerThreshold && normalizedValue >= dangerThreshold ? '#ef4444'
            : warningThreshold && normalizedValue >= warningThreshold ? '#f59e0b' : '#22c55e',
        }}>{normalizedValue.toFixed(0)}</span>
      </div>
      {isCavitating && (
        <motion.div
          style={{
            position: 'absolute', top: 8, right: 8, width: 10, height: 10,
            borderRadius: '50%', background: '#ef4444', boxShadow: '0 0 8px #ef4444', zIndex: 6,
          }}
          animate={{ opacity: [1, 0.2, 1] }}
          transition={{ duration: 0.3, repeat: Infinity }}
        />
      )}
    </div>
  );
};

export default Gauge;