import type { StationRequestType } from '../types';

const ALLOWED_RETURN_PATH = /^\/(stations(?:\/\d+(?:\/rooms\/\d+)?)?|forms-hub)(?:[/?#]|$)/;

export function safeReturnTo(value: string | null, fallback = '/forms-hub'): string {
  if (!value || !ALLOWED_RETURN_PATH.test(value) || value.startsWith('//') || value.includes('\\')) {
    return fallback;
  }
  return value;
}

export function buildStationRequestPath(search: string, forcedType?: StationRequestType): string {
  const source = new URLSearchParams(search);
  const target = new URLSearchParams();
  const rawStationId = source.get('station_id') ?? source.get('station');
  if (rawStationId && /^\d+$/.test(rawStationId) && Number(rawStationId) > 0) {
    target.set('station_id', rawStationId);
  }
  const requestType = forcedType ?? source.get('type');
  if (requestType === 'repair_service' || requestType === 'equipment') {
    target.set('type', requestType);
  }
  const returnTo = source.get('return_to');
  if (returnTo) target.set('return_to', safeReturnTo(returnTo));

  const query = target.toString();
  return `/forms-hub/station-request${query ? `?${query}` : ''}`;
}
