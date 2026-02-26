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

// Register service worker for PWA functionality with correct /daily/ scope
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/daily/sw.js', { scope: '/daily/' })
      .then((registration) => {
        console.log('[PWA] Service worker registered successfully:', registration);
      })
      .catch((error) => {
        console.error('[PWA] Service worker registration failed:', error);
      });
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
