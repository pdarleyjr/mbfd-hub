import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { sentryVitePlugin } from '@sentry/vite-plugin';

export default defineConfig({
    plugins: [
        react(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/pulse-font.css',
                'resources/js/app.js',
                'resources/js/hub-shell.js',
                'resources/js/hub-support/blade.js',
                'resources/js/pump-simulator/main.tsx',
                'resources/js/push-notification-widget.js',
                // "This device" block of Notification settings (loaded only on that page).
                'resources/js/pwa/push-enrollment.ts',
                'resources/js/pwa/install-ui.ts',
                'resources/css/filament/admin/theme.css',
                'resources/js/workgroup-data-dashboard.jsx',
                'resources/js/workgroup-l1-inventory.jsx',
                // Standalone Workgroup reports: bundled Chart.js and per-report token stylesheets.
                'resources/js/report-charts.js',
                'resources/css/reports/evaluation-report.css',
                'resources/css/reports/final-recommendations.css',
                'resources/css/reports/workgroup-summary.css',
                'resources/css/reports/saver-report.css',
                // Admin desktop-PWA bootstrap (registers /admin/ scoped SW,
                // gates install prompt by matchMedia, prefetches lookups).
                'resources/js/admin-pwa/main.ts',
                'resources/js/operational-forms/main.tsx',
                'resources/js/video-conferencing/main.tsx',
                'resources/js/training-roster/main.tsx',
                'resources/css/take-home-inspection.css',
                'resources/js/take-home-inspection.js',
                'resources/css/daily-roster.css',
                'resources/js/daily-roster.js',
            ],
            refresh: true,
        }),
        // Upload source maps to Sentry during production builds
        sentryVitePlugin({
            org: process.env.SENTRY_ORG || 'mbfd',
            project: process.env.SENTRY_PROJECT_FRONTEND || 'support-frontend',
            authToken: process.env.SENTRY_AUTH_TOKEN,
            // Only upload in production builds
            disable: !process.env.SENTRY_AUTH_TOKEN,
            // Configure source map upload
            sourcemaps: {
                assets: './public/build/**',
                filesToDeleteAfterUpload: './public/build/**/*.map',
            },
            release: {
                name: process.env.VITE_SENTRY_RELEASE || 'unknown',
                cleanArtifacts: true,
            },
        }),
    ],
    build: {
        // Never publish browser-readable source maps. CI generates hidden maps
        // only when Sentry can upload and delete them in the same build.
        sourcemap: process.env.SENTRY_AUTH_TOKEN ? 'hidden' : false,
        rolldownOptions: {
            output: {
                codeSplitting: {
                    groups: [
                        {
                            name: 'livekit',
                            test: /node_modules[\\/](?:livekit-client|@livekit)[\\/]/,
                            priority: 10,
                        },
                    ],
                },
            },
        },
    },
});
