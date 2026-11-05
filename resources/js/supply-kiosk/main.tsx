// EMS Supply Room Kiosk entry. Contract and screen flow: docs/ems/supply-room-kiosk.md.
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '@fontsource-variable/plus-jakarta-sans';
import '../../css/mbfd-theme.css';
import './kiosk.css';
import { App } from './App.tsx';

const root = document.getElementById('supply-kiosk-root');
if (root) {
    createRoot(root).render(<StrictMode><App /></StrictMode>);
} else {
    console.error('Supply kiosk: #supply-kiosk-root is missing from the page.');
}
