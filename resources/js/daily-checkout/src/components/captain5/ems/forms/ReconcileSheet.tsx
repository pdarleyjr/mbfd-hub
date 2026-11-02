import { useCallback, useEffect, useMemo, useState } from 'react';
import type { EmsApiError } from '../../../../utils/emsApi';
import { LoadError, SkeletonRows } from '../../../ui/StateViews';
import { Sheet } from '../../ui';
import { BTN_PRIMARY, BTN_SECONDARY, HINT, LiveNote, useEmsLoad, useWrite } from '../shared';
import { fetchReconcilePreview, reconcileForms } from './api';
import {
  buildDecisions, correctionLineIds, hasLowStock, indexMaster, itemTakes, keepInputs, reconcileSignature, resolveAll, unresolvedCount, type LineInputs,
} from './logic';
import { BlockedNotice, DecisionRow, FormsList, ItemCard, QuietList, type InputPatch } from './ReconcileSections';
import type { ReconcileRequest, ReconcileResult } from './types';
import type { MasterItems } from './useMasterItems';

interface Props {
  /** The forms to reconcile; null keeps the sheet closed. */
  ids: readonly string[] | null;
  master: MasterItems;
  onClose: () => void;
  onReconciled: (result: ReconcileResult) => void;
  /** Something changed on the server that the list should show. */
  onChanged: () => void;
  onForbidden: () => void;
  /** The person left these forms out of this reconcile. */
  onLeaveOut: (ids: string[]) => void;
}

const plural = (count: number, one: string, many = `${one}s`): string => `${count} ${count === 1 ? one : many}`;

function Content({ ids, master, onClose, onReconciled, onChanged, onForbidden, onLeaveOut }: Omit<Props, 'ids'> & { ids: readonly string[] }) {
  const { data: preview, error, loading, reload } = useEmsLoad(() => fetchReconcilePreview(ids), ids.join(','), { onForbidden });
  const [inputs, setInputs] = useState<LineInputs>({});
  const [note, setNote] = useState<string | null>(null);
  const [stale, setStale] = useState(false);
  const write = useWrite();
  const { load: loadMaster } = master;

  const needsItem = preview?.lines.some((line) => line.state === 'needs_item') ?? false;
  useEffect(() => { if (needsItem) loadMaster(); }, [needsItem, loadMaster]);
  useEffect(() => { if (preview) setInputs((current) => keepInputs(current, preview)); }, [preview]);

  const masterIndex = useMemo(() => indexMaster(master.items ?? []), [master.items]);
  const resolutions = useMemo(() => (preview ? resolveAll(preview, inputs, masterIndex) : []), [preview, inputs, masterIndex]);
  const takes = useMemo(() => (preview ? itemTakes(preview, resolutions, masterIndex) : []), [preview, resolutions, masterIndex]);
  const flagged = useMemo(() => new Set(preview ? correctionLineIds(write.fields, preview) : []), [preview, write.fields]);
  const unresolved = unresolvedCount(resolutions);
  const low = hasLowStock(takes);
  const blocked = preview !== null && (preview.blocked || preview.forms.some((form) => !form.eligible));

  const patch: InputPatch = useCallback((lineId, change) => {
    setStale(false);
    setInputs((current) => ({ ...current, [lineId]: { ...current[lineId], ...change } }));
  }, []);

  const conflict = (failure: EmsApiError) => {
    if (failure.isStalePreview || failure.code === 'low_stock') {
      setStale(true);
      setNote(failure.code === 'low_stock'
        ? 'Stock changed while this was open. The numbers are updated. Check the red lines, then confirm again.'
        : 'Stock changed while this was open. The numbers are updated and what you typed is kept. Check them, then confirm again.');
      void reload(true);
      return;
    }
    // Already reconciled, or no longer eligible: the server's words stay on screen and the lists catch up.
    setNote(null);
    onChanged();
    void reload(true);
  };

  const confirm = () => {
    if (!preview || blocked || unresolved > 0 || loading) return;
    const decisions = buildDecisions(resolutions);
    const body: ReconcileRequest = {
      request_ids: [...ids],
      expected_last_movement_id: preview.last_movement_id,
      ...(low ? { accept_low_stock: true } : {}),
      ...(decisions.length > 0 ? { decisions } : {}),
    };
    setNote(null);
    setStale(false);
    void write.run(reconcileSignature(ids, preview.last_movement_id, low, decisions), (key) => reconcileForms(body, key), { onDone: onReconciled, onConflict: conflict });
  };

  const decisionRows = resolutions.filter((entry) => entry.group === 'decision');
  const quietRows = resolutions.filter((entry) => entry.group === 'quiet');
  const confirmLabel = write.busy ? 'Reconciling…' : unresolved > 0 ? `Answer ${plural(unresolved, 'more line')} to continue` : write.retry ? 'Retry: Confirm and reconcile' : 'Confirm and reconcile';
  const errorText = stale ? null : write.error;

  return (
    <>
      {error && !preview && <LoadError title="The reconcile preview could not be loaded" message={error} onRetry={() => void reload(false)}><button type="button" className={BTN_SECONDARY} onClick={onClose}>Close</button></LoadError>}
      {!error && !preview && <SkeletonRows rows={3} />}
      {error && preview && <LiveNote tone="warning">The preview could not be refreshed: {error} What you see may be out of date.</LiveNote>}

      {preview && (
        <div className="space-y-5" aria-busy={loading} data-testid="ems-reconcile-body">
          <LiveNote tone="warning" testId="ems-reconcile-note">{note}</LiveNote>
          <section aria-labelledby="ems-reconcile-forms-heading" className="space-y-1.5">
            <h3 id="ems-reconcile-forms-heading" className="font-heading text-base font-bold text-hub-ink">{plural(preview.forms.length, 'form')}</h3>
            <FormsList forms={preview.forms} />
          </section>

          <BlockedNotice forms={preview.forms} busy={write.busy} onLeaveOut={() => onLeaveOut(preview.forms.filter((form) => !form.eligible).map((form) => form.id))} />

          {decisionRows.length > 0 && (
            <section aria-labelledby="ems-reconcile-decide-heading" className="space-y-2">
              <h3 id="ems-reconcile-decide-heading" className="font-heading text-base font-bold text-hub-ink">Needs your answer</h3>
              <ul className="space-y-2">
                {decisionRows.map((row) => (
                  <DecisionRow key={row.line.line_id} resolution={row} input={inputs[row.line.line_id] ?? {}} master={master.items} masterError={master.error}
                    onRetryMaster={() => loadMaster(true)} onInput={patch} flagged={flagged.has(row.line.line_id)} disabled={write.busy} />
                ))}
              </ul>
            </section>
          )}

          <section aria-labelledby="ems-reconcile-shelf-heading" className="space-y-2">
            <h3 id="ems-reconcile-shelf-heading" className="font-heading text-base font-bold text-hub-ink">Coming off the shelf</h3>
            {takes.length === 0
              ? <p className={HINT}>Nothing comes off the shelf from these forms.</p>
              : (
                <ul className="space-y-2" data-testid="ems-reconcile-items">
                  {takes.map((take) => (
                    <ItemCard key={take.itemId} take={take} onInput={patch} flaggedIds={flagged} disabled={write.busy}
                      rows={resolutions.filter((entry) => entry.group === 'shelf' && entry.itemId === take.itemId)} />
                  ))}
                </ul>
              )}
          </section>

          <QuietList rows={quietRows} />
        </div>
      )}

      <div className="sticky bottom-0 z-10 -mx-4 -mb-4 mt-5 space-y-2 border-t border-hub-border bg-hub-n-0 px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 sm:-mx-5 sm:px-5">
        <LiveNote tone="warning">{errorText}</LiveNote>
        <div className="flex flex-wrap gap-2">
          <button type="button" data-testid="ems-reconcile-confirm" className={BTN_PRIMARY} disabled={!preview || blocked || unresolved > 0 || loading || write.busy} aria-busy={write.busy} onClick={confirm}>
            {confirmLabel}
          </button>
          <button type="button" className={BTN_SECONDARY} disabled={write.busy} onClick={onClose}>Go back</button>
        </div>
      </div>
    </>
  );
}

/** Confirms a reconcile: which forms, what comes off each shelf, and the few answers only a person can give. */
export default function ReconcileSheet(props: Props) {
  const { ids, onClose } = props;
  if (ids === null || ids.length === 0) return null;
  return (
    <Sheet open onClose={onClose} testId="ems-reconcile-sheet" title="Reconcile with Supply Room Inventory" description={`${plural(ids.length, 'form')} selected. Nothing changes until you confirm.`}>
      <Content {...props} ids={ids} />
    </Sheet>
  );
}
