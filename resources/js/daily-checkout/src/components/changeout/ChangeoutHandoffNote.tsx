import { useId } from 'react';
import type { ChangeoutHandoff } from './types';

/** So the crew does not report equipment as missing when the last changeout deliberately left it on the old truck. */
export default function ChangeoutHandoffNote({ handoff }: { handoff: ChangeoutHandoff | undefined }) {
  const headingId = useId();
  if (!handoff || handoff.left_behind.length === 0) return null;
  const truck = handoff.from_vehicle_number ? `truck ${handoff.from_vehicle_number}` : 'the old truck';

  return (
    <section aria-labelledby={headingId} className="mb-4 rounded-xl border border-hub-border bg-hub-surface-muted p-3 text-sm text-hub-ink-secondary">
      <h2 id={headingId} className="font-semibold text-hub-ink">Left on {truck} at the last changeout</h2>
      <ul className="mt-1 space-y-0.5">
        {handoff.left_behind.map((line) => <li key={line.name}>{line.name}{line.note ? `: ${line.note}` : ''}</li>)}
      </ul>
    </section>
  );
}
