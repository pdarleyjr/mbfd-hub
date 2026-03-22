import React from 'react'
import ReactDOM from 'react-dom/client'
import * as Sentry from '@sentry/react'
import App from './App.tsx'
import { QueryProvider } from './providers/QueryProvider'
import './index.css'

Sentry.init({
  dsn: import.meta.env.VITE_SENTRY_DSN,
  environment: import.meta.env.MODE,
  release: import.meta.env.VITE_SENTRY_RELEASE,
})

// Service worker is registered by vite-plugin-pwa via registerSW.js (in index.html).
// Do NOT register a second SW here — dual registration causes infinite reload loops.
// See: ERROR-037 fix applied 2026-03-21

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryProvider>
      <App />
    </QueryProvider>
  </React.StrictMode>,
)

// Hide splash screen once React has mounted
if (typeof (window as any).__hideSplash === 'function') {
  (window as any).__hideSplash()
}
