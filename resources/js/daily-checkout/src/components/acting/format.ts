import type { Tone } from '../ui/status';
import type { ActingRole, OnDuty } from './types';

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/** "5 min ago", "3 h ago", "2 d ago": coarse on purpose, with the exact time in a title/aria-label. */
export function ageText(iso: string | null | undefined, now = Date.now()): string {
  if (!iso) return 'Unknown time';
  const elapsed = now - new Date(iso).getTime();
  if (!Number.isFinite(elapsed)) return 'Unknown time';
  if (elapsed < MINUTE) return 'just now';
  if (elapsed < HOUR) return `${Math.floor(elapsed / MINUTE)} min ago`;
  if (elapsed < DAY) return `${Math.floor(elapsed / HOUR)} h ago`;
  return `${Math.floor(elapsed / DAY)} d ago`;
}

export function dateTimeText(iso: string | null | undefined): string {
  if (!iso) return 'Unknown time';
  const date = new Date(iso);
  return Number.isNaN(date.getTime())
    ? 'Unknown time'
    : date.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

/** Version 4 UUID; crypto.randomUUID needs a secure context, so fall back to getRandomValues. */
export function newKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

type DutyRole = Pick<ActingRole, 'label' | 'withRank'>;

/** The line when nobody is resolved: "Shift 300 not set". Never a guess. */
export const dutyUnset = (role: Pick<ActingRole, 'label'>): string => `Shift ${role.label} not set`;

/**
 * The line at the top of a page and on a station-list card: "A Shift Captain 5: Oscar Lima", or
 * "A Shift Acting Captain 5: Oscar Lima until Oct 24, 8:00 AM" for an approved acting member. A role that
 * shows rank reads "A Shift 300: Division Chief Jane Smith". The name is whatever the server resolved;
 * nothing here knows a person.
 */
export function dutyLine(onDuty: OnDuty, role: DutyRole): string {
  if (!onDuty.resolved || !onDuty.name) return dutyUnset(role);
  const shift = onDuty.shift ? `${onDuty.shift} Shift ` : '';
  const who = role.withRank && onDuty.rank ? `${onDuty.rank} ${onDuty.name}` : onDuty.name;
  if (onDuty.source === 'acting') {
    const until = onDuty.acting_until ? ` until ${dateTimeText(onDuty.acting_until)}` : '';
    return `${shift}Acting ${role.label}: ${who}${until}`;
  }
  return `${shift}${role.label}: ${who}`;
}

const SHIFT_TONE: Record<string, Tone> = { A: 'success', B: 'info', C: 'danger' };

/** A is green, B is blue, C is red. The text always names the shift, so colour never carries it alone. */
export function dutyTone(onDuty: OnDuty): Tone {
  if (!onDuty.resolved || !onDuty.name || !onDuty.shift) return 'neutral';
  return SHIFT_TONE[onDuty.shift] ?? 'neutral';
}
