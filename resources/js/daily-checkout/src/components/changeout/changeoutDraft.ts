import type { ChangeoutContext, ChangeoutDraft } from './types';

/**
 * The member's changeout on this phone, one per truck and member. localStorage writes are synchronous, so a closed
 * app or an interruption loses nothing. Every access is guarded: storage can be blocked or full, and the wizard then
 * says so instead of failing. Drafts live across a 24-on, 48-off rotation, then expire. With no known member nothing
 * is stored at all: station phones are shared, so a draft must never be readable by whoever signs in next.
 */
const DRAFT_PREFIX = 'mbfd_changeout_draft_';
const CONTEXT_PREFIX = 'mbfd_changeout_context_';
export const CHANGEOUT_DRAFT_MAX_AGE_MS = 4 * 24 * 60 * 60 * 1000;

const owner = (apparatusId: number, userId: number): string => `${apparatusId}_${userId}`;

const isDraft = (value: unknown, apparatusId: number, userId: number): value is ChangeoutDraft => {
  if (typeof value !== 'object' || value === null) return false;
  const draft = value as Partial<ChangeoutDraft>;

  // An older draft has the earlier shape, so it is dropped rather than read as this one.
  return draft.version === 2
    && draft.apparatusId === apparatusId
    && draft.userId === userId
    && typeof draft.clientSubmissionId === 'string' && draft.clientSubmissionId !== ''
    && typeof draft.savedAt === 'number'
    && typeof draft.left === 'object' && draft.left !== null
    && typeof draft.typedVehicle === 'string'
    && typeof draft.note === 'string';
};

const pruneExpired = (now: number): void => {
  const keys = Array.from({ length: localStorage.length }, (_, index) => localStorage.key(index))
    .filter((key): key is string => key !== null && key.startsWith(DRAFT_PREFIX));
  for (const key of keys) {
    let savedAt = Number.NaN;
    try {
      const parsed = JSON.parse(localStorage.getItem(key) ?? 'null') as { savedAt?: unknown } | null;
      savedAt = typeof parsed?.savedAt === 'number' ? parsed.savedAt : Number.NaN;
    } catch {
      // An unreadable copy is removed below.
    }
    if (!Number.isFinite(savedAt) || now - savedAt > CHANGEOUT_DRAFT_MAX_AGE_MS) {
      localStorage.removeItem(key);
      localStorage.removeItem(`${CONTEXT_PREFIX}${key.slice(DRAFT_PREFIX.length)}`);
    }
  }
};

export const loadChangeoutDraft = (apparatusId: number, userId: number | null, now = Date.now()): ChangeoutDraft | null => {
  if (userId === null) return null;
  try {
    pruneExpired(now);
    const parsed: unknown = JSON.parse(localStorage.getItem(`${DRAFT_PREFIX}${owner(apparatusId, userId)}`) ?? 'null');

    return isDraft(parsed, apparatusId, userId) ? parsed : null;
  } catch {
    return null;
  }
};

/** False when the phone would not keep it, so the wizard can say the work is only on the open page. */
export const saveChangeoutDraft = (draft: ChangeoutDraft, now = Date.now()): boolean => {
  if (draft.userId === null) return false;
  try {
    localStorage.setItem(`${DRAFT_PREFIX}${owner(draft.apparatusId, draft.userId)}`, JSON.stringify({ ...draft, savedAt: now }));
    return true;
  } catch {
    return false;
  }
};

export const clearChangeoutDraft = (apparatusId: number, userId: number | null): void => {
  if (userId === null) return;
  try {
    localStorage.removeItem(`${DRAFT_PREFIX}${owner(apparatusId, userId)}`);
    localStorage.removeItem(`${CONTEXT_PREFIX}${owner(apparatusId, userId)}`);
  } catch {
    // Nothing to clear when storage is blocked.
  }
};

/** The list the member worked from, so a reopened draft still shows it on poor signal. */
export const saveContextSnapshot = (apparatusId: number, userId: number | null, context: ChangeoutContext): boolean => {
  if (userId === null) return false;
  try {
    localStorage.setItem(`${CONTEXT_PREFIX}${owner(apparatusId, userId)}`, JSON.stringify(context));
    return true;
  } catch {
    return false;
  }
};

export const loadContextSnapshot = (apparatusId: number, userId: number | null): ChangeoutContext | null => {
  if (userId === null) return null;
  try {
    const parsed = JSON.parse(localStorage.getItem(`${CONTEXT_PREFIX}${owner(apparatusId, userId)}`) ?? 'null') as ChangeoutContext | null;

    // The route's truck and the unit's truck can differ once an alert has moved on, so only the shape is checked.
    return Number.isInteger(parsed?.unit?.apparatus_id) ? parsed : null;
  } catch {
    return null;
  }
};
