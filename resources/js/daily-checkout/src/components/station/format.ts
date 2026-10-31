import { HUB_TIMEZONE } from '../../../../shared/timezone.ts';

export const clockTime = (iso: string): string => new Date(iso).toLocaleTimeString('en-US', { timeZone: HUB_TIMEZONE, hour: 'numeric', minute: '2-digit' });

export const dayAndTime = (iso: string): string => new Date(iso).toLocaleString('en-US', { timeZone: HUB_TIMEZONE, weekday: 'short', hour: 'numeric', minute: '2-digit' });

export const unitLabel = (unit: { vehicle_number?: string | null; type?: string | null }): string => [unit.vehicle_number ? `#${unit.vehicle_number}` : null, unit.type].filter(Boolean).join(' · ');
