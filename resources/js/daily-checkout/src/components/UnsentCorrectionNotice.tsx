import { useState } from 'react';
import type { CorrectionMarker, InventoryCorrectionContext } from '../utils/inventoryCorrections';

interface Props {
  marker: CorrectionMarker;
  context: InventoryCorrectionContext;
}

/**
 * A report saved on this device while offline that the server later refused (for example, the checklist changed
 * before it could be sent). Without this it would wait on the device forever: say why, and let the member discard it
 * and report again from the current checklist.
 */
export default function UnsentCorrectionNotice({ marker, context }: Props) {
  const [confirming, setConfirming] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  if (marker.queueId === undefined) return null;
  const queueId = marker.queueId;

  const discard = async () => {
    const removed = await context.onDiscardUnsent?.(queueId).catch(() => false);
    setConfirming(false);
    if (!removed) setMessage('The saved report could not be discarded. It is still on this device.');
  };

  return <div className="ic-unsent" data-unsent-correction={queueId}>
    <span className="hub-tag hub-tag--warning">Report not sent</span>
    <p>Logistics did not receive the inventory report saved on this device{marker.error ? `: ${marker.error}` : '.'} Nothing was rejected. Discard it, then report again if the checklist is still wrong.</p>
    {message && <p role="alert">{message}</p>}
    {!confirming
      ? <button type="button" className="hub-btn hub-btn--tertiary" onClick={() => setConfirming(true)}>Discard saved report…</button>
      : <div role="group" aria-label="Confirm discard" className="ic-actions">
        <button type="button" className="hub-btn hub-btn--destructive" onClick={() => { void discard(); }}>Discard permanently</button>
        <button type="button" className="hub-btn hub-btn--tertiary" onClick={() => setConfirming(false)}>Keep it</button>
      </div>}
  </div>;
}
