import { describe, expect, it } from 'vitest';
import { signCookie, verifyCookie, constantTimeEquals } from '../../../apps/pin-gate/src/sign.js';

describe('signCookie / verifyCookie', () => {
  it('round-trips a valid cookie', async () => {
    const secret = 'a'.repeat(32);
    const c = await signCookie(secret, 60);
    const ok = await verifyCookie(secret, c);
    expect(ok).toBe(true);
  });

  it('rejects a cookie signed with a different secret', async () => {
    const c = await signCookie('a'.repeat(32), 60);
    const ok = await verifyCookie('b'.repeat(32), c);
    expect(ok).toBe(false);
  });

  it('rejects an expired cookie', async () => {
    const c = await signCookie('a'.repeat(32), -1);
    const ok = await verifyCookie('a'.repeat(32), c);
    expect(ok).toBe(false);
  });

  it('rejects a malformed cookie', async () => {
    expect(await verifyCookie('a'.repeat(32), 'garbage')).toBe(false);
    expect(await verifyCookie('a'.repeat(32), 'a.b')).toBe(false);
  });
});

describe('constantTimeEquals', () => {
  it('matches identical strings', () => {
    expect(constantTimeEquals('hello', 'hello')).toBe(true);
  });
  it('rejects different strings of same length', () => {
    expect(constantTimeEquals('hello', 'world')).toBe(false);
  });
  it('rejects different lengths fast', () => {
    expect(constantTimeEquals('a', 'ab')).toBe(false);
  });
});
