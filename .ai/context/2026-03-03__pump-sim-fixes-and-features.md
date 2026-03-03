# Pump Simulator V2 — Fixes & Features Session
**Date:** 2026-03-03
**Status:** Complete

## Issues Fixed
1. Removed Tailwind CDN from `pump-simulator.blade.php`
2. Removed `@apply` directives from CSS (iOS black-screen crash prevention)
3. Removed `rollupOptions.output.entryFileNames` from `vite.config.js`
4. Migrated store from React Context to actual Zustand
5. Added `import React` to `main.tsx`
6. Implemented advanced hydraulics with friction loss math
7. Rebuilt UI with brushed-metal dark panel, SVG chrome bezel gauges

## Architecture
- **Store**: Zustand (`usePumpStore`) with 10 nozzle profiles, friction loss calculation
- **Formula**: FL = C × (GPM/100)² × (L/100) with coefficients for 1", 1¾", 2½", 3", 5" hose
- **Valves**: Tank-to-Pump, 5" LDH Intake, 3" Pony Suction + 6 discharge lines
- **Gauges**: SVG chrome bezel with Framer Motion spring needle (stiffness:100, damping:10)
- **Cavitation**: Detected when pump mode + intake<0 + MDP>150 + throttle>40%
- **CSS**: Zero @apply directives, iOS safe-area support, responsive grid
