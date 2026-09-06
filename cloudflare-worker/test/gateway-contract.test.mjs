import assert from 'node:assert/strict';
import test from 'node:test';

import worker, { callGateway } from '../src/index.ts';

test('generation uses the frozen mbfd-support-ai gateway contract', async () => {
  const originalFetch = globalThis.fetch;
  const requests = [];
  globalThis.fetch = async (url, init) => {
    requests.push({ url, init });
    return new Response('{}', { status: 200 });
  };

  try {
    const env = {
      AI_GATEWAY_URL: 'https://gateway.test',
      AI_GATEWAY_TOKEN: 'unit-test-credential',
    };

    await callGateway(env, [{ role: 'user', content: 'first' }], false);
    await callGateway(env, [{ role: 'user', content: 'second' }], true);
  } finally {
    globalThis.fetch = originalFetch;
  }

  assert.equal(requests.length, 2);
  const requestIds = [];
  for (const request of requests) {
    assert.equal(request.url, 'https://gateway.test/v1/chat/completions');
    const headers = new Headers(request.init.headers);
    assert.equal(headers.get('Authorization'), 'Bearer unit-test-credential');
    assert.equal(headers.get('X-MBFD-Capability'), 'mbfd-general');
    assert.match(headers.get('X-Request-ID'), /^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/);
    requestIds.push(headers.get('X-Request-ID'));

    const payload = JSON.parse(request.init.body);
    assert.equal(payload.model, 'mbfd-general');
  }
  assert.notEqual(requestIds[0], requestIds[1]);
});

test('generation fails closed before fetch when its unique secret is absent', async () => {
  const originalFetch = globalThis.fetch;
  let fetchCalled = false;
  globalThis.fetch = async () => {
    fetchCalled = true;
    return new Response('{}', { status: 200 });
  };

  try {
    await assert.rejects(
      callGateway({ AI_GATEWAY_URL: 'https://gateway.test' }, [], false),
      /AI gateway credential is unavailable/,
    );
  } finally {
    globalThis.fetch = originalFetch;
  }

  assert.equal(fetchCalled, false);
});

test('chat preserves Workers AI embedding and Vectorize retrieval before gateway generation', async () => {
  const originalFetch = globalThis.fetch;
  const embeddingCalls = [];
  const vectorCalls = [];
  globalThis.fetch = async () => Response.json({
    choices: [{ message: { content: 'bounded answer' } }],
  });

  const env = {
    ALLOWED_ORIGIN: 'https://www.mbfdhub.com',
    AI_GATEWAY_URL: 'https://gateway.test',
    AI_GATEWAY_TOKEN: 'unit-test-credential',
    AI: {
      run: async (...args) => {
        embeddingCalls.push(args);
        return { data: [[0.1, 0.2]] };
      },
    },
    VECTORIZE: {
      query: async (...args) => {
        vectorCalls.push(args);
        return { matches: [] };
      },
    },
  };

  let response;
  try {
    response = await worker.fetch(new Request('https://worker.test/chat', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'CF-Connecting-IP': '192.0.2.20',
      },
      body: JSON.stringify({ message: 'Where is the policy?' }),
    }), env);
  } finally {
    globalThis.fetch = originalFetch;
  }

  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), {
    response: 'bounded answer',
    sources: [],
    model: 'mbfd-general',
  });
  assert.equal(embeddingCalls.length, 1);
  assert.equal(embeddingCalls[0][0], '@cf/baai/bge-large-en-v1.5');
  assert.deepEqual(embeddingCalls[0][1], { text: ['Where is the policy?'] });
  assert.deepEqual(vectorCalls, [[[0.1, 0.2], { topK: 6, returnMetadata: 'all' }]]);
});
