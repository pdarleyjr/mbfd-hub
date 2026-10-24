import { useEffect, useMemo, useRef, useState } from 'react';
import { ClipboardCheck } from 'lucide-react';
import { discardCount, fetchCounts, fetchLocations, fetchSnapshot, finalizeCount, saveCountLines, startCount } from '../../../utils/emsApi';
import { EmptyState, LoadError, SkeletonRows } from '../../ui/StateViews';
import Tag from '../../ui/Tag';
import {
  COUNT_FIELD_LABEL, MAX_COUNT, convertEntry, countChanges, countFields, etDateTime, parseWhole, snapshotExportUrl, type CountDraft, type CountField,
} from './logic';
import { BTN, BTN_PRIMARY, BTN_SECONDARY, ConfirmStep, ErrorText, FIELD, HINT, LABEL, LiveNote, NumberInput, useEmsLoad, useWrite } from './shared';
import type { CountItem, EmsMeta, SnapshotDetail } from './types';

const AUTOSAVE_MS = 800;

function valueOf(item: CountItem, draft: CountDraft | undefined, field: CountField): string {
  if (draft && draft[field] !== undefined) return draft[field] as string;
  const saved = item.entry?.[field];
  return saved === undefined || saved === null ? '' : String(saved);
}

export function ExportLinks({ id }: { id: string }) {
  return (
    <p className="flex flex-wrap gap-2" data-testid="ems-export-links">
      <a className={`${BTN_SECONDARY} no-underline`} href={snapshotExportUrl(id, 'xlsx')} download>Download Excel</a>
      <a className={`${BTN_SECONDARY} no-underline`} href={snapshotExportUrl(id, 'pdf')} download>Download PDF</a>
    </p>
  );
}

interface SessionProps {
  initial: SnapshotDetail;
  canAct: boolean;
  onExit: () => void;
  onFinalized: (detail: SnapshotDetail) => void;
}

/** One draft count: type numbers, they save as you go, then finalize. A final count can never be changed. */
function CountSession({ initial, canAct, onExit, onFinalized }: SessionProps) {
  const [items, setItems] = useState<CountItem[]>(initial.items ?? []);
  const [drafts, setDrafts] = useState<Record<number, CountDraft>>({});
  const [search, setSearch] = useState('');
  const [savedAt, setSavedAt] = useState<string | null>(null);
  const [pass, setPass] = useState(0);
  const [stage, setStage] = useState<'counting' | 'finalize' | 'discard'>('counting');
  const save = useWrite();
  const finish = useWrite();
  const draftsRef = useRef(drafts);
  draftsRef.current = drafts;

  const pending = useMemo(() => countChanges(items, drafts), [items, drafts]);
  const pendingKey = JSON.stringify(pending.lines);
  const counted = items.filter((item) => item.quantity_base !== null || (item.entry !== null && Object.keys(item.entry).length > 0)).length;

  useEffect(() => {
    if (!canAct || pending.lines.length === 0) return undefined;
    const timer = setTimeout(() => {
      const sent = { ...draftsRef.current };
      void save.run(pendingKey, (key) => saveCountLines(initial.id, pending.lines, key), {
        onDone: (detail) => {
          if (detail.items) setItems(detail.items);
          setDrafts((current) => {
            const next = { ...current };
            for (const line of pending.lines) {
              if (JSON.stringify(next[line.supply_item_id]) === JSON.stringify(sent[line.supply_item_id])) delete next[line.supply_item_id];
            }
            return next;
          });
          setSavedAt(etDateTime(new Date().toISOString()));
          setPass((n) => n + 1);
        },
      });
    }, AUTOSAVE_MS);
    return () => clearTimeout(timer);
    // `save.run` is stable; the content and the retry counter decide when to send.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingKey, pass, canAct]);

  const dirty = pending.lines.length > 0 || save.busy;
  const set = (item: CountItem, field: CountField, text: string) => setDrafts((current) => ({ ...current, [item.id]: { ...(current[item.id] ?? {}), [field]: text } }));
  const needle = search.trim().toLowerCase();
  const shown = needle === '' ? items : items.filter((item) => item.name.toLowerCase().includes(needle));

  const doFinalize = () => {
    void finish.run('finalize', (key) => finalizeCount(initial.id, key), { onDone: onFinalized });
  };
  const doDiscard = () => {
    void finish.run('discard', async (key) => { await discardCount(initial.id, key); return null; }, { onDone: onExit });
  };

  const status = save.busy ? 'Saving…' : save.error ? `Not saved: ${save.error}` : pending.lines.length > 0 ? 'Changes waiting to save…' : savedAt ? `All changes saved at ${savedAt}` : 'Nothing typed yet';

  return (
    <div className="space-y-4" data-testid="ems-count-session">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="font-heading text-lg font-bold text-hub-ink">Counting {initial.scope_location_code ? `location ${initial.scope_location_code}` : 'all locations'}</h3>
          <p className={HINT}>Started {etDateTime(initial.started_at)}{initial.started_by ? ` by ${initial.started_by}` : ''}. {counted} of {items.length} items counted.</p>
        </div>
        <button type="button" className={BTN_SECONDARY} onClick={onExit} disabled={dirty && !save.error}>Back to counts</button>
      </div>

      <div role="status" aria-live="polite" data-testid="ems-autosave" className={`text-sm font-semibold ${save.error ? 'text-hub-danger' : 'text-hub-n-600'}`}>
        {status}
        {save.error && <button type="button" className={`${BTN_SECONDARY} ml-3`} onClick={() => setPass((n) => n + 1)}>{save.retry ? 'Retry saving' : 'Try saving again'}</button>}
      </div>
      {Object.values(pending.errors).map((message) => <ErrorText key={message}>{message}</ErrorText>)}

      <div>
        <label className={LABEL} htmlFor="ems-count-search">Find an item</label>
        <input id="ems-count-search" type="search" className={`${FIELD} sm:max-w-sm`} value={search} autoComplete="off" onChange={(event) => setSearch(event.target.value)} />
      </div>

      {items.length === 0 && <EmptyState title="Nothing to count here" body="This location has no items that are counted in the supply room." />}
      <ul className="space-y-2" aria-label="Items to count">
        {shown.map((item) => {
          const fields = countFields(item);
          const draft = drafts[item.id];
          const typed: Partial<Record<CountField, number>> = {};
          for (const field of fields) { const parsed = parseWhole(valueOf(item, draft, field), 0, MAX_COUNT); if (parsed !== null && !Number.isNaN(parsed)) typed[field] = parsed; }
          const total = fields.length > 1 ? convertEntry(item, typed) : null;
          return (
            <li key={item.id} className="space-y-2 rounded-hub-control border border-hub-border bg-hub-well p-3" data-testid="ems-count-item">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="min-w-0 break-words font-semibold text-hub-ink">{item.name}{item.location_code ? <span className={`${HINT} ml-2 font-normal`}>{item.location_code}</span> : null}</p>
                {item.warnings.includes('unknown_conversion') && <Tag tone="warning">Size not stated</Tag>}
              </div>
              <div className="flex flex-wrap items-end gap-3">
                {fields.map((field) => (
                  <label key={field} className="text-[13px] font-semibold text-hub-n-600">
                    {COUNT_FIELD_LABEL[field]}
                    <NumberInput maxLength={6} label={`${COUNT_FIELD_LABEL[field]} counted for ${item.name}`} value={valueOf(item, draft, field)} disabled={!canAct || finish.busy}
                      onChange={(text) => set(item, field, text)} />
                  </label>
                ))}
                {total !== null && Object.keys(typed).length > 0 && fields.length > 1 && <p className="pb-2 text-sm text-hub-ink">= {total} single units</p>}
              </div>
              {fields.length === 1 && <p className={HINT}>No package size is stated for this item, so count single units.</p>}
            </li>
          );
        })}
      </ul>

      {canAct && stage === 'counting' && (
        <div className="flex flex-wrap gap-2 border-t border-hub-border pt-4">
          <button type="button" className={BTN_PRIMARY} disabled={dirty || counted === 0} onClick={() => setStage('finalize')}>Finalize count</button>
          <button type="button" className={`${BTN} hub-btn--tertiary`} disabled={dirty} onClick={() => setStage('discard')}>Discard this draft</button>
          {dirty && <p className={HINT}>Wait for the changes to save before you finalize or leave.</p>}
        </div>
      )}
      {stage === 'finalize' && (
        <ConfirmStep
          testId="ems-confirm-finalize"
          title="Finalize this count?"
          body={<p>{counted} of {items.length} items are counted; the rest stay "not counted". The count is recorded under your name and the time, and it can never be changed afterward.</p>}
          confirmLabel="Finalize count" busy={finish.busy} error={finish.error} retry={finish.retry} onConfirm={doFinalize} onCancel={() => setStage('counting')}
        />
      )}
      {stage === 'discard' && (
        <ConfirmStep
          testId="ems-confirm-discard"
          title="Discard this draft?"
          body={<p>The numbers typed into this draft are thrown away. Counts that were already finalized are never affected.</p>}
          confirmLabel="Discard draft" busy={finish.busy} error={finish.error} retry={finish.retry} onConfirm={doDiscard} onCancel={() => setStage('counting')}
        />
      )}
    </div>
  );
}

/** Counts: start one for a location, resume a draft, finalize. Final counts are listed with their downloads. */
export default function SupplyCounts({ meta, onForbidden, onChanged }: { meta: EmsMeta; onForbidden: () => void; onChanged: () => void }) {
  const list = useEmsLoad(() => fetchCounts(), 'counts', { onForbidden });
  const locations = useEmsLoad(() => fetchLocations(), 'locations', { onForbidden });
  const [session, setSession] = useState<SnapshotDetail | null>(null);
  const [finalized, setFinalized] = useState<SnapshotDetail | null>(null);
  const [location, setLocation] = useState('');
  const [notice, setNotice] = useState<string | null>(null);
  const start = useWrite();
  const resume = useWrite();
  const canAct = meta.permissions.act;

  const drafts = (list.data ?? []).filter((count) => count.status === 'draft');
  const finals = (list.data ?? []).filter((count) => count.status === 'final').slice(0, 5);

  if (session) {
    return <CountSession key={session.id} initial={session} canAct={canAct} onExit={() => { setSession(null); void list.reload(true); }}
      onFinalized={(detail) => { setSession(null); setFinalized(detail); onChanged(); void list.reload(true); }} />;
  }

  return (
    <div className="space-y-6" data-testid="ems-supply-counts">
      {finalized && (
        <div role="status" className="hub-callout space-y-2 p-3 text-sm" data-testid="ems-count-final">
          <p className="font-semibold text-hub-ink">Count finalized{finalized.version !== null ? ` as version ${finalized.version}` : ''}{finalized.finalized_name ? ` by ${finalized.finalized_name}` : ''}. It cannot be changed.</p>
          <ExportLinks id={finalized.id} />
        </div>
      )}
      <div aria-live="polite">{notice && <p className="text-sm font-semibold text-hub-ink">{notice}</p>}</div>

      {canAct ? (
        <section className="space-y-3" aria-labelledby="ems-start-count">
          <h3 id="ems-start-count" className="font-heading text-base font-bold text-hub-ink">Start a count</h3>
          <p className={HINT}>Pick a location, or count everything. What you type saves as you go, and you can leave a draft and come back to it.</p>
          <div className="flex flex-wrap items-end gap-3">
            <div>
              <label className={LABEL} htmlFor="ems-count-location">Location</label>
              <select id="ems-count-location" className={`${FIELD} sm:w-56`} value={location} onChange={(event) => setLocation(event.target.value)}>
                <option value="">All locations</option>
                {(locations.data ?? []).filter((entry) => entry.active).map((entry) => <option key={entry.code} value={entry.code}>{entry.code}{entry.label && entry.label !== entry.code ? ` (${entry.label})` : ''} ({entry.item_count})</option>)}
              </select>
            </div>
            <button type="button" className={BTN_PRIMARY} disabled={start.busy} aria-busy={start.busy}
              onClick={() => void start.run(location, (key) => startCount(location === '' ? null : location, key), {
                onDone: (detail) => { setFinalized(null); setNotice(detail.resumed ? 'You already had a draft for this location, so it was opened.' : null); setSession(detail); },
              })}>
              {start.busy ? 'Starting…' : start.retry ? 'Retry: Start count' : 'Start count'}
            </button>
          </div>
          <LiveNote tone="warning">{start.error}</LiveNote>
        </section>
      ) : <p className={HINT}>You can read counts. Your access does not include starting one.</p>}

      {list.error && !list.data && <LoadError title="Counts could not be loaded" message={list.error} onRetry={() => void list.reload(false)} />}
      {list.loading && !list.data && <SkeletonRows rows={2} />}

      {drafts.length > 0 && (
        <section className="space-y-2" aria-label="Draft counts">
          <h3 className="font-heading text-base font-bold text-hub-ink">Drafts in progress</h3>
          <ul className="space-y-2">
            {drafts.map((count) => (
              <li key={count.id} className="flex flex-wrap items-center justify-between gap-3 rounded-hub-control border border-hub-border bg-hub-well p-3" data-testid="ems-count-draft">
                <div>
                  <p className="font-semibold text-hub-ink">{count.scope_location_code ? `Location ${count.scope_location_code}` : 'All locations'} <Tag tone="warning">Draft</Tag></p>
                  <p className={HINT}>Started {etDateTime(count.started_at)}{count.started_by ? ` by ${count.started_by}` : ''}. {count.counted_count} of {count.line_count} typed so far.</p>
                </div>
                <button type="button" className={BTN_PRIMARY} disabled={resume.busy} onClick={() => void resume.run(count.id, async () => fetchSnapshot(count.id), { onDone: (detail) => { setFinalized(null); setSession(detail); } })}>
                  {canAct ? 'Resume' : 'View'}
                </button>
              </li>
            ))}
          </ul>
          <LiveNote tone="warning">{resume.error}</LiveNote>
        </section>
      )}

      {list.data && drafts.length === 0 && finals.length === 0 && (
        <EmptyState icon={<ClipboardCheck className="hub-empty__icon" aria-hidden="true" />} title="No counts yet" body="Start a count to record what is on the shelves. Until then, quantities show as not counted." />
      )}
      {finals.length > 0 && (
        <section className="space-y-2" aria-label="Recent final counts">
          <h3 className="font-heading text-base font-bold text-hub-ink">Recent final counts</h3>
          <ul className="space-y-2">
            {finals.map((count) => (
              <li key={count.id} className="rounded-hub-control border border-hub-border bg-hub-well p-3 text-sm text-hub-ink">
                Version {count.version}: {count.scope_location_code ? `location ${count.scope_location_code}` : 'all locations'}, {count.counted_count} of {count.line_count} items counted. {count.finalized_name ?? ''} {etDateTime(count.finalized_at)}.
              </li>
            ))}
          </ul>
          <p className={HINT}>Open Snapshots to view or download any count.</p>
        </section>
      )}
    </div>
  );
}
