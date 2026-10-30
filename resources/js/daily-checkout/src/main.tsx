import React from 'react'
import ReactDOM from 'react-dom/client'
import * as Sentry from '@sentry/react'
import App from './App.tsx'
import { QueryProvider } from './providers/QueryProvider'
import './index.css'
import { startHubIssueDiagnosticsCollector } from '../../hub-support/diagnostics.js'
import { startUpdateRegistrar } from '../../pwa/update-registrar'
import { startForegroundAlerts } from '../../pwa/foreground-alerts'

startHubIssueDiagnosticsCollector()

Sentry.init({
  dsn: import.meta.env.VITE_SENTRY_DSN,
  environment: import.meta.env.MODE,
  release: import.meta.env.VITE_SENTRY_RELEASE,
})

if ('serviceWorker' in navigator) {
  // The one and only registration of this worker: plain URL (never a ?v= variant,
  // see 2.AI_AGENT_ERRORS.md ERROR-037), scope /daily/. Do not add a second register().
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/daily/sw.js', { scope: '/daily/' })
      .catch((error) => console.error('Service worker registration failed:', error))
  })
}

// Release detection, the "update ready" banner, chunk-load recovery and notification
// deep links (MBFD_NAVIGATE). No scriptUrl: the registration above stays the only one.
// It never reloads while a draft or a queued submission is busy (see src/pwa).
startUpdateRegistrar({ scope: '/daily/' })

// New in-app alerts pop up while Daily Checkout is open (one shared, visibility-aware poller per device,
// at most once a minute; it stops by itself when the member is signed out or the feed is unavailable).
startForegroundAlerts({ feedUrl: '/api/me/alerts' })

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
