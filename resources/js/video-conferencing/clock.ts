export { ServerClock } from '../shared/server-clock.ts';

export function formatConferenceTime(iso: string, timeZone: string): string {
    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? 'the scheduled time'
        : date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone });
}
