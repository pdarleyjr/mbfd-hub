import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react-swc';

/**
 * Component tests for the Daily Checkout app (React Testing Library on jsdom). They are separate from the production
 * build: nothing under `src/**\/*.test.*` or `src/test/` is imported by the app, so none of it ships.
 */
export default defineConfig({
  plugins: [react()],
  resolve: { dedupe: ['react', 'react-dom'] },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    css: false,
    // Pages with many controls are slow on a shared CI runner.
    testTimeout: 20_000,
    restoreMocks: true,
  },
});
