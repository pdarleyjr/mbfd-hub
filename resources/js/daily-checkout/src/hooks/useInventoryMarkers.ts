import { useCallback, useEffect, useMemo, useState } from 'react';
import type { PendingInventoryCorrection } from '../types';
import { readQueuedCorrectionMarkers, serverMarkers, type CorrectionMarker } from '../utils/inventoryCorrections';
import { onDailyCheckoutQueueChanged } from '../utils/dailyCheckoutSubmissionQueue';

/**
 * Items and compartments that already have a report waiting on Logistics: the server's pending list, reports
 * saved on this device (read again on mount so the marker survives a reload offline), and reports this member
 * sent during this session (the server list is only refreshed when the checklist is loaded again).
 */
export function useInventoryMarkers(apparatusId: number | undefined, ownerUserId: number | undefined, serverPending: PendingInventoryCorrection[] | undefined) {
  const [queued, setQueued] = useState<CorrectionMarker[]>([]);
  const [recorded, setRecorded] = useState<CorrectionMarker[]>([]);

  useEffect(() => {
    setRecorded([]);
    if (apparatusId === undefined || ownerUserId === undefined) {
      setQueued([]);
      return undefined;
    }
    let cancelled = false;
    const load = () => {
      void readQueuedCorrectionMarkers(apparatusId, ownerUserId).then(markers => {
        if (!cancelled) setQueued(markers);
      });
    };
    load();
    const stopListening = onDailyCheckoutQueueChanged(load);

    return () => {
      cancelled = true;
      stopListening();
    };
  }, [apparatusId, ownerUserId]);

  const markers = useMemo(() => [...serverMarkers(serverPending), ...queued, ...recorded], [serverPending, queued, recorded]);
  const record = useCallback((marker: CorrectionMarker) => setRecorded(previous => [...previous, marker]), []);

  return { markers, record };
}
