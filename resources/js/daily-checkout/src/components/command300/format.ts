import type { AttentionLevel, BoardCrewMember, Presence } from './types';
import type { Tone } from '../ui/status';

export const SECONDARY = 'text-[13px] leading-5 text-hub-n-600';

/** "just now", "12 min ago", "3 h ago", "2 d ago". Empty for a missing or unreadable time. */
export function ageLabel(iso: string | null | undefined, now: number = Date.now()): string {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '';
  const minutes = Math.max(0, Math.round((now - then) / 60_000));
  if (minutes < 1) return 'just now';
  if (minutes < 60) return `${minutes} min ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 48) return `${hours} h ago`;
  return `${Math.round(hours / 24)} d ago`;
}

export const ATTENTION: Record<AttentionLevel, { tone: Tone; label: string }> = {
  ok: { tone: 'success', label: 'On track' },
  watch: { tone: 'warning', label: 'Watch' },
  attention: { tone: 'danger', label: 'Needs attention' },
};

export const PRESENCE_LABEL: Record<Presence | 'vacant', string> = {
  working: 'Working',
  away: 'Away',
  unknown: 'Status unclear',
  vacant: 'Vacant',
};

export const PRESENCE_TONE: Record<Presence | 'vacant', Tone> = {
  working: 'success',
  away: 'warning',
  unknown: 'neutral',
  vacant: 'neutral',
};

/** "Lt. Smith" style short names are not available; the roster prints "LAST, FIRST". Show it as "First Last". */
export function readableName(name: string): string {
  const [last, first] = name.split(',').map((part) => part.trim());
  const titled = (value: string) => value.toLowerCase().replace(/(^|[\s'-])([a-z])/g, (_, lead: string, letter: string) => lead + letter.toUpperCase());
  return first ? `${titled(first)} ${titled(last)}` : titled(name);
}

/** The first few names of members working, then "+N". Away members are left to the detail. */
export function crewLine(members: BoardCrewMember[], limit = 3): string {
  const working = members.filter((member) => member.presence !== 'away');
  const shown = working.slice(0, limit).map((member) => readableName(member.name));
  const more = working.length - shown.length;
  return shown.join(', ') + (more > 0 ? ` +${more} more` : '');
}
