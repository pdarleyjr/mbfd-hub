import { useCallback, useEffect, useMemo, useState } from 'react';
import type { PendingInventoryCorrection } from '../types';
import { discardUnsentCorrection, readQueuedCorrectionMarkers, serverMarkers, type CorrectionMarker } from '../utils/inventoryCorrections';
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
    let previous: CorrectionMarker[] = [];
    const load = () => {
      void readQueuedCorrectionMarkers(apparatusId, ownerUserId).then(markers => {
        if (cancelled) return;
        // A pending saved report that left the queue was sent: keep its marker for this session (the server
        // list is only refreshed with the checklist). A refused report stays in the queue as `unsent`.
        const ids = new Set(markers.map(marker => marker.queueId));
        const sent = previous.filter(marker => marker.kind === 'pending' && !ids.has(marker.queueId));
        if (sent.length > 0) setRecorded(current => [...current, ...sent.map(({ queueId: _queueId, ...marker }) => marker)]);
        previous = markers;
        setQueued(markers);
      });
    };
    load();
    const stopListening = onDailyCheckoutQueueChanged(load);

    return () => {
      cancelled = true;
      stopListening();
    };
  }, [apparatusId, ownerUserId]);

  // A fresh server list already contains what this session sent: drop the session copies so nothing counts twice.
  useEffect(() => { setRecorded([]); }, [serverPending]);

  const markers = useMemo(() => [...serverMarkers(serverPending), ...queued, ...recorded], [serverPending, queued, recorded]);
  const record = useCallback((marker: CorrectionMarker) => setRecorded(previous => [...previous, marker]), []);

  // A report the server refused after it was saved offline: the member's own, on this apparatus only.
  const discardUnsent = useCallback(async (queueId: number): Promise<boolean> => (
    apparatusId !== undefined && ownerUserId !== undefined ? discardUnsentCorrection(apparatusId, ownerUserId, queueId) : false
  ), [apparatusId, ownerUserId]);

  return { markers, record, discardUnsent };
}
