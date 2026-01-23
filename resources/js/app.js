import './bootstrap';
import * as Sentry from "@sentry/browser";

// Initialize Sentry for frontend error tracking and performance monitoring
if (import.meta.env.VITE_SENTRY_DSN) {
    Sentry.init({
        dsn: import.meta.env.VITE_SENTRY_DSN,
        release: import.meta.env.VITE_SENTRY_RELEASE || 'development',
        environment: import.meta.env.VITE_APP_ENV || import.meta.env.MODE || "production",
        
        // Performance Monitoring
        integrations: [
            new Sentry.BrowserTracing({
                // Track navigation and page load performance
                tracePropagationTargets: [
                    "localhost",
                    /^https:\/\/support\.darleyplex\.com/,
                    /^\//
                ],
            }),
            new Sentry.Replay({
                // Session Replay for visual debugging
                maskAllText: false,
                blockAllMedia: false,
            }),
        ],

        // Performance sampling rates
        tracesSampleRate: 0.1, // 10% of transactions for performance monitoring
        
        // Session Replay sampling
        replaysSessionSampleRate: 0.1, // 10% of normal sessions
        replaysOnErrorSampleRate: 1.0, // 100% of sessions with errors

        // Capture unhandled promise rejections
        attachStacktrace: true,

        // Filter out noisy errors
        beforeSend(event, hint) {
            // Filter out browser extension errors
            if (event.exception?.values?.[0]?.stacktrace?.frames?.some(
                frame => frame.filename?.includes('extension://')
            )) {
                return null;
            }
            return event;
        },
    });

    console.log('[Sentry] Initialized successfully');
} else {
    console.warn('[Sentry] DSN not configured, skipping initialization');
}
