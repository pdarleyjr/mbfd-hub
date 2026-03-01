import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { sentryVitePlugin } from '@sentry/vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/push-notification-widget.js'],
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
        sourcemap: 'hidden', // Generate source maps for Sentry
    },
});
