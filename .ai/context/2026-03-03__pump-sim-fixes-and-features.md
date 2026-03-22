# Pump Simulator V2 — Fixes & Features Session
**Date:** 2026-03-03
**Status:** In Progress

## Issues Identified
1. **Tailwind CDN** in `pump-simulator.blade.php` line 13 — must remove
2. **`@apply` directives** in `styles/index.css` lines 48-53 — causes iOS black-screen crash
3. **`rollupOptions.output.entryFileNames`** in `vite.config.js` — overrides ALL entry filenames, breaking other Vite entries
4. **Store uses React Context** not actual Zustand — despite zustand being installed
5. **Missing `import React`** in `main.tsx` — causes `ReferenceError: React is not defined`
6. **No advanced hydraulics** — missing friction loss, nozzle profiles, expanded valve array
7. **Flat CSS UI** — needs brushed-metal panel, spec-compliant gauge bezels

## Plan
- Fix blade: remove CDN script block
- Fix vite.config.js: remove rollupOptions.output.entryFileNames
- Rewrite store to actual Zustand with friction loss math + expanded valves
- Rewrite CSS: no @apply, plain CSS only
- Rebuild Gauge with SVG bezel + Framer Motion spring needle
- Add expanded valve panel (crosslays, deck gun, booster, tank-to-pump, intakes)
- Add Framer Motion cavitation vibration keyframes
