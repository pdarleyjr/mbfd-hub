import { useState } from 'react';
import { BTN, ConfirmStep, useWrite } from '../shared';
import { discardCountForm } from './api';

/** Throwing the count away is tucked behind a disclosure and then asks once more, in place. */
export default function DiscardCount({ countId, onDiscarded }: { countId: string; onDiscarded: () => void }) {
  const [asking, setAsking] = useState(false);
  const discard = useWrite();
  return (
    <details className="rounded-hub-control border border-hub-border bg-hub-n-25 px-3" data-testid="discard-details">
      <summary className="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-hub-n-600">More options</summary>
      <div className="pb-3">
        {!asking && <button type="button" className={`${BTN} hub-btn--tertiary !text-hub-danger`} onClick={() => setAsking(true)}>Discard this count</button>}
        {asking && (
          <ConfirmStep testId="discard-confirm" title="Discard this count?" confirmLabel="Discard this count" busy={discard.busy} error={discard.error} retry={discard.retry}
            body={<p>Every number typed into this count is thrown away, for everyone. The inventory keeps its current numbers.</p>}
            onCancel={() => setAsking(false)}
            onConfirm={() => void discard.run(`discard:${countId}`, (key) => discardCountForm(countId, key), { onDone: onDiscarded })} />
        )}
      </div>
    </details>
  );
}
