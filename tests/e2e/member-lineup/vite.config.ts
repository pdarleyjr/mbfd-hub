import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    root: fileURLToPath(new URL('.', import.meta.url)),
    plugins: [react()],
    server: { watch: null, fs: { allow: [fileURLToPath(new URL('../../..', import.meta.url))] } },
    optimizeDeps: { entries: ['fixture.tsx'] },
});
