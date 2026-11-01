import { CheckCircle2 } from 'lucide-react';
import { BTN_PRIMARY } from '../shared';
import { etDateTime } from '../logic';
import type { SubmitResult } from './types';

const items = (count: number): string => `${count} ${count === 1 ? 'item' : 'items'}`;

/** The confirmation after a submit: who, when, and the three numbers that matter. */
export default function CountDone({ result, onExit }: { result: SubmitResult; onExit: () => void }) {
  return (
    <section className="space-y-4" aria-labelledby="count-done-title" data-testid="count-done">
      <div role="status" className="hub-callout space-y-3 p-4">
        <div className="flex items-start gap-3">
          <CheckCircle2 className="mt-0.5 h-6 w-6 flex-none text-hub-success" aria-hidden="true" />
          <div className="min-w-0">
            <h2 id="count-done-title" className="font-heading text-lg font-bold text-hub-ink">Inventory submitted</h2>
            <p className="text-sm text-hub-ink">
              Submitted {etDateTime(result.finalized_at)}{result.finalized_name ? ` by ${result.finalized_name}` : ''}.
            </p>
          </div>
        </div>
        <dl className="grid gap-2 text-sm sm:grid-cols-3">
          <div><dt className="text-[13px] text-hub-n-600">Counted</dt><dd className="font-bold text-hub-ink" data-testid="done-counted">{items(result.counted_items)}</dd></div>
          <div><dt className="text-[13px] text-hub-n-600">Not counted</dt><dd className="font-bold text-hub-ink" data-testid="done-uncounted">{items(result.uncounted_items)}</dd></div>
          <div><dt className="text-[13px] text-hub-n-600">To order</dt><dd className="font-bold text-hub-ink" data-testid="done-orders">{items(result.order_items)}</dd></div>
        </dl>
        {result.uncounted_items > 0 && <p className="text-sm text-hub-n-600">Items not counted keep their current numbers.</p>}
      </div>
      <button type="button" className={`${BTN_PRIMARY} !min-h-12 w-full text-base sm:w-auto`} data-testid="back-to-inventory" onClick={onExit}>Back to inventory</button>
    </section>
  );
}
