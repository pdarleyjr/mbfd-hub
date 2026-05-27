/// <reference types="@cloudflare/workers-types" />

import { renderPinForm } from './pin-form';
import { constantTimeEquals, signCookie, verifyCookie } from './sign';

const COOKIE_NAME = 'vac_pin';
const COOKIE_TTL_SEC = 14 * 24 * 60 * 60; // 14 days
const RATE_LIMIT_MAX_ATTEMPTS = 5;
const RATE_LIMIT_WINDOW_SEC = 15 * 60;

export type Env = {
  ORIGIN_URL: string;
  PIN_AUDIT_WEBHOOK_URL: string;
  PIN_VALUE: string;
  PIN_SIGNING_SECRET: string;
  PIN_AUDIT_WEBHOOK_SECRET: string;
  /** Shared secret with the origin API; injected as X-Origin-Token. */
  ORIGIN_SHARED_TOKEN: string;
  PIN_AUDIT_KV: KVNamespace;
};

export default {
  async fetch(req: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    const url = new URL(req.url);

    if (url.pathname === '/__pin/submit' && req.method === 'POST') {
      return handlePinSubmit(req, env, ctx);
    }
    if (url.pathname === '/__pin/logout') {
      return new Response(null, {
        status: 303,
        headers: {
          location: '/',
          'set-cookie': `${COOKIE_NAME}=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=Lax`,
        },
      });
    }

    const cookieHeader = req.headers.get('cookie') ?? '';
    const cookieVal = parseCookie(cookieHeader, COOKIE_NAME);
    if (cookieVal && (await verifyCookie(env.PIN_SIGNING_SECRET, cookieVal))) {
      return proxyToOrigin(req, env);
    }
    // Anything else: show the PIN form (200 to keep the URL clean).
    return new Response(renderPinForm(), {
      status: 200,
      headers: {
        'content-type': 'text/html; charset=utf-8',
        'cache-control': 'no-store',
        'x-robots-tag': 'noindex,nofollow',
      },
    });
  },
} satisfies ExportedHandler<Env>;

async function handlePinSubmit(req: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
  const ip = req.headers.get('cf-connecting-ip') ?? '';
  const ua = req.headers.get('user-agent') ?? '';

  // Rate limit by IP
  const rlKey = `pin:attempt:${ip}`;
  const current = Number((await env.PIN_AUDIT_KV.get(rlKey)) ?? 0);
  if (current >= RATE_LIMIT_MAX_ATTEMPTS) {
    ctx.waitUntil(postAudit(env, ip, ua, 'rate_limited'));
    return new Response(renderPinForm({ error: 'Too many attempts. Try again in 15 minutes.' }), {
      status: 429,
      headers: { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store' },
    });
  }

  const form = await req.formData().catch(() => null);
  const submitted = String(form?.get('pin') ?? '');

  if (!submitted || !constantTimeEquals(submitted, env.PIN_VALUE)) {
    await env.PIN_AUDIT_KV.put(rlKey, String(current + 1), {
      expirationTtl: RATE_LIMIT_WINDOW_SEC,
    });
    ctx.waitUntil(postAudit(env, ip, ua, 'failure'));
    return new Response(renderPinForm({ error: 'Incorrect PIN.' }), {
      status: 401,
      headers: { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store' },
    });
  }

  // Success
  await env.PIN_AUDIT_KV.delete(rlKey);
  const cookie = await signCookie(env.PIN_SIGNING_SECRET, COOKIE_TTL_SEC);
  ctx.waitUntil(postAudit(env, ip, ua, 'success'));
  return new Response(null, {
    status: 303,
    headers: {
      location: '/',
      'set-cookie': `${COOKIE_NAME}=${cookie}; Path=/; Max-Age=${COOKIE_TTL_SEC}; HttpOnly; Secure; SameSite=Lax`,
    },
  });
}

async function proxyToOrigin(req: Request, env: Env): Promise<Response> {
  const url = new URL(req.url);
  const target = new URL(env.ORIGIN_URL);
  target.pathname = url.pathname;
  target.search = url.search;

  const forwarded = new Request(target.toString(), req);
  forwarded.headers.set('x-forwarded-host', url.hostname);
  forwarded.headers.set('x-forwarded-proto', 'https');
  // Shared secret so the origin API can distinguish gated traffic from
  // anyone who guesses the un-gated vacation-origin.mbfdhub.com hostname.
  forwarded.headers.set('x-origin-token', env.ORIGIN_SHARED_TOKEN);
  return fetch(forwarded);
}

async function postAudit(env: Env, ip: string, ua: string, outcome: 'success' | 'failure' | 'rate_limited'): Promise<void> {
  try {
    await fetch(env.PIN_AUDIT_WEBHOOK_URL, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        authorization: `Bearer ${env.PIN_AUDIT_WEBHOOK_SECRET}`,
      },
      body: JSON.stringify({ ip, userAgent: ua, outcome }),
    });
  } catch {
    /* best-effort */
  }
}

function parseCookie(header: string, name: string): string | null {
  for (const part of header.split(';')) {
    const [k, ...rest] = part.trim().split('=');
    if (k === name) return decodeURIComponent(rest.join('='));
  }
  return null;
}
