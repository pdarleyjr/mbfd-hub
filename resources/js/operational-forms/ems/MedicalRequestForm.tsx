import { useCallback, useDeferredValue, useEffect, useMemo, useRef, useState } from 'react';
import { Button, MessageBar, MessageBarBody } from '@fluentui/react-components';
import { AlertTriangle, ArrowLeft, Check, ClipboardList, ListChecks, Search, WifiOff, X } from 'lucide-react';
import { EmsApiError, type EmsApi } from './emsApi';
import {
    blankDraft, buildPayload, bumpQuantity, carriedCount, classifyFailure, countRequested, discardCarried, fieldForError, filterForms, formName, groupSections,
    hasUnsavedWork, itemsLabel, lineKey, newSubmissionId, payloadItemCount, readiness, requestedInSections, restoreDraft, serializeDraft,
    setAnswer, setQuantity, unitForChoice, variantCaption,
} from './emsLogic.js';
import { emsDrafts } from './emsStore';
import type { EmsDraft, EmsFormDefinition, EmsOutboxEntry, EmsRequestDetail, EmsUnit } from './emsTypes';
import { CategoryPanel, ItemRow } from './FormParts';
import { PendingRequests } from './PendingRequests';
import { ReviewSheet } from './ReviewSheet';
import { useEmsDraftBusy } from './useEmsBusy';
import type { EmsOutbox } from './useEmsOutbox';

type Result =
    | { kind: 'sent'; request: EmsRequestDetail }
    | { kind: 'queued'; reason: 'offline' | 'server' | 'auth'; message: string };

interface FieldErrors { target?: string; event?: string; note?: string; items?: string }

const PERSIST_DELAY_MS = 400;

function reducedMotion() {
    return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function unitOptionLabel(unit: EmsUnit) {
    return unit.status && unit.status !== 'In Service' ? `${unit.label} (${unit.status})` : unit.label;
}

export interface MedicalRequestFormProps {
    api: EmsApi;
    owner: string;
    definition: EmsFormDefinition;
    outbox: EmsOutbox;
    /** A request sent back for fixing ("Edit and resend"); otherwise the saved draft is restored. */
    startFrom: EmsDraft | null;
    onStartConsumed: () => void;
    goForms: () => void;
    goMine: () => void;
    goDetail: (id: string) => void;
    editEntry: (entry: EmsOutboxEntry) => void;
    onSubmitted: () => void;
}

export function MedicalRequestForm({ api, owner, definition, outbox, startFrom, onStartConsumed, goForms, goMine, goDetail, editEntry, onSubmitted }: MedicalRequestFormProps) {
    const [draft, setDraft] = useState<EmsDraft | null>(null);
    const [restored, setRestored] = useState(false);
    const [query, setQuery] = useState('');
    const [activeBag, setActiveBag] = useState('');
    const deferredQuery = useDeferredValue(query);
    const [openPanels, setOpenPanels] = useState<Record<string, boolean>>({});
    const [reviewOpen, setReviewOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [reviewError, setReviewError] = useState('');
    const [errors, setErrors] = useState<FieldErrors>({});
    const [result, setResult] = useState<Result | null>(null);
    const sendingRef = useRef(false);
    const carriedAfterSend = useRef<EmsDraft['carried'] | null>(null);
    const latestDraft = useRef<EmsDraft | null>(null);
    const targetRef = useRef<HTMLSelectElement | null>(null);
    const searchRef = useRef<HTMLInputElement | null>(null);
    const topRef = useRef<HTMLDivElement | null>(null);
    const reviewButtonRef = useRef<HTMLButtonElement | null>(null);

    const max = definition.limits.max_quantity;
    const controlledNote = definition.rules.controlled_substance_note;

    // Open with the request sent back for fixing, else with whatever was saved on this device.
    useEffect(() => {
        let active = true;
        const begin = async () => {
            if (startFrom) {
                setDraft(startFrom); setRestored(false); onStartConsumed();
                return;
            }
            const saved = restoreDraft(await emsDrafts.get(owner), definition);
            if (!active) return;
            setDraft(saved ?? blankDraft());
            setRestored(saved !== null && (countRequested(saved) > 0 || saved.targetChoice !== '' || carriedCount(saved) > 0));
        };
        void begin();
        return () => { active = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [owner, definition]);

    useEffect(() => { latestDraft.current = draft; }, [draft]);

    // Keep the unfinished request on this device: quantities, the unit choice and the submission id only.
    const persist = useCallback(async (value: EmsDraft | null) => {
        if (!value) return;
        const stored = serializeDraft(value);
        if (stored) await emsDrafts.put(owner, stored);
        else await emsDrafts.remove(owner);
    }, [owner]);

    useEffect(() => {
        if (!draft || result) return;
        const timer = window.setTimeout(() => void persist(draft), PERSIST_DELAY_MS);
        return () => window.clearTimeout(timer);
    }, [draft, result, persist]);

    useEffect(() => {
        const flush = () => { if (!result) void persist(latestDraft.current); };
        const hidden = () => { if (document.visibilityState === 'hidden') flush(); };
        window.addEventListener('pagehide', flush);
        document.addEventListener('visibilitychange', hidden);
        return () => { window.removeEventListener('pagehide', flush); document.removeEventListener('visibilitychange', hidden); };
    }, [persist, result]);

    const onType = useCallback((key: string, raw: string) => setDraft((d) => (d ? setQuantity(d, key, raw, max) : d)), [max]);
    const onBump = useCallback((key: string, delta: number) => setDraft((d) => (d ? bumpQuantity(d, key, delta, max) : d)), [max]);
    const onAnswer = useCallback((key: string, value: boolean | null) => setDraft((d) => (d ? setAnswer(d, key, value) : d)), []);
    const patch = useCallback((change: Partial<EmsDraft>) => setDraft((d) => (d ? { ...d, ...change } : d)), []);

    const searching = deferredQuery.trim() !== '';
    const filtered = useMemo(() => filterForms(definition.forms, deferredQuery), [definition.forms, deferredQuery]);
    // One list at a time (Airway Bag, ALS Med Bag/Box, ALS Transport Vehicle); a search looks through all of them.
    const bagKey = definition.forms.some((form) => form.key === activeBag) ? activeBag : (definition.forms[0]?.key ?? '');
    const firstPanelOf = (formKey: string) => {
        const form = definition.forms.find((candidate) => candidate.key === formKey);
        return form ? groupSections(form.sections)[0]?.section.key : undefined;
    };
    const isOpen = (formKey: string, sectionKey: string) => {
        if (searching) return true;
        return openPanels[`${formKey}:${sectionKey}`] ?? sectionKey === firstPanelOf(formKey);
    };
    const togglePanel = (formKey: string, sectionKey: string) => setOpenPanels((state) => ({ ...state, [`${formKey}:${sectionKey}`]: !isOpen(formKey, sectionKey) }));
    const setAllPanels = (form: EmsFormDefinition['forms'][number], open: boolean) => setOpenPanels((state) => {
        const next = { ...state };
        for (const { section } of groupSections(form.sections)) next[`${form.key}:${section.key}`] = open;
        return next;
    });
    const chooseBag = (key: string) => {
        setActiveBag(key);
        window.requestAnimationFrame(() => document.getElementById('ems-bags')?.scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' }));
    };
    const shownForms = searching ? filtered.forms : filtered.forms.filter((form) => form.key === bagKey);

    const unitGroups = useMemo(() => {
        const groups: { label: string; units: EmsUnit[] }[] = [];
        for (const unit of definition.units) {
            if (unit.kind === 'detail_event') continue;
            const last = groups[groups.length - 1];
            if (last && last.label === unit.group) last.units.push(unit);
            else groups.push({ label: unit.group, units: [unit] });
        }
        return groups;
    }, [definition.units]);
    const detailUnit = definition.units.find((unit) => unit.kind === 'detail_event');

    const focusWithScroll = (element: HTMLElement | null) => {
        if (!element) return;
        element.scrollIntoView({ block: 'center', behavior: reducedMotion() ? 'auto' : 'smooth' });
        element.focus({ preventScroll: true });
    };

    const chooseUnit = (value: string) => {
        patch({ targetChoice: value });
        setErrors((current) => ({ ...current, target: undefined }));
    };

    const startNew = () => {
        setResult(null); setDraft(carriedAfterSend.current ? { ...blankDraft(), carried: carriedAfterSend.current } : blankDraft()); setRestored(false); setQuery(''); setOpenPanels({}); setActiveBag(''); setErrors({}); setReviewError('');
        window.scrollTo({ top: 0, behavior: reducedMotion() ? 'auto' : 'smooth' });
    };

    const clearForm = () => {
        if (!window.confirm('Clear everything you entered on this form?')) return;
        void emsDrafts.remove(owner);
        setDraft(blankDraft()); setRestored(false); setErrors({}); setQuery('');
    };

    const ready = draft ? readiness(draft) : null;
    const carried = draft ? carriedCount(draft) : 0;
    useEmsDraftBusy({
        unsaved: draft !== null && result === null && hasUnsavedWork(draft),
        sending,
        textAtRisk: draft !== null && result === null && (draft.note.trim() !== '' || draft.eventAssignment.trim() !== ''),
    });

    const openReview = () => {
        if (!draft || !ready) return;
        if (ready.needsTarget) {
            setErrors((current) => ({ ...current, target: 'Choose a unit, or Detail / Special Event, so Captain 5 knows where the supplies go.' }));
            focusWithScroll(targetRef.current);
            return;
        }
        if (ready.needsContent) {
            setErrors((current) => ({ ...current, items: 'Enter how many you need of at least one item, or write a note, then review.' }));
            focusWithScroll(searchRef.current);
            return;
        }
        setReviewError(''); setErrors({}); setReviewOpen(true);
    };

    const send = async () => {
        if (!draft || sendingRef.current) return; // a double tap cannot send twice
        sendingRef.current = true; setSending(true); setReviewError('');
        const payload = buildPayload(draft, definition);
        const unit = unitForChoice(draft.targetChoice, definition.units);
        try {
            const outcome = await api.submit(payload);
            await emsDrafts.remove(owner);
            // Entries kept for items that are not on the form right now are not part of what was sent; they stay saved.
            carriedAfterSend.current = draft.carried ?? null;
            if (draft.carried) await emsDrafts.put(owner, { v: 1, clientSubmissionId: newSubmissionId(), targetChoice: '', quantities: {}, answers: {}, carried: draft.carried, savedAt: new Date().toISOString() });
            await outbox.discard(payload.client_submission_id);
            setResult({ kind: 'sent', request: outcome.request });
            setReviewOpen(false);
            onSubmitted();
        } catch (error) {
            if (!(error instanceof EmsApiError)) {
                console.error('The medical inventory request could not be sent.', error);
                setReviewError('Something went wrong on this device. Your entries are still here. Try again.');
            } else {
                await handleFailure(error, payload, unit?.label ?? draft.targetChoice);
            }
        } finally {
            sendingRef.current = false; setSending(false);
        }
    };

    const handleFailure = async (error: EmsApiError, payload: ReturnType<typeof buildPayload>, unitLabel: string) => {
        const kind = classifyFailure({ status: error.status, code: error.code, network: error.network });
        if (kind === 'message') {
            const field = fieldForError(error.code, error.fields);
            if (error.code === 'duplicate_submission') patch({ clientSubmissionId: newSubmissionId() });
            if (field === 'target' || field === 'event' || field === 'note') {
                setReviewOpen(false);
                setErrors({ [field]: error.message });
                window.setTimeout(() => focusWithScroll(field === 'target' ? targetRef.current : document.getElementById(field === 'event' ? 'ems-event' : 'ems-note')), 50);
            } else {
                if (field === 'items') setErrors({ items: error.message });
                setReviewError(error.message);
            }
            return;
        }

        // Offline, signed out, throttled or a server hiccup: keep it on this device and retry with the same submission id.
        const entry: EmsOutboxEntry = {
            clientSubmissionId: payload.client_submission_id, payload, createdAt: new Date().toISOString(),
            state: 'pending', unitLabel, itemCount: payloadItemCount(payload),
        };
        const saved = await outbox.enqueue(entry);
        if (!saved) {
            setReviewError('This could not be sent and this browser could not save it. Keep this page open and try again.');
            return;
        }
        await emsDrafts.remove(owner);
        setResult({
            kind: 'queued',
            reason: kind === 'auth' ? 'auth' : error.network ? 'offline' : 'server',
            message: error.message,
        });
        setReviewOpen(false);
    };

    if (!draft || !ready) return <div className="ems-app"><p className="ems-loading" role="status">Opening the form</p></div>;

    if (result?.kind === 'sent') {
        return <div className="ems-app"><SentConfirmation request={result.request} onView={() => goDetail(result.request.id)} onNew={startNew} onBack={goForms} onMine={goMine} /></div>;
    }
    if (result?.kind === 'queued') {
        return <div className="ems-app">
            <QueuedNotice reason={result.reason} message={result.message} outbox={outbox} onNew={startNew} onBack={goForms} />
            <PendingRequests outbox={outbox} openRequest={goDetail} editEntry={editEntry} />
        </div>;
    }

    const requestedCount = countRequested(draft);
    const isDetail = draft.targetChoice === 'detail';
    const unit = unitForChoice(draft.targetChoice, definition.units);

    return <div className="ems-app has-bar" ref={topRef} {...(hasUnsavedWork(draft) ? { 'data-mbfd-unsaved-work-guard': '' } : {})}>
        <div className="ems-topline">
            <Button appearance="subtle" icon={<ArrowLeft size={17} />} onClick={goForms}>Forms library</Button>
            <Button appearance="subtle" icon={<ListChecks size={17} />} onClick={goMine}>My medical requests</Button>
        </div>
        <header className="ems-head">
            <p className="of-eyebrow">Captain 5 EMS supply</p>
            <h1>Medical Inventory Request</h1>
            <p>Choose your unit, pick a list, and enter how many you need of each item. Leave the rest blank; a partial request is fine.</p>
        </header>

        <PendingRequests outbox={outbox} openRequest={goDetail} editEntry={editEntry} />

        {restored && <MessageBar intent="info"><MessageBarBody>Your unfinished request was restored from this device. Notes and event text are not kept when you leave, so add yours again if you had one.</MessageBarBody></MessageBar>}

        {carried > 0 && <MessageBar intent="warning" className="ems-carried"><MessageBarBody>
            {carried === 1 ? '1 saved entry is' : `${carried} saved entries are`} for items that are not on this form right now. {carried === 1 ? 'It is' : 'They are'} kept on this device and not sent.
            {' '}<Button appearance="transparent" onClick={() => setDraft((d) => (d ? discardCarried(d) : d))}>Discard {carried === 1 ? 'it' : 'them'}</Button>
        </MessageBarBody></MessageBar>}

        <section className="ems-card" aria-labelledby="ems-target-label">
            <label id="ems-target-label" htmlFor="ems-target" className="ems-label">Completing this form for:</label>
            <select
                id="ems-target" ref={targetRef} className="ems-select" value={draft.targetChoice} onChange={(event) => chooseUnit(event.target.value)}
                aria-describedby={`ems-target-hint${errors.target ? ' ems-target-error' : ''}`} aria-invalid={Boolean(errors.target)}
            >
                <option value="">Choose a unit</option>
                {unitGroups.map((group) => <optgroup key={group.label} label={group.label}>
                    {group.units.map((candidate) => <option key={candidate.id ?? candidate.label} value={`unit:${candidate.id}`}>{unitOptionLabel(candidate)}</option>)}
                </optgroup>)}
                {detailUnit && <option value="detail">{detailUnit.label}</option>}
            </select>
            <p id="ems-target-hint" className="ems-hint">Rescue units are listed first. Captain 5 uses this to know where the supplies go.</p>
            {errors.target && <p id="ems-target-error" className="ems-field-error" role="alert"><AlertTriangle size={15} aria-hidden="true" /> {errors.target}</p>}
            {isDetail && <div className="ems-event">
                <label htmlFor="ems-event" className="ems-label">Unit, assignment, or position for this event <span className="ems-optional">optional</span></label>
                <input id="ems-event" className="ems-text" type="text" maxLength={definition.limits.event_assignment_max} autoComplete="off" value={draft.eventAssignment}
                    aria-invalid={Boolean(errors.event)} aria-describedby={errors.event ? 'ems-event-error' : undefined}
                    onChange={(event) => { patch({ eventAssignment: event.target.value }); setErrors((current) => ({ ...current, event: undefined })); }} />
                {errors.event && <p id="ems-event-error" className="ems-field-error" role="alert"><AlertTriangle size={15} aria-hidden="true" /> {errors.event}</p>}
            </div>}
        </section>

        <section className="ems-card ems-searchbar" aria-label="Find an item">
            <label htmlFor="ems-search" className="sr-only">Search items in every list</label>
            <div className="ems-search">
                <Search size={18} aria-hidden="true" />
                <input id="ems-search" ref={searchRef} type="search" className="ems-search-input" autoComplete="off" placeholder="Search all lists" value={query} onChange={(event) => setQuery(event.target.value)} />
                {query && <button type="button" className="ems-search-clear" aria-label="Clear search" onClick={() => { setQuery(''); searchRef.current?.focus(); }}><X size={17} aria-hidden="true" /></button>}
            </div>
            <p className="ems-search-count" role="status" aria-live="polite">
                {searching ? (filtered.matches === 0 ? `No items match "${deferredQuery.trim()}". Clear the search to see the full list.` : `${filtered.matches} matching ${filtered.matches === 1 ? 'item' : 'items'}`) : ''}
            </p>
            {errors.items && <p className="ems-field-error" role="alert"><AlertTriangle size={15} aria-hidden="true" /> {errors.items}</p>}
        </section>

        {!searching && definition.forms.length > 1 && <nav id="ems-bags" className="ems-bags" aria-label="Choose a list">
            {definition.forms.map((form) => {
                const needed = requestedInSections(draft, form.key, form.sections);
                const total = form.sections.reduce((sum, section) => sum + section.items.length, 0);
                return <button key={form.key} type="button" className="ems-bag" aria-pressed={form.key === bagKey} onClick={() => chooseBag(form.key)}>
                    <span className="ems-bag-name">{formName(form)}</span>
                    <span className="ems-bag-meta">{itemsLabel(total)}{needed > 0 && <span className="ems-count-chip">{needed} needed</span>}</span>
                </button>;
            })}
        </nav>}

        {shownForms.map((form) => <section key={form.key} className="ems-form" aria-labelledby={`ems-form-${form.key}`}>
            <header className="ems-form-head">
                <h2 id={`ems-form-${form.key}`}><ClipboardList size={18} aria-hidden="true" /> {formName(form)}</h2>
                <p className="ems-form-source">Source form: {form.title}{form.version_label && form.version_label !== 'undated' ? `, ${form.version_label}` : ''}</p>
                {form.printed_note && <p className="ems-printed-note">{form.printed_note}</p>}
                {!searching && groupSections(form.sections).length > 1 && <div className="ems-form-tools">
                    <Button appearance="subtle" onClick={() => setAllPanels(form, true)}>Open all sections</Button>
                    <Button appearance="subtle" onClick={() => setAllPanels(form, false)}>Close all sections</Button>
                </div>}
            </header>
            {groupSections(form.sections).map(({ section, children }) => {
                const all = [section, ...children];
                const total = all.reduce((sum, part) => sum + part.items.length, 0);
                const panelId = `ems-panel-${form.key}-${section.key}`;
                return <CategoryPanel
                    key={section.key} panelId={panelId} title={section.label} total={total}
                    requested={requestedInSections(draft, form.key, all)} open={isOpen(form.key, section.key)} onToggle={() => togglePanel(form.key, section.key)}
                >
                    {all.map((part, partIndex) => <div key={part.key} className="ems-subsection">
                        {partIndex > 0 && <h4 className="ems-subhead">{part.label}</h4>}
                        <ul className="ems-items">
                            {part.items.map((item, index) => {
                                const key = lineKey(form.key, item.item_key);
                                return <ItemRow
                                    key={item.item_key} rowId={`ems-${form.key}-${item.item_key}`.replace(/[^a-zA-Z0-9-]/g, '-')} itemKey={key} item={item}
                                    qty={draft.quantities[key]} answer={draft.answers[key]} caption={variantCaption(part.items, index)} controlledNote={controlledNote}
                                    onType={onType} onBump={onBump} onAnswer={onAnswer}
                                />;
                            })}
                        </ul>
                    </div>)}
                </CategoryPanel>;
            })}
        </section>)}

        <section className="ems-card" aria-labelledby="ems-note-label">
            <label id="ems-note-label" htmlFor="ems-note" className="ems-label">Note for Captain 5 <span className="ems-optional">optional</span></label>
            <textarea id="ems-note" className="ems-textarea" rows={3} maxLength={definition.limits.note_max} value={draft.note}
                aria-invalid={Boolean(errors.note)} aria-describedby={errors.note ? 'ems-note-error' : undefined}
                onChange={(event) => { patch({ note: event.target.value }); setErrors((current) => ({ ...current, note: undefined, items: undefined })); }} />
            {errors.note && <p id="ems-note-error" className="ems-field-error" role="alert"><AlertTriangle size={15} aria-hidden="true" /> {errors.note}</p>}
            {(draft.note.length > definition.limits.note_max * 0.8) && <p className="ems-hint">{draft.note.length} of {definition.limits.note_max} characters</p>}
        </section>

        {hasUnsavedWork(draft) && <div className="ems-clear"><Button appearance="subtle" onClick={clearForm}>Clear form</Button></div>}

        <div className="ems-bar" role="region" aria-label="Request summary">
            <div className="ems-bar-summary">
                <strong className="ems-bar-count" role="status" aria-live="polite" aria-atomic="true">{itemsLabel(requestedCount)}</strong>
                <span className={`ems-bar-hint${ready.ready ? '' : ' is-pending'}`}>{ready.message}</span>
            </div>
            <Button ref={reviewButtonRef} appearance="primary" size="large" className="ems-bar-action" onClick={openReview}>Review and send</Button>
        </div>

        <ReviewSheet
            open={reviewOpen} definition={definition} draft={draft} carried={carried} unitLabel={unit?.label ?? ''} sending={sending} error={reviewError}
            onBack={() => { if (!sending) { setReviewOpen(false); window.setTimeout(() => reviewButtonRef.current?.focus(), 0); } }} onSend={() => void send()}
        />
    </div>;
}

function SentConfirmation({ request, onView, onNew, onBack, onMine }: {
    request: EmsRequestDetail; onView: () => void; onNew: () => void; onBack: () => void; onMine: () => void;
}) {
    const items = request.lines.filter((line) => (line.response_type === 'yes_no' ? line.requested_flag === true : (line.requested_qty ?? 0) > 0)).length;
    const headingRef = useRef<HTMLHeadingElement | null>(null);
    useEffect(() => { headingRef.current?.focus(); }, []);
    return <section className="ems-result" aria-labelledby="ems-sent-title">
        <div className="ems-result-icon is-success"><Check size={28} aria-hidden="true" /></div>
        <h1 id="ems-sent-title" tabIndex={-1} ref={headingRef}>Request sent</h1>
        <p className="ems-result-number" role="status">{request.number}</p>
        <dl className="ems-review-facts">
            <div><dt>For</dt><dd>{request.target_label}{request.event_assignment ? `, ${request.event_assignment}` : ''}</dd></div>
            <div><dt>Items</dt><dd>{itemsLabel(items)}</dd></div>
        </dl>
        <p className="ems-hint">A PDF copy is saved under Recent records in Forms. Captain 5 will review it, and you can follow its status or send a message from My medical requests.</p>
        <div className="ems-result-actions">
            <Button appearance="primary" onClick={onView}>View request</Button>
            <Button onClick={onNew}>New request</Button>
            <Button appearance="subtle" onClick={onMine}>My medical requests</Button>
            <Button appearance="subtle" onClick={onBack}>Back to Forms</Button>
        </div>
    </section>;
}

function QueuedNotice({ reason, message, outbox, onNew, onBack }: {
    reason: 'offline' | 'server' | 'auth'; message: string; outbox: EmsOutbox; onNew: () => void; onBack: () => void;
}) {
    const headingRef = useRef<HTMLHeadingElement | null>(null);
    useEffect(() => { headingRef.current?.focus(); }, []);
    const title = reason === 'auth' ? 'Sign in again to send' : 'Saved on this device';
    const detail = reason === 'offline'
        ? "Saved on this device. We'll send it when you're back online."
        : reason === 'auth'
            ? 'Your sign-in has ended. Your request is saved on this device. Sign in again and it will be sent.'
            : `${message} Your request is saved on this device and we will keep trying.`;
    return <section className="ems-result" aria-labelledby="ems-queued-title">
        <div className="ems-result-icon is-warning"><WifiOff size={26} aria-hidden="true" /></div>
        <h1 id="ems-queued-title" tabIndex={-1} ref={headingRef}>{title}</h1>
        <p role="status">{detail}</p>
        <div className="ems-result-actions">
            {reason === 'auth'
                ? <Button appearance="primary" onClick={() => window.location.reload()}>Sign in again</Button>
                : <Button appearance="primary" disabled={outbox.flushing} onClick={() => void outbox.flush()}>{outbox.flushing ? 'Sending' : 'Try sending now'}</Button>}
            <Button onClick={onNew}>Start another request</Button>
            <Button appearance="subtle" onClick={onBack}>Back to Forms</Button>
        </div>
    </section>;
}
