import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/daily-checkout/src/main.tsx',
                'resources/js/daily-checkout/src/index.css',
            ],
            refresh: true,
        }),
        react(),
    ],
});
