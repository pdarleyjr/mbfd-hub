/** Server-rendered civil timezone; the fallback also supports cached/offline pages. */
export const HUB_TIMEZONE = (typeof document === 'undefined'
  ? null
  : document.querySelector<HTMLMetaElement>('meta[name="hub-timezone"]')?.content) || 'America/New_York';

/** Calendar-only values are dates, not midnight UTC instants. */
export function formatHubDate(value: string): string {
  return new Date(value).toLocaleDateString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric',
    timeZone: /^\d{4}-\d{2}-\d{2}$/.test(value) ? 'UTC' : HUB_TIMEZONE,
  });
}

/** Values for editable calendar and datetime-local inputs, interpreted by the server as Eastern. */
export function hubDateInput(value: Date, includeTime = false): string {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: HUB_TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit',
    ...(includeTime ? { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' as const } : {}),
  }).formatToParts(value);
  const part = (type: Intl.DateTimeFormatPartTypes): string => parts.find((entry) => entry.type === type)?.value ?? '';
  const date = `${part('year')}-${part('month')}-${part('day')}`;
  return includeTime ? `${date}T${part('hour')}:${part('minute')}` : date;
}
