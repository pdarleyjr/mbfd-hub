import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    globals: true,
    environment: 'node',
    include: ['tests/unit/**/*.test.ts', 'tests/integration/**/*.test.ts'],
    pool: 'forks',
    testTimeout: 30_000,
    hookTimeout: 60_000,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'lcov'],
      include: [
        'apps/api/src/**/*.ts',
        'apps/worker/src/**/*.ts',
        'packages/db/src/**/*.ts',
        'packages/shared/src/**/*.ts',
      ],
    },
  },
  resolve: {
    alias: {
      '@mbfd-vacation/db': new URL('./packages/db/src/index.ts', import.meta.url).pathname,
      '@mbfd-vacation/shared': new URL('./packages/shared/src/index.ts', import.meta.url).pathname,
    },
  },
});
