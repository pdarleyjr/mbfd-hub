import { afterEach, describe, expect, it, vi } from 'vitest';
import { submitMemberCorrection } from './memberIssueActionsApi';
import { resolveStationRequest } from './stationRequestResolveApi';

const swOffline = () => new Response(JSON.stringify({ error: 'You are offline. Your submission has been queued.', offline: true }), { status: 503, headers: { 'Content-Type': 'application/json' } });

describe('a POST the service worker could not deliver', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('is an unknown outcome (status 0), not a server fault, for a correction', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(swOffline()));
    await expect(submitMemberCorrection(1, { clientActionId: 'a', outcome: 'found' })).rejects.toMatchObject({ status: 0, isNetwork: true });
  });

  it('is an unknown outcome (status 0) for a station request resolve', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(swOffline()));
    await expect(resolveStationRequest(1, { clientActionId: 'a', outcome: 'fixed' })).rejects.toMatchObject({ status: 0, isNetwork: true });
  });
});
