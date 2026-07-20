import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

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
    alias: [
      {
        find: /^@mbfd-vacation\/db\/(.+)$/,
        replacement: `${fileURLToPath(new URL('./packages/db/src/', import.meta.url))}$1`,
      },
      {
        find: '@mbfd-vacation/db',
        replacement: fileURLToPath(new URL('./packages/db/src/index.ts', import.meta.url)),
      },
      {
        find: /^@mbfd-vacation\/shared\/(.+)$/,
        replacement: `${fileURLToPath(new URL('./packages/shared/src/', import.meta.url))}$1`,
      },
      {
        find: '@mbfd-vacation/shared',
        replacement: fileURLToPath(new URL('./packages/shared/src/index.ts', import.meta.url)),
      },
    ],
  },
});
