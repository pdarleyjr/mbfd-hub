import { motion } from 'framer-motion';
import React from 'react';

interface GaugeProps {
  value: number;
  min: number;
  max: number;
  label: string;
  unit: string;
  warningThreshold?: number;
  dangerThreshold?: number;
  isCavitating?: boolean;
}

const Gauge: React.FC<GaugeProps> = ({
  value,
  min,
  max,
  label,
  unit,
  warningThreshold,
  dangerThreshold,
  isCavitating = false,
}) => {
  // Calculate rotation angle (0 value = -135deg, max value = 135deg, total 270deg sweep)
  const normalizedValue = Math.max(min, Math.min(max, value));
  const percentage = (normalizedValue - min) / (max - min);
  const rotation = -135 + percentage * 270;

  // Determine color based on thresholds
  const getNeedleColor = () => {
    if (dangerThreshold && normalizedValue >= dangerThreshold) return '#ef4444';
    if (warningThreshold && normalizedValue >= warningThreshold) return '#f59e0b';
    return '#22c55e';
  };

  const getTickCount = () => {
    const range = max - min;
    const step = range <= 100 ? 10 : range <= 500 ? 50 : 100;
    return Math.floor(range / step) + 1;
  };

  const ticks = Array.from({ length: getTickCount() }, (_, i) => {
    const tickValue = min + i * ((max - min) / (getTickCount() - 1));
    const tickPercentage = (tickValue - min) / (max - min);
    const tickRotation = -135 + tickPercentage * 270;
    return { value: tickValue, rotation: tickRotation };
  });

  return (
    <div className="relative w-48 h-48 flex flex-col items-center justify-center">
      {/* Bezel - metallic ring */}
      <div className="absolute inset-0 rounded-full bg-gradient-to-br from-gray-700 via-gray-900 to-black shadow-xl border-4 border-gray-600">
        {/* Inner bezel ring */}
        <div className="absolute inset-2 rounded-full bg-gradient-to-br from-gray-800 to-gray-950 border border-gray-500">
          {/* Gauge face */}
          <div className="absolute inset-3 rounded-full bg-gradient-to-b from-gray-100 to-gray-300">
            {/* Tick marks */}
            <svg className="absolute inset-0 w-full h-full" viewBox="0 0 200 200">
              {ticks.map((tick, i) => {
                const isMajor = i === 0 || i === ticks.length - 1 || i === Math.floor(ticks.length / 2);
                return (
                  <line
                    key={i}
                    x1="100"
                    y1="20"
                    x2="100"
                    y2={isMajor ? '35' : '30'}
                    stroke="#374151"
                    strokeWidth={isMajor ? 2 : 1}
                    transform={`rotate(${tick.rotation} 100 100)`}
                  />
                );
              })}
              {/* Labels */}
              <text x="100" y="55" textAnchor="middle" fontSize="10" fill="#374151" fontWeight="bold">
                {min}
              </text>
              <text x="175" y="105" textAnchor="middle" fontSize="10" fill="#374151" fontWeight="bold">
                {Math.round((min + max) / 2)}
              </text>
              <text x="100" y="165" textAnchor="middle" fontSize="10" fill="#374151" fontWeight="bold">
                {max}
              </text>
            </svg>

            {/* Center cap */}
            <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-gradient-to-br from-gray-400 to-gray-600 shadow-inner border-2 border-gray-500" />
          </div>
        </div>
      </div>

      {/* Needle */}
      <motion.div
        className="absolute w-1 h-20 bg-gradient-to-t from-red-600 to-red-400 origin-bottom rounded-t-full shadow-lg"
        style={{
          bottom: '50%',
          left: 'calc(50% - 2px)',
          transformOrigin: 'bottom center',
        }}
        animate={{
          rotate: rotation,
        }}
        transition={{
          type: 'spring',
          stiffness: 300,
          damping: 20,
          mass: 0.5,
        }}
      />

      {/* Cavitation warning indicator */}
      {isCavitating && (
        <motion.div
          className="absolute top-2 right-2 w-4 h-4 bg-red-500 rounded-full"
          animate={{
            opacity: [1, 0.3, 1],
          }}
          transition={{
            duration: 0.3,
            repeat: Infinity,
          }}
        />
      )}

      {/* Label */}
      <div className="absolute bottom-2 text-center">
        <div className="text-xs font-bold text-gray-700 uppercase tracking-wide">{label}</div>
        <div className="text-lg font-bold text-gray-900">
          {normalizedValue.toFixed(1)}
          <span className="text-xs font-normal text-gray-600 ml-1">{unit}</span>
        </div>
      </div>
    </div>
  );
};

export default Gauge;
