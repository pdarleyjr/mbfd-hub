import { defineConfig } from 'vite';

export default defineConfig({
    base: '/vendor/policy-library/',
    publicDir: false,
    build: {
        outDir: 'public',
        emptyOutDir: true,
        target: 'es2022',
        rollupOptions: {
            input: 'resources/js/viewer.js',
            output: {
                entryFileNames: 'viewer.js',
                assetFileNames: asset => asset.names?.some(name => name.endsWith('.css')) ? 'viewer.css' : 'assets/[name]-[hash][extname]',
            },
        },
    },
});
