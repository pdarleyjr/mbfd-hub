import './bootstrap';
import * as Sentry from "@sentry/browser";

// Only initialize Sentry if DSN is provided (production environment)
if (import.meta.env.VITE_SENTRY_DSN) {
    Sentry.init({
        dsn: import.meta.env.VITE_SENTRY_DSN,
        release: import.meta.env.VITE_SENTRY_RELEASE || 'unknown',
        environment: import.meta.env.VITE_APP_ENV || 'production',
        
        // Performance Monitoring
        integrations: [
            Sentry.browserTracingIntegration({
                // Set sampling rate for performance monitoring
                tracePropagationTargets: ["localhost", /^https:\/\/support\.darleyplex\.com/],
            }),
            Sentry.replayIntegration({
                // Mask all text content for privacy
                maskAllText: true,
                blockAllMedia: true,
            }),
        ],
        
        // Sample rates
        tracesSampleRate: 0.1, // 10% of transactions
        replaysSessionSampleRate: 0.1, // 10% of sessions
        replaysOnErrorSampleRate: 1.0, // 100% of sessions with errors
        
        // Additional configuration
        beforeSend(event) {
            // Filter out localhost errors in development
            if (event.request?.url?.includes('localhost')) {
                return null;
            }
            return event;
        },
    });
    
    // Log that Sentry is initialized
    console.log('[Sentry] Frontend error tracking initialized');
}
