import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { LoadError, SkeletonRows } from '../../../ui/StateViews';
import { failureMessage } from '../../../../utils/emsApi';
import { BTN_PRIMARY, BTN_SECONDARY, HINT, LiveNote, useWrite } from '../shared';
import { fetchCountReview, submitCountForm } from './api';
import DiscardCount from './DiscardCount';
import { groupMissing, humanReason, missingNote } from './logic';
import type { CountDraft } from './useCountDraft';
import type { CountReviewData, ReviewMissing, SubmitResult } from './types';

interface Props {
  draft: CountDraft;
  onFix: (item: ReviewMissing) => void;
  onBack: () => void;
  onSubmitted: (result: SubmitResult) => void;
  onDiscarded: () => void;
}

const UNSENT = 'Your numbers are saved on this device but the server does not have them yet. Check your connection, then try again.';

function Section({ title, count, children }: { title: string; count?: number; children: ReactNode }) {
  return (
    <section className="space-y-2" aria-label={title}>
      <h3 className="font-heading text-base font-bold text-hub-ink">{title}{count !== undefined ? ` (${count})` : ''}</h3>
      {children}
    </section>
  );
}

/** Review and submit: what was counted, what was missed (tap to fix), what to order, and one way forward. */
export default function CountReview({ draft, onFix, onBack, onSubmitted, onDiscarded }: Props) {
  const [review, setReview] = useState<CountReviewData | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [version, setVersion] = useState(0);
  const [changedNote, setChangedNote] = useState<string | null>(null);
  const submit = useWrite();
  const { flushNow } = draft;
  const countId = draft.count?.id ?? '';

  const load = useCallback(async () => {
    setReview(null);
    setLoadError(null);
    if (!(await flushNow())) { setLoadError(UNSENT); return; }
    try {
      setReview(await fetchCountReview(countId));
      setVersion((n) => n + 1);
    } catch (failure) {
      setLoadError(failureMessage(failure, 'The review could not be loaded.'));
    }
  }, [countId, flushNow]);

  useEffect(() => { void load(); }, [load]);

  const missing = review?.uncounted ?? [];
  const groups = useMemo(() => groupMissing(missing, draft.sections), [missing, draft.sections]);

  const send = () => {
    if (!review) return;
    const expected = review.uncounted.length;
    void submit.run(`submit:${countId}:${expected}:${version}`, (key) => submitCountForm(countId, expected, key), {
      onDone: (result) => { draft.finish(); onSubmitted(result); },
      onConflict: (error) => {
        if (error.code === 'uncounted_changed') {
          setChangedNote('The list of items not counted changed while you were reviewing. It has been reloaded. Check it again before you submit.');
          void load();
        }
      },
    });
  };

  if (loadError) {
    return <LoadError title="The review is not ready" message={loadError} onRetry={() => void load()}><button type="button" className={BTN_SECONDARY} onClick={onBack}>Back to counting</button></LoadError>;
  }
  if (!review) return <SkeletonRows rows={4} />;

  return (
    <section className="space-y-5" aria-labelledby="count-review-title" data-testid="count-review">
      <div>
        <h2 id="count-review-title" className="font-heading text-lg font-bold text-hub-ink">Review and submit</h2>
        <p className="text-sm text-hub-ink" data-testid="review-counted">{review.counted} {review.counted === 1 ? 'item' : 'items'} counted.</p>
      </div>

      {changedNote && <LiveNote tone="warning" testId="review-changed">{changedNote}</LiveNote>}

      {missing.length > 0 && (
        <Section title="Not counted" count={missing.length}>
          <p className={HINT}>Tap an item to go back and count it.</p>
          {groups.map((group) => (
            <div key={group.code ?? 'none'} data-testid="missing-group">
              <h4 className="text-sm font-semibold text-hub-n-600">{group.label}</h4>
              <ul>
                {group.items.map((item) => (
                  <li key={item.id}>
                    <button type="button" className="flex min-h-11 w-full items-center py-1 text-left font-semibold text-hub-blue-strong underline underline-offset-2" data-testid="missing-item" onClick={() => onFix(item)}>
                      {item.name}
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </Section>
      )}

      <Section title="To order" count={review.orders.length}>
        {review.orders.length === 0 ? <p className="text-sm text-hub-n-600">Nothing needs ordering.</p> : (
          <ul className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border bg-hub-well" data-testid="review-orders">
            {review.orders.map((row) => (
              <li key={row.supply_item_id} className="flex items-start justify-between gap-3 px-3 py-2">
                <span className="min-w-0"><span className="block font-semibold text-hub-ink [overflow-wrap:anywhere]">{row.name}</span><span className="text-[13px] text-hub-n-600">{row.location_code ?? 'No shelf'}</span></span>
                <span className="flex-none font-bold text-hub-danger">Order {row.order}{row.unit_label ? ` ${row.unit_label}` : ''}</span>
              </li>
            ))}
          </ul>
        )}
      </Section>

      {review.config_problems.length > 0 && (
        <Section title="Needs a look" count={review.config_problems.length}>
          <ul className="list-disc space-y-0.5 pl-5 text-sm text-hub-ink" data-testid="review-problems">
            {review.config_problems.map((problem) => <li key={problem.id}>{problem.name}: {humanReason(problem.problem)}</li>)}
          </ul>
        </Section>
      )}

      <div className="space-y-3 border-t border-hub-border pt-4">
        {missing.length === 0 ? (
          <button type="button" className={`${BTN_PRIMARY} !min-h-12 w-full text-base sm:w-auto`} disabled={submit.busy} aria-busy={submit.busy} data-testid="submit-inventory" onClick={send}>
            {submit.busy ? 'Submitting…' : submit.retry ? 'Try again: Submit inventory' : 'Submit inventory'}
          </button>
        ) : (
          <>
            <button type="button" className={`${BTN_PRIMARY} !min-h-12 w-full text-base sm:w-auto`} onClick={onBack} data-testid="keep-counting">Keep counting</button>
            <div className="space-y-1">
              <button type="button" className={`${BTN_SECONDARY} w-full sm:w-auto`} disabled={submit.busy} aria-busy={submit.busy} data-testid="submit-partial" onClick={send}>
                {submit.busy ? 'Submitting…' : submit.retry ? `Try again: ${missingNote(missing.length)}` : missingNote(missing.length)}
              </button>
              <p className={HINT}>They keep their current numbers.</p>
            </div>
          </>
        )}
        <LiveNote tone="warning">{submit.error}</LiveNote>
        {missing.length === 0 && <button type="button" className={`${BTN_SECONDARY} w-full sm:w-auto`} onClick={onBack}>Back to counting</button>}
      </div>

      <DiscardCount countId={countId} onDiscarded={() => { draft.finish(); onDiscarded(); }} />
    </section>
  );
}
