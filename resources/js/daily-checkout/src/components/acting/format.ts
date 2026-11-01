import type { Tone } from '../ui/status';
import type { DutyRole, OnDuty } from './types';
import { HUB_TIMEZONE } from '../../../../shared/timezone.ts';

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
    : date.toLocaleString('en-US', { timeZone: HUB_TIMEZONE, month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
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

/** The 300 (Division Chief) line shows rank. */
export const COMMAND_300_DUTY: DutyRole = { label: '300', withRank: true };

/** "9:14 AM" in Miami Beach time. Some runtimes put a narrow space before AM; members read a plain one. */
function clockText(iso: string | null | undefined): string | null {
  if (!iso) return null;
  const date = new Date(iso);
  return Number.isNaN(date.getTime())
    ? null
    : date.toLocaleTimeString('en-US', { timeZone: HUB_TIMEZONE, hour: 'numeric', minute: '2-digit' }).replace(/ /g, ' ');
}

/** The line when nobody is resolved: "Shift 300 not set". Never a guess. */
export const dutyUnset = (role: Pick<DutyRole, 'label'>): string => `Shift ${role.label} not set`;

/** The roster disagrees with itself right now, so the line says so instead of naming anyone. */
const rosterNeedsReview = (onDuty: OnDuty): boolean => onDuty.reason === 'conflict' || onDuty.reason === 'needs_review';

/**
 * The line at the top of a page and on a station-list card: "A Shift Captain 5: Oscar Lima". A role that
 * shows rank reads "A Shift 300: Division Chief Jane Smith". The name is whatever the server resolved;
 * nothing here knows a person.
 */
export function dutyLine(onDuty: OnDuty, role: DutyRole): string {
  const shift = onDuty.shift ? `${onDuty.shift} Shift ` : '';
  if (rosterNeedsReview(onDuty)) return `${shift}${role.label}: roster needs review`;
  if (!onDuty.resolved || !onDuty.name) return dutyUnset(role);
  const who = role.withRank && onDuty.rank ? `${onDuty.rank} ${onDuty.name}` : onDuty.name;
  return `${shift}${role.label}: ${who}`;
}

const SHIFT_TONE: Record<string, Tone> = { A: 'success', B: 'info', C: 'danger' };

/** A is green, B is blue, C is red. The text always names the shift, so colour never carries it alone. */
export function dutyTone(onDuty: OnDuty): Tone {
  if (rosterNeedsReview(onDuty)) return 'warning';
  if (!onDuty.resolved || !onDuty.name || !onDuty.shift) return 'neutral';
  return SHIFT_TONE[onDuty.shift] ?? 'neutral';
}

export interface DutyNoteContent {
  text: string;
  /** The text points members at the Daily Roster, so it links there where a link is allowed. */
  pointsToRoster: boolean;
}

/**
 * The small line under the shift line, saying where the name comes from. A server that does not read
 * the roster (no `freshness`, `reason` or `telestaff` source) gets no line.
 */
export function dutyNote(onDuty: OnDuty): DutyNoteContent | null {
  const plain = (text: string): DutyNoteContent => ({ text, pointsToRoster: false });
  if (rosterNeedsReview(onDuty) || onDuty.reason === 'no_working_row') return { text: 'Check the Daily Roster.', pointsToRoster: true };

  const named = onDuty.resolved && Boolean(onDuty.name);
  if (onDuty.source === 'telestaff' && named) {
    if (onDuty.identity === 'unlinked') return plain('TeleStaff roster. Not matched to a Hub member.');
    const updated = clockText(onDuty.as_of);
    return plain(updated ? `TeleStaff roster, updated ${updated}` : 'TeleStaff roster');
  }

  const seat = named ? 'Bid seat. ' : '';
  if (onDuty.reason === 'section_missing') return plain(`${seat}Not listed on today's roster.`);
  if (onDuty.freshness === 'awaiting') return plain(`${seat}Today's roster has not arrived.`);
  if (onDuty.freshness === 'delayed') return plain(`${seat}Today's roster is late.`);
  return null;
}
