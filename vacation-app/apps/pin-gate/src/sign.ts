/**
 * HMAC-SHA256 cookie signing. Uses Web Crypto so it runs unchanged inside
 * the Cloudflare Worker.
 *
 * Cookie format: `${nonceHex}.${expiryEpochSec}.${hmacHex}`.
 */

const encoder = new TextEncoder();

async function importKey(secret: string): Promise<CryptoKey> {
  return crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign', 'verify'],
  );
}

function toHex(buf: ArrayBuffer): string {
  const bytes = new Uint8Array(buf);
  return [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function fromHex(hex: string): Uint8Array {
  const len = hex.length / 2;
  const out = new Uint8Array(len);
  for (let i = 0; i < len; i++) {
    out[i] = parseInt(hex.substr(i * 2, 2), 16);
  }
  return out;
}

export async function signCookie(secret: string, ttlSec: number): Promise<string> {
  const key = await importKey(secret);
  const nonce = crypto.getRandomValues(new Uint8Array(16));
  const expiry = Math.floor(Date.now() / 1000) + ttlSec;
  const nonceHex = toHex(nonce.buffer as ArrayBuffer);
  const payload = `${nonceHex}.${expiry}`;
  const sig = await crypto.subtle.sign('HMAC', key, encoder.encode(payload));
  return `${payload}.${toHex(sig)}`;
}

export async function verifyCookie(secret: string, cookie: string): Promise<boolean> {
  const parts = cookie.split('.');
  if (parts.length !== 3) return false;
  const [nonceHex, expiryStr, sigHex] = parts;
  if (!nonceHex || !expiryStr || !sigHex) return false;
  const expiry = Number(expiryStr);
  if (!Number.isFinite(expiry)) return false;
  if (expiry < Math.floor(Date.now() / 1000)) return false;
  const key = await importKey(secret);
  const ok = await crypto.subtle.verify(
    'HMAC',
    key,
    fromHex(sigHex).buffer as ArrayBuffer,
    encoder.encode(`${nonceHex}.${expiry}`),
  );
  return ok;
}

/**
 * Timing-safe string equality for the PIN comparison.
 */
export function constantTimeEquals(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) {
    diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return diff === 0;
}
