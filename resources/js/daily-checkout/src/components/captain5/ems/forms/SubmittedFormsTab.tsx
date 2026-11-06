import { useEffect, useMemo, useState } from 'react';
import { ClipboardList } from 'lucide-react';
import { EmptyState, LoadError, SkeletonRows } from '../../../ui/StateViews';
import { ChoiceChips, FIELD, HINT, LABEL, LiveNote, Pager, BTN_SECONDARY, useEmsLoad } from '../shared';
import type { EmsMeta } from '../types';
import { fetchForms } from './api';
import FormCard from './FormCard';
import FormDetailSheet from './FormDetailSheet';
import {
  ACTIVE_PER_PAGE, ARCHIVED_PER_PAGE, clearDoneText, listKey, mergeSummary, pruneSelection, readyIds, reconcileDoneText, toggleId,
} from './logic';
import ReconcileSheet from './ReconcileSheet';
import RejectSheet, { type RejectTarget } from './RejectSheet';
import { DoneNotice, SelectionBar } from './SelectionBar';
import type { FormDetail, FormSummary, FormView, ReconcileResult } from './types';
import { useClearForms } from './useClearForms';
import { useMasterItems } from './useMasterItems';

interface Props {
  meta: EmsMeta;
  /** The form open in the detail sheet (`?request=<uuid>`), if any. */
  requestId: string | null;
  onOpenRequest: (uuid: string) => void;
  onCloseRequest: () => void;
  /** Bumped about once a minute while the page is visible: the list refreshes quietly. */
  tick: number;
  /** A form changed: the tab badge and the list refresh. */
  onChanged: () => void;
  onForbidden: () => void;
}

const DEBOUNCE_MS = 300;
const VIEWS: Array<{ id: FormView; label: string }> = [{ id: 'active', label: 'Active' }, { id: 'archived', label: 'Archived' }];

/**
 * Submitted Inventory Forms: the Friday work. Acknowledge or reject each form, tick the ready ones and reconcile them
 * with the supply room. Archived holds everything that is finished.
 */
export default function SubmittedFormsTab({ meta, requestId, onOpenRequest, onCloseRequest, tick, onChanged, onForbidden }: Props) {
  const canAct = meta.permissions.act;
  const [view, setView] = useState<FormView>('active');
  const [search, setSearch] = useState('');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set());
  const [rejecting, setRejecting] = useState<RejectTarget | null>(null);
  const [reconcileIds, setReconcileIds] = useState<readonly string[] | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [doneTone, setDoneTone] = useState<'success' | 'warning'>('success');
  /** Forms the last Clear archived: Undo on the notice restores them. */
  const [undoIds, setUndoIds] = useState<readonly string[] | null>(null);
  const [announcement, setAnnouncement] = useState('');
  const master = useMasterItems(onForbidden);
  const batch = useClearForms(onForbidden);

  useEffect(() => {
    const timer = setTimeout(() => { setQ(search); setPage(1); }, DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [search]);

  const perPage = view === 'active' ? ACTIVE_PER_PAGE : ARCHIVED_PER_PAGE;
  const query = view === 'archived' ? q : '';
  // The answer remembers which view it is for, so switching chips never shows the old view's forms under the new heading.
  const { data: loaded, error, loading, reload, setData, applyLocal } = useEmsLoad(
    async () => ({ ...(await fetchForms(view, { q: query, page, perPage })), view }),
    listKey(view, query, page),
    { tick, onForbidden },
  );
  const data = loaded !== null && loaded.view === view ? loaded : null;

  useEffect(() => { if (data) setSelected((current) => pruneSelection(current, data.forms)); }, [data]);

  const forms = useMemo(() => data?.forms ?? [], [data]);
  const ready = useMemo(() => readyIds(forms, canAct), [forms, canAct]);
  /** The page's own refresh (it bumps `tick`) brings the list, the badge and any open form up to date. */
  const refresh = onChanged;

  const notify = (text: string, tone: 'success' | 'warning' = 'success', undo: readonly string[] | null = null) => {
    setDone(text);
    setDoneTone(tone);
    setUndoIds(undo !== null && undo.length > 0 ? undo : null);
    setAnnouncement(text);
  };
  const change = (next: FormView) => { setView(next); setPage(1); setDone(null); setUndoIds(null); };

  /** Cleared or restored forms leave the list in view at once; the refresh that follows brings the server's answer. */
  const dropRows = (ids: readonly string[]) => {
    applyLocal((current) => (current ? {
      ...current,
      forms: current.forms.filter((row) => !ids.includes(row.id)),
      meta: { ...current.meta, total: Math.max(0, current.meta.total - current.forms.filter((row) => ids.includes(row.id)).length) },
    } : current));
    setSelected((current) => (ids.some((id) => current.has(id)) ? new Set([...current].filter((id) => !ids.includes(id))) : current));
  };
  const cleared = (detail: FormDetail) => {
    dropRows([detail.id]);
    notify(clearDoneText(1, 0), 'success', [detail.id]);
    refresh();
  };
  const restored = (detail: FormDetail) => {
    dropRows([detail.id]);
    notify(`Restored ${detail.target_label} form ${detail.number} to Active.`);
    refresh();
  };
  const clearSelected = async () => {
    const outcome = await batch.clear([...selected]);
    if (outcome === null) return;
    dropRows(outcome.done);
    setSelected(new Set(outcome.failed.map((entry) => entry.id)));
    notify(clearDoneText(outcome.done.length, outcome.failed.length, outcome.failed[0]?.message ?? null), outcome.failed.length > 0 ? 'warning' : 'success', outcome.done);
    refresh();
  };
  const undoClear = async () => {
    if (undoIds === null) return;
    const outcome = await batch.restore(undoIds);
    if (outcome === null) return;
    const back = outcome.done.length;
    const text = outcome.failed.length === 0
      ? `Restored ${back} ${back === 1 ? 'form' : 'forms'} to Active.`
      : `Restored ${back} of ${undoIds.length} forms. ${outcome.failed[0].message}`;
    notify(text, outcome.failed.length > 0 ? 'warning' : 'success', outcome.failed.length > 0 ? outcome.failed.map((entry) => entry.id) : null);
    refresh();
  };

  const apply = (detail: FormDetail) => {
    setData((current) => (current ? { ...current, forms: current.forms.map((row) => (row.id === detail.id ? mergeSummary(row, detail) : row)) } : current));
    setSelected((current) => (detail.can_reconcile || !current.has(detail.id) ? current : new Set([...current].filter((id) => id !== detail.id))));
    onChanged();
  };
  const acknowledged = (detail: FormDetail) => {
    apply(detail);
    setAnnouncement(`Acknowledged ${detail.target_label} form ${detail.number}.`);
  };
  const rejected = (detail: FormDetail) => {
    setRejecting(null);
    apply(detail);
    setAnnouncement(`Sent ${detail.target_label} form ${detail.number} back to ${detail.submitter_name}.`);
  };
  const reconciled = (result: ReconcileResult) => {
    setReconcileIds(null);
    setSelected(new Set());
    notify(reconcileDoneText(result));
    refresh();
  };
  const leaveOut = (ids: string[]) => {
    const rest = (reconcileIds ?? []).filter((id) => !ids.includes(id));
    setSelected(new Set(rest));
    setReconcileIds(rest.length > 0 ? rest : null);
  };

  const narrowed = view === 'archived' && q.trim() !== '';
  const openCard = (form: FormSummary) => (
    <FormCard key={form.id} form={form} view={view} canAct={canAct} selected={selected.has(form.id)}
      onToggle={(id) => setSelected((current) => toggleId(current, id))} onOpen={onOpenRequest} onAcknowledged={acknowledged}
      onCleared={cleared} onRestored={restored}
      onReject={(target) => setRejecting({ id: target.id, member: target.submitter_name, number: target.number })} onConflict={refresh} />
  );

  return (
    <div className="space-y-4" data-testid="ems-forms-tab">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <ChoiceChips<FormView> label="Show forms" testId="ems-forms-views" value={view} options={VIEWS} onChange={change} />
        {view === 'active' && ready.length > 0 && (
          <button type="button" data-testid="ems-select-all-ready" className="hub-btn hub-btn--tertiary !min-h-11" onClick={() => setSelected(new Set(ready))}>
            Select all ready ({ready.length})
          </button>
        )}
      </div>

      {view === 'active'
        ? <p className={HINT}>Acknowledge each form, then tick the ones that are ready and reconcile them with the supply room. If you handed the supplies over yourself, Clear the form: it moves to Archived and stock does not change.</p>
        : (
          <div>
            <label className={LABEL} htmlFor="ems-forms-search">Search archived forms</label>
            <input id="ems-forms-search" type="search" className={FIELD} value={search} placeholder="Number, member or unit" autoComplete="off" maxLength={100} onChange={(event) => setSearch(event.target.value)} />
          </div>
        )}

      {error && data && <LiveNote tone="warning">The list could not be refreshed: {error} What you see may be out of date.</LiveNote>}
      {error && !data && <LoadError title="Forms could not be loaded" message={error} onRetry={() => void reload(false)} />}
      {!error && loading && !data && <SkeletonRows rows={4} />}

      {data && forms.length === 0 && !error && (
        <EmptyState
          icon={<ClipboardList className="hub-empty__icon" aria-hidden="true" />}
          title={view === 'active' ? 'No forms waiting' : narrowed ? 'No archived forms match this search' : 'No archived forms yet'}
          body={view === 'active' ? 'When an apparatus submits a medical inventory form it appears here for you to acknowledge and reconcile.' : narrowed ? 'Try a different number, member or unit.' : 'Reconciled and cleared forms and older records are kept here.'}
          action={view === 'archived' && narrowed ? <button type="button" className={BTN_SECONDARY} onClick={() => setSearch('')}>Clear search</button> : undefined}
        />
      )}
      {data && forms.length > 0 && (
        <div className="space-y-3" aria-busy={loading}>
          <ul className="space-y-2" aria-label={view === 'active' ? 'Active forms' : 'Archived forms'} data-testid="ems-forms-list">{forms.map(openCard)}</ul>
          <Pager page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} noun={data.meta.total === 1 ? 'form' : 'forms'} busy={loading} onPage={setPage} />
        </div>
      )}

      {view === 'active' && selected.size > 0 && (
        <SelectionBar count={selected.size} onClear={() => setSelected(new Set())} clearing={batch.busy === 'clear'}
          onReconcile={() => { setDone(null); setUndoIds(null); setReconcileIds([...selected]); }}
          onClearForms={canAct ? () => void clearSelected() : undefined} />
      )}
      {done !== null && !(view === 'active' && selected.size > 0) && (
        <DoneNotice text={done} tone={doneTone} onDismiss={() => { setDone(null); setUndoIds(null); }}
          onUndo={undoIds !== null && canAct ? () => void undoClear() : undefined} undoing={batch.busy === 'restore'} />
      )}
      <div className="sr-only" role="status" aria-live="polite" data-testid="ems-forms-announce">{announcement}</div>

      <RejectSheet target={rejecting} onClose={() => setRejecting(null)} onDone={rejected} onConflict={refresh} />
      <ReconcileSheet ids={reconcileIds} master={master} onClose={() => setReconcileIds(null)} onReconciled={reconciled} onChanged={refresh} onForbidden={onForbidden} onLeaveOut={leaveOut} />
      <FormDetailSheet uuid={requestId} canAct={canAct} tick={tick} onOpenForm={onOpenRequest} onClose={onCloseRequest} onChanged={refresh} onForbidden={onForbidden} />
    </div>
  );
}
