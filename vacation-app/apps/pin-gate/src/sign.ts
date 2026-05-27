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
 * Timing-safe string equality.
 *
 * Walks both strings to a fixed comparison length (>=16) so an attacker
 * cannot infer the secret's length from response time. The XOR of the
 * length difference also poisons `diff` so unequal-length inputs reliably
 * return false in the same number of operations.
 */
export function constantTimeEquals(a: string, b: string): boolean {
  const max = Math.max(a.length, b.length, 16);
  let diff = a.length ^ b.length;
  for (let i = 0; i < max; i++) {
    const ac = i < a.length ? a.charCodeAt(i) : 0;
    const bc = i < b.length ? b.charCodeAt(i) : 0;
    diff |= ac ^ bc;
  }
  return diff === 0;
}
