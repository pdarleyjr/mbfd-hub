import assert from 'node:assert/strict';
import test from 'node:test';
import worker from '../src/index.ts';

test('does not call PulsePoint when the worker secret is absent', async (t) => {
  const originalFetch = globalThis.fetch;
  const originalCaches = globalThis.caches;
  let providerCalls = 0;

  globalThis.fetch = async () => {
    providerCalls += 1;
    throw new Error('PulsePoint must not be called without its worker secret.');
  };
  globalThis.caches = {
    default: {
      match: async () => undefined,
      put: async () => undefined,
    },
  } as CacheStorage;

  t.after(() => {
    globalThis.fetch = originalFetch;
    globalThis.caches = originalCaches;
  });

  const response = await worker.fetch(
    new Request('https://pulsepoint-proxy.example.test/incidents', {
      headers: { Origin: 'https://app.example.test' },
    }),
    {
      ALLOWED_ORIGIN: 'https://app.example.test',
      PULSEPOINT_AGENCY: 'X1012',
      CACHE_TTL: '30',
    },
  );

  assert.equal(response.status, 503);
  assert.equal(providerCalls, 0);
});
