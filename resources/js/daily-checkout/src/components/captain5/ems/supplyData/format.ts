import type { Basis, Direction, Headline, PeriodInfo, StockStatus, TrendBucket } from './types.ts';

/** Words and numbers for the Supply Data screen. Pure: no React. `null` is "cannot be determined"; `0` is a real zero. */

export const NOT_ENOUGH_DATA = 'Not enough data';
/** The contract's wording; detail answers carry no label of their own, so they show this exact sentence. */
export const DISTRIBUTED_LABEL = 'Distributed from the supply room — not patient use';

/** One decimal at most, none when whole. */
export function formatNumber(value: number): string {
  const rounded = Math.round(value * 10) / 10;
  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
}

export const plural = (count: number, one: string, many = `${one}s`): string => (count === 1 ? one : many);

/** "box" becomes "boxes"; "each" reads as nothing because "14" already means 14 each. */
export function unitWord(unit: string | null | undefined, quantity: number): string {
  const word = (unit ?? '').trim().toLowerCase();
  if (word === '' || word === 'each') return '';
  if (quantity === 1) return word;
  return /(x|s|ch|sh)$/.test(word) ? `${word}es` : `${word}s`;
}

export function quantityText(quantity: number, unit: string | null | undefined): string {
  const word = unitWord(unit, quantity);
  return word === '' ? formatNumber(quantity) : `${formatNumber(quantity)} ${word}`;
}

export function daysOfSupplyText(days: number): string {
  if (days < 1) return 'less than a day';
  const whole = Math.round(days);
  return `${whole} ${plural(whole, 'day')}`;
}

export function hoursText(hours: number): string {
  if (hours < 1) return 'under an hour';
  if (hours < 48) return `${formatNumber(hours)} ${plural(Math.round(hours), 'hour')}`;
  const days = Math.round((hours / 24) * 10) / 10;
  return `${formatNumber(days)} days`;
}

// ------------------------------------------------------------------ dates

const dateParts = (ymd: string): Date | null => {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd);
  return match ? new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))) : null;
};

/** "Oct 5" from a calendar date, with no timezone shift. */
export function shortDate(ymd: string): string {
  const date = dateParts(ymd);
  return date ? new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', month: 'short', day: 'numeric' }).format(date) : ymd;
}

export function longDate(ymd: string): string {
  const date = dateParts(ymd);
  return date ? new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', month: 'short', day: 'numeric', year: 'numeric' }).format(date) : ymd;
}

export function periodText(period: PeriodInfo): string {
  return `${shortDate(period.from)} to ${longDate(period.to)}`;
}

export function previousPeriodText(period: PeriodInfo): string {
  return `${shortDate(period.previous.from)} to ${longDate(period.previous.to)}`;
}

// ------------------------------------------------------------------ trend buckets

export const isMonthly = (trend: TrendBucket[]): boolean => trend.length > 0 && trend[0].month_start !== undefined;

export interface BucketLabel { short: string; long: string }

export function bucketLabel(bucket: TrendBucket, monthly: boolean): BucketLabel {
  const start = (monthly ? bucket.month_start : bucket.week_start) ?? '';
  const date = dateParts(start);
  if (!date) return { short: start, long: start };
  if (monthly) {
    return {
      short: new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', month: 'short' }).format(date),
      long: new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', month: 'long', year: 'numeric' }).format(date),
    };
  }
  return { short: shortDate(start), long: `Week of ${shortDate(start)}` };
}

// ------------------------------------------------------------------ balances and status

export const BASIS_LABEL: Record<Basis, string> = {
  counted: 'Counted',
  estimate: 'Estimated',
  calculated: 'Calculated',
  none: 'Not counted',
};

export const BASIS_EXPLANATION: Record<Basis, string> = {
  counted: 'From a physical count of the shelf, less what has left since.',
  estimate: 'A best-guess starting number that has not been counted yet. It is not a verified count.',
  calculated: 'Worked out from the last counted number and the movements since.',
  none: 'This supply has never been counted.',
};

export const STATUS_LABEL: Record<StockStatus, string> = {
  zero: 'Zero on hand',
  below_minimum: 'Below minimum',
  ok: 'OK',
  unknown: 'Unknown',
};

export const STATUS_TONE: Record<StockStatus, 'danger' | 'warning' | 'info' | 'success' | 'neutral'> = {
  zero: 'danger',
  below_minimum: 'warning',
  ok: 'success',
  unknown: 'neutral',
};

/** Stock counts as not counted recently when it has no physical count in this many days (the backend's STALE_COUNT_DAYS), or never. */
export const STALE_COUNT_DAYS = 10;

export const DIRECTION_LABEL: Record<Direction, string> = {
  up: 'More than the period before',
  down: 'Less than the period before',
  flat: 'Same as the period before',
  new: 'None in the period before',
};

/** The headline comparison in words. A zero previous period gives no percentage, only an honest note. */
export function changeText(headline: Headline): { text: string; tone: 'up' | 'down' | 'flat' | 'none' } {
  return changeSentence(headline.change_pct, headline.previous_distribution_events);
}

/** The same comparison for any figure: a null percentage or a zero before gives no percentage. */
export function changeSentence(pct: number | null, before: number): { text: string; tone: 'up' | 'down' | 'flat' | 'none' } {
  if (pct === null || before === 0) return { text: 'No earlier activity to compare', tone: 'none' };
  const rounded = Math.round(pct * 10) / 10;
  if (rounded === 0) return { text: `No change from ${before} in the previous period`, tone: 'flat' };
  const word = rounded > 0 ? 'Up' : 'Down';
  return { text: `${word} ${formatNumber(Math.abs(rounded))}% from ${before} in the previous period`, tone: rounded > 0 ? 'up' : 'down' };
}

/** A share bar width, kept inside 0 to 100. */
export const clampPercent = (value: number | null | undefined): number => (typeof value === 'number' && Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0);
