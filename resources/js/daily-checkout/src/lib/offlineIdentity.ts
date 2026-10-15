import { db } from './db';
import { fetchWithTimeout, READ_TIMEOUT_MS, redirectToLoginAfterSessionExpiry } from '../utils/api';

const CACHE_KEY = 'authenticated-offline-identity-v1';

export interface OfflineIdentity {
  userId: number;
  securityVersion: number;
  personnel?: { employee_profile_id: number; employee_number: string; name: string; rank: string };
  /** Server-derived entry points; absent in identities cached before this field existed. */
  capabilities?: { personnelEquipmentRequest: boolean };
}

interface MemberContextResponse {
  identity?: { user_id?: unknown };
  offline?: { security_version?: unknown };
  personnel?: OfflineIdentity['personnel'];
  authorization?: { capabilities?: { personnel_equipment_request?: unknown } };
}

const parseIdentity = (value: unknown): OfflineIdentity | null => {
  if (!value || typeof value !== 'object') return null;
  const context = value as MemberContextResponse;
  const userId = context.identity?.user_id;
  const securityVersion = context.offline?.security_version;

  return Number.isInteger(userId) && Number.isInteger(securityVersion)
    ? {
        userId: userId as number,
        securityVersion: securityVersion as number,
        personnel: context.personnel,
        capabilities: { personnelEquipmentRequest: context.authorization?.capabilities?.personnel_equipment_request === true },
      }
    : null;
};

/** The member is no longer signed in; saved work stays on this device. */
export class OfflineIdentitySignInRequiredError extends Error {
  constructor() {
    super('Your sign-in expired. Saved work stays on this device; sign in again to send it.');
    this.name = 'OfflineIdentitySignInRequiredError';
  }
}

export interface RefreshOfflineIdentityOptions {
  /**
   * Member-initiated requests may send the member to sign in. Background
   * replays pass false: they must never navigate away from open work.
   */
  redirectOnExpiry?: boolean;
}

export async function refreshOfflineIdentity({ redirectOnExpiry = true }: RefreshOfflineIdentityOptions = {}): Promise<OfflineIdentity> {
  const response = await fetchWithTimeout('/api/me/context', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  }, READ_TIMEOUT_MS);
  if (response.status === 401) {
    if (redirectOnExpiry && (typeof navigator === 'undefined' || navigator.onLine)) {
      redirectToLoginAfterSessionExpiry();
    }
    throw new OfflineIdentitySignInRequiredError();
  }
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

export async function identityForQueueCapture(options: RefreshOfflineIdentityOptions = {}): Promise<OfflineIdentity> {
  if (typeof navigator === 'undefined' || navigator.onLine) {
    try {
      return await refreshOfflineIdentity(options);
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
