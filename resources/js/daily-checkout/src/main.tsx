import React from 'react'
import ReactDOM from 'react-dom/client'
import * as Sentry from '@sentry/react'
import App from './App.tsx'
import './index.css'

Sentry.init({
  dsn: import.meta.env.VITE_SENTRY_DSN,
  environment: import.meta.env.MODE,
  release: import.meta.env.VITE_SENTRY_RELEASE,
})

const DAILY_SW_VERSION = '2026-03-09-vehicle-inspections-fix-2';
const DAILY_SW_URL = `/daily/sw.js?v=${DAILY_SW_VERSION}`;
let hasReloadedForNewServiceWorker = false;

// Register service worker for PWA functionality with aggressive update handling
if ('serviceWorker' in navigator) {
  window.addEventListener('load', async () => {
    try {
      const registration = await navigator.serviceWorker.register(DAILY_SW_URL, { scope: '/daily/' });

      console.log('[PWA] Service worker registered successfully:', registration);

      if (registration.waiting) {
        registration.waiting.postMessage({ type: 'SKIP_WAITING' });
      }

      registration.addEventListener('updatefound', () => {
        const nextWorker = registration.installing;

        if (!nextWorker) {
          return;
        }

        nextWorker.addEventListener('statechange', () => {
          if (nextWorker.state === 'installed') {
            nextWorker.postMessage({ type: 'SKIP_WAITING' });
          }
        });
      });

      navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (hasReloadedForNewServiceWorker) {
          return;
        }

        hasReloadedForNewServiceWorker = true;
        window.location.reload();
      });

      await registration.update();
    } catch (error) {
      console.error('[PWA] Service worker registration failed:', error);
    }
  });
}

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
)

// Hide splash screen once React has mounted
if (typeof (window as any).__hideSplash === 'function') {
  (window as any).__hideSplash()
}
