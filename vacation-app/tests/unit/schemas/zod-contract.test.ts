import { randomUUID } from 'node:crypto';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { WorkCodeDecisionsSchema } from '../../../packages/shared/src/work-code-decision';

// Pins the zod 4 behaviours the app relies on (upgraded from zod 3 for
// GHSA-mrhx-c7xc-m3w5): URL checks on deployment env values, UUID checks on
// database ids, defaults, string coercion and the flatten() error shape.

const productionShapedEnv = {
  NODE_ENV: 'production',
  DATABASE_URL: 'postgres://vacation:placeholder@vac-postgres:5432/vacation',
  REDIS_URL: 'redis://vac-redis:6379',
  R2_ENDPOINT: 'https://example-account.r2.cloudflarestorage.com',
  R2_ACCESS_KEY_ID: 'placeholder',
  R2_SECRET_ACCESS_KEY: 'placeholder',
  R2_BUCKET: 'mbfd-hub-laravel',
  PIN_AUDIT_WEBHOOK_SECRET: 'x'.repeat(32),
  ORIGIN_SHARED_TOKEN: 'y'.repeat(32),
  API_PORT: '3001',
  WORKER_CONCURRENCY: '4',
};

afterEach(() => {
  vi.unstubAllEnvs();
  vi.restoreAllMocks();
  vi.resetModules();
});

function stubEnv(values: Record<string, string>): void {
  for (const [key, value] of Object.entries(values)) vi.stubEnv(key, value);
}

describe('deployment env schemas', () => {
  it('accepts production-shaped API env, coerces numbers and applies defaults', async () => {
    stubEnv(productionShapedEnv);
    const { getEnv } = await import('../../../apps/api/src/env');
    const env = getEnv();
    expect(env.API_PORT).toBe(3001);
    expect(env.R2_PREFIX).toBe('vacation/imports/');
    expect(env.MAX_UPLOAD_BYTES).toBe(1_073_741_824);
    expect(env.DATABASE_URL).toBe(productionShapedEnv.DATABASE_URL);
  });

  it('accepts production-shaped worker env and coerces concurrency', async () => {
    stubEnv(productionShapedEnv);
    const { getEnv } = await import('../../../apps/worker/src/env');
    const env = getEnv();
    expect(env.WORKER_CONCURRENCY).toBe(4);
    expect(env.MAX_TELESTAFF_ROWS).toBe(5_000_000);
  });

  it('rejects a malformed URL and reports it by field name', async () => {
    stubEnv({ ...productionShapedEnv, REDIS_URL: 'not a url' });
    const exit = vi.spyOn(process, 'exit').mockImplementation(() => {
      throw new Error('exit');
    });
    const errors = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const { getEnv } = await import('../../../apps/api/src/env');
    expect(() => getEnv()).toThrow('exit');
    expect(exit).toHaveBeenCalledWith(1);
    expect(errors.mock.calls[0]?.[1]).toHaveProperty('REDIS_URL');
  });
});

describe('work code decisions', () => {
  it('accepts database-generated ids and fills create_new defaults', () => {
    const parsed = WorkCodeDecisionsSchema.parse([
      { kind: 'use_existing', telestaffDescription: 'Vacation', leaveCodeId: randomUUID() },
      { kind: 'create_new', telestaffDescription: 'Union Leave', newCode: { code: 'UL', label: 'Union Leave' } },
      { kind: 'skip', telestaffDescription: 'Noise' },
    ]);
    expect(parsed[1]).toMatchObject({
      newCode: { uiColor: '#78716C', countsAgainstVacationBalance: false, isADayMarker: false },
    });
  });

  it('rejects a non-UUID id with a flatten() shape the API can return', () => {
    const result = WorkCodeDecisionsSchema.safeParse([
      { kind: 'use_existing', telestaffDescription: 'Vacation', leaveCodeId: 'not-a-uuid' },
    ]);
    expect(result.success).toBe(false);
    if (result.success) return;
    const flattened = result.error.flatten();
    expect(flattened).toHaveProperty('formErrors');
    expect(flattened).toHaveProperty('fieldErrors');
  });
});
