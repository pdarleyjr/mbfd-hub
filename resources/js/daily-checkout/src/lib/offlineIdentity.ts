import { db } from './db';

const CACHE_KEY = 'authenticated-offline-identity-v1';

export interface OfflineIdentity {
  userId: number;
  securityVersion: number;
}

interface MemberContextResponse {
  identity?: { user_id?: unknown };
  offline?: { security_version?: unknown };
}

const parseIdentity = (value: unknown): OfflineIdentity | null => {
  if (!value || typeof value !== 'object') return null;
  const context = value as MemberContextResponse;
  const userId = context.identity?.user_id;
  const securityVersion = context.offline?.security_version;

  return Number.isInteger(userId) && Number.isInteger(securityVersion)
    ? { userId: userId as number, securityVersion: securityVersion as number }
    : null;
};

export async function refreshOfflineIdentity(): Promise<OfflineIdentity> {
  const response = await fetch('/api/me/context', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  if (!response.ok) {
    throw new Error('The signed-in member could not be verified for offline synchronization.');
  }

  const identity = parseIdentity(await response.json());
  if (!identity) {
    throw new Error('The signed-in member context is incomplete for offline synchronization.');
  }

  await db.cachedData.put({ key: CACHE_KEY, data: identity, updatedAt: new Date() });
  return identity;
}

export async function identityForQueueCapture(): Promise<OfflineIdentity> {
  if (typeof navigator === 'undefined' || navigator.onLine) {
    try {
      return await refreshOfflineIdentity();
    } catch {
      // A recent canonical context may still allow an offline capture. Replay
      // always refreshes the server context before sending anything.
    }
  }

  const cached = await db.cachedData.get(CACHE_KEY);
  const identity = cached?.data as Partial<OfflineIdentity> | undefined;
  if (Number.isInteger(identity?.userId) && Number.isInteger(identity?.securityVersion)) {
    return identity as OfflineIdentity;
  }

  throw new Error('Sign in online once before saving operational work for offline synchronization.');
}
