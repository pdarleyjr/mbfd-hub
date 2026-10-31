import { useEffect, useState } from 'react';
import type { StationCheckoutDetail, StationCheckoutRow } from '../../types';
import { ApiClient, ApiRequestError, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { formatHubDate } from '../../../../shared/timezone.ts';
import { Sheet } from '../captain5/ui';
import Tag from '../ui/Tag';
import { LoadError } from '../ui/StateViews';
import type { Tone } from '../ui/status';
import { clockTime, unitLabel } from './format';

const ITEM_TONE: Record<string, Tone> = { present: 'success', missing: 'danger', damaged: 'danger' };
const needsAttention = (status: string): boolean => (ITEM_TONE[status.toLowerCase()] ?? 'neutral') === 'danger';

/** The whole of one submitted checkout, read only. Compartments with a problem start open; the rest open on a tap. */
export default function CheckoutSheet({ stationId, row, onClose, returnFocusTo }: { stationId: number; row: StationCheckoutRow | null; onClose: () => void; returnFocusTo: () => HTMLElement | null }) {
  const [detail, setDetail] = useState<StationCheckoutDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [unavailable, setUnavailable] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const checkoutId = row?.id ?? null;

  useEffect(() => {
    setDetail(null);
    setError(null);
    setUnavailable(false);
    if (checkoutId === null) return undefined;
    let active = true;
    ApiClient.getStationCheckout(stationId, checkoutId)
      .then((loaded) => { if (active) setDetail(loaded); })
      .catch((reason: unknown) => {
        if (!active) return;
        if (isApiAuthenticationError(reason)) { redirectToLoginAfterSessionExpiry(); return; }
        if (reason instanceof ApiRequestError && reason.status === 404) { setUnavailable(true); return; }
        setError(reason instanceof Error ? reason.message : 'The checkout could not be loaded.');
      });
    return () => { active = false; };
  }, [stationId, checkoutId, attempt]);

  const issues = detail?.compartments.reduce((total, compartment) => total + compartment.items.filter((item) => needsAttention(item.status)).length, 0) ?? 0;
  const itemCount = detail?.compartments.reduce((total, compartment) => total + compartment.items.length, 0) ?? 0;
  const label = row ? unitLabel(row) : '';

  return (
    <Sheet open={row !== null} onClose={onClose} returnFocusTo={returnFocusTo} testId="station-checkout-sheet" title={row ? `${row.designation} checkout` : 'Checkout'} description={label || undefined}>
      {row && (
        <dl className="mb-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
          <Fact label="Submitted by" value={row.submitted_by} wide />
          <Fact label="Submitted" value={row.submitted_at ? `${formatHubDate(row.submitted_at)}, ${clockTime(row.submitted_at)}` : null} wide />
          <Fact label="Shift" value={row.shift} />
          <Fact label="Status" value={row.status} />
          {detail && <Fact label="Mileage" value={detail.miles?.toLocaleString('en-US')} />}
          {detail && <Fact label="Engine hours" value={detail.engine_hours} />}
        </dl>
      )}
      {!detail && !error && !unavailable && <div role="status" aria-label="Loading checkout" className="space-y-3"><span className="hub-skeleton block !h-8 w-2/3" /><span className="hub-skeleton block !h-40 w-full" /></div>}
      {unavailable && <p className="hub-callout p-4 text-sm" role="alert">This checkout is no longer available here.</p>}
      {error && <LoadError title="The checkout could not be loaded" message={error} onRetry={() => setAttempt((value) => value + 1)} />}
      {detail && (
        <div className="space-y-2" data-testid="station-checkout-detail">
          <p className="mb-3 text-sm font-semibold tabular-nums text-hub-ink">{itemCount} {itemCount === 1 ? 'item' : 'items'} checked, {issues === 0 ? 'no problems reported' : `${issues} ${issues === 1 ? 'problem' : 'problems'} reported`}</p>
          {detail.compartments.map((compartment, index) => {
            const problems = compartment.items.filter((item) => needsAttention(item.status)).length;
            return (
              <details key={`${compartment.name}-${index}`} open={problems > 0} className="hub-well overflow-hidden">
                <summary className="flex min-h-11 cursor-pointer items-center justify-between gap-3 px-3 py-2 font-semibold text-hub-ink">
                  <span className="min-w-0 break-words">{compartment.name || 'Compartment'}</span>
                  {problems > 0
                    ? <Tag tone="danger">{problems} {problems === 1 ? 'problem' : 'problems'}</Tag>
                    : <span className="flex-none text-[13px] font-medium tabular-nums text-hub-n-600">{compartment.items.length} {compartment.items.length === 1 ? 'item' : 'items'}</span>}
                </summary>
                <ul className="divide-y divide-hub-n-100 border-t border-hub-n-100">
                  {compartment.items.map((item, itemIndex) => (
                    <li key={`${item.name}-${itemIndex}`} className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1 px-3 py-2">
                      <div className="min-w-0 flex-1">
                        <p className="break-words text-sm font-medium text-hub-ink">{item.name}</p>
                        {item.value && <p className="break-words text-[13px] tabular-nums text-hub-n-600">Recorded: {item.value}</p>}
                        {item.notes && <p className="whitespace-pre-wrap break-words text-[13px] text-hub-n-600">{item.notes}</p>}
                        {item.has_photo && <p className="text-[13px] text-hub-n-600">Photo attached</p>}
                      </div>
                      <Tag tone={ITEM_TONE[item.status.toLowerCase()] ?? 'neutral'}>{item.status || 'Not recorded'}</Tag>
                    </li>
                  ))}
                </ul>
              </details>
            );
          })}
          {detail.compartments.length === 0 && <p className="text-sm text-hub-n-600">This checkout has no recorded items.</p>}
        </div>
      )}
    </Sheet>
  );
}

function Fact({ label, value, wide = false }: { label: string; value?: string | number | null; wide?: boolean }) {
  if (value === null || value === undefined || value === '') return null;
  return (
    <div className={`min-w-0 ${wide ? 'col-span-2' : ''}`}>
      <dt className="text-[13px] leading-5 text-hub-n-600">{label}</dt>
      <dd className="break-words font-medium tabular-nums text-hub-ink">{value}</dd>
    </div>
  );
}
