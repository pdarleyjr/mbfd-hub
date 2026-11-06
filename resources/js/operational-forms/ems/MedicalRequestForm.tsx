import { useCallback, useDeferredValue, useEffect, useRef, useState } from 'react';
import { Button, MessageBar, MessageBarActions, MessageBarBody, MessageBarTitle } from '@fluentui/react-components';
import { AlertTriangle, ArrowLeft, ListChecks } from 'lucide-react';
import { EmsApiError, type EmsApi } from './emsApi';
import {
    blankDraft, buildPayload, bumpQuantity, retiredCount, classifyFailure, countRequested, defaultTargetChoice, discardRetired, draftStoreKey, familyOfDefinition,
    familyShortTitle, familyTitle, fieldForError, groupSections, hasUnsavedWork, itemDomId, itemsLabel, newSubmissionId, payloadItemCount, readiness, restoreDraft,
    serializeDraft, setAnswer, setQuantity, unitForChoice, withDefaultTarget,
    CANNOT_REVISE_MESSAGE, isCannotRevise, isUuid, reviseStoreKey, revisionBanner,
} from './emsLogic.js';
import { emsDrafts } from './emsStore';
import type { EmsDraft, EmsForm, EmsFormDefinition, EmsOutboxEntry, EmsRequestDetail } from './emsTypes';
import { QueuedNotice, SentConfirmation } from './FormResults';
import { ItemBrowser } from './ItemBrowser';
import { ItemSearch } from './ItemSearch';
import { PendingRequests } from './PendingRequests';
import { ReviewSheet, type ReviewEditTarget } from './ReviewSheet';
import { UnitPicker } from './UnitPicker';
import type { EmsOutbox } from './useEmsOutbox';

type Result =
    | { kind: 'sent'; request: EmsRequestDetail; replaces: string | null }
    | { kind: 'queued'; reason: 'offline' | 'server' | 'auth'; message: string };

interface FieldErrors { target?: string; event?: string; note?: string; items?: string }

/** Where to move the member's attention once the screen has the element: an item's quantity control, or a section's heading button. */
interface PendingFocus { kind: 'item' | 'section'; id: string }

const PERSIST_DELAY_MS = 400;
/** Frames to wait for an element (and for the review sheet to finish closing) before giving up on moving focus. */
const FOCUS_FRAMES = 60;

function reducedMotion() {
    return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export interface MedicalRequestFormProps {
    api: EmsApi;
    owner: string;
    /** One form's lists (ALS Transport, ALS Non Transport, Fire Boat 6 or BLS Detail). Everything on screen and on the device belongs to it alone. */
    definition: EmsFormDefinition;
    outbox: EmsOutbox;
    /** A request sent back for fixing ("Edit and resend"); otherwise the saved draft is restored. */
    startFrom: EmsDraft | null;
    /** True when `startFrom` is a correction picked up again from this device rather than started from the sent form. */
    resumed?: boolean;
    onStartConsumed: () => void;
    /** Opens the plain form of a family (used after a correction, so a new request starts from the member's own saved draft). */
    goForm?: (family?: string) => void;
    goForms: () => void;
    goMine: () => void;
    goDetail: (id: string) => void;
    editEntry: (entry: EmsOutboxEntry) => void;
    onSubmitted: () => void;
}

export function MedicalRequestForm({ api, owner, definition, outbox, startFrom, resumed = false, onStartConsumed, goForm, goForms, goMine, goDetail, editEntry, onSubmitted }: MedicalRequestFormProps) {
    const family = familyOfDefinition(definition);
    const [draft, setDraft] = useState<EmsDraft | null>(null);
    const [restored, setRestored] = useState(false);
    const [query, setQuery] = useState('');
    const [matches, setMatches] = useState(0);
    const [enteredOnly, setEnteredOnly] = useState(false);
    const [pinned, setPinned] = useState<ReadonlySet<string>>(() => new Set());
    const [activeBag, setActiveBag] = useState('');
    const deferredQuery = useDeferredValue(query);
    const [openPanels, setOpenPanels] = useState<Record<string, boolean>>({});
    const [unitOpen, setUnitOpen] = useState(false);
    const [pendingFocus, setPendingFocus] = useState<PendingFocus | null>(null);
    const [reviewOpen, setReviewOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [reviewError, setReviewError] = useState('');
    const [errors, setErrors] = useState<FieldErrors>({});
    const [result, setResult] = useState<Result | null>(null);
    /** The server said the returned form was already corrected or cancelled. What the member typed is kept. */
    const [stale, setStale] = useState(false);
    const discardedRef = useRef(false);
    const sendingRef = useRef(false);
    const resultRef = useRef<Result | null>(null);
    const retiredAfterSend = useRef<{ retired: NonNullable<EmsDraft['retired']>; labels?: EmsDraft['labels'] } | null>(null);
    const latestDraft = useRef<EmsDraft | null>(null);
    const targetRef = useRef<HTMLDivElement | null>(null);
    const searchRef = useRef<HTMLInputElement | null>(null);
    const topRef = useRef<HTMLDivElement | null>(null);
    const reviewButtonRef = useRef<HTMLButtonElement | null>(null);

    const max = definition.limits.max_quantity;
    const controlledNote = definition.rules.controlled_substance_note;
    const presetUnit = defaultTargetChoice(definition);
    // A correction is kept in its own row, so the normal unfinished request of any form on this device is never touched.
    // ALS Transport keeps the legacy row (the employee id); every other form has a row of its own.
    const storeKeyOf = useCallback((value: EmsDraft) => (isUuid(value.revises) ? reviseStoreKey(owner, value.revises as string) : draftStoreKey(owner, family)), [owner, family]);

    // Open with the request sent back for fixing, else with whatever was saved on this device for this form.
    useEffect(() => {
        let active = true;
        const begin = async () => {
            if (startFrom) {
                setDraft(startFrom); setRestored(resumed); onStartConsumed();
                return;
            }
            const saved = restoreDraft(await emsDrafts.get(draftStoreKey(owner, family)), definition);
            if (!active) return;
            setDraft(withDefaultTarget(saved ?? blankDraft(undefined, family), definition));
            setRestored(saved !== null && (countRequested(saved) > 0 || (saved.targetChoice !== '' && saved.targetChoice !== presetUnit) || retiredCount(saved) > 0));
        };
        void begin();
        return () => { active = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [owner, definition]);

    useEffect(() => { latestDraft.current = draft; }, [draft]);
    useEffect(() => { resultRef.current = result; }, [result]);

    // Keep the unfinished request on this device: quantities, the unit choice and the submission id only.
    const persist = useCallback(async (value: EmsDraft | null) => {
        if (!value || discardedRef.current) return;
        const stored = serializeDraft(value, undefined, definition);
        if (stored) await emsDrafts.put(storeKeyOf(value), stored);
        else await emsDrafts.remove(storeKeyOf(value));
    }, [storeKeyOf, definition]);

    useEffect(() => {
        if (!draft || result) return;
        const timer = window.setTimeout(() => void persist(draft), PERSIST_DELAY_MS);
        return () => window.clearTimeout(timer);
    }, [draft, result, persist]);

    useEffect(() => {
        const flush = () => { if (!resultRef.current) void persist(latestDraft.current); };
        const hidden = () => { if (document.visibilityState === 'hidden') flush(); };
        window.addEventListener('pagehide', flush);
        document.addEventListener('visibilitychange', hidden);
        // Leaving the form inside the app (to the library, another form, My requests) must not drop the last 400 ms of typing.
        return () => {
            window.removeEventListener('pagehide', flush); document.removeEventListener('visibilitychange', hidden);
            if (!sendingRef.current) flush();
        };
    }, [persist]);

    const onType = useCallback((key: string, raw: string) => setDraft((d) => (d ? setQuantity(d, key, raw, max) : d)), [max]);
    const onBump = useCallback((key: string, delta: number) => setDraft((d) => (d ? bumpQuantity(d, key, delta, max) : d)), [max]);
    const onAnswer = useCallback((key: string, value: boolean | null) => setDraft((d) => (d ? setAnswer(d, key, value) : d)), []);
    const patch = useCallback((change: Partial<EmsDraft>) => setDraft((d) => (d ? { ...d, ...change } : d)), []);

    // "Entered only" keeps a row on screen while its quantity is being retyped (cleared, then typed again).
    useEffect(() => {
        if (!enteredOnly || !draft) return;
        setPinned((current) => {
            const keys = [...Object.keys(draft.quantities), ...Object.keys(draft.answers)];
            if (keys.every((key) => current.has(key))) return current;
            return new Set([...current, ...keys]);
        });
    }, [enteredOnly, draft]);
    const toggleEnteredOnly = (on: boolean) => { setPinned(new Set()); setEnteredOnly(on); };

    const searching = deferredQuery.trim() !== '';
    // One list at a time (Airway Bag, ALS Med Bag/Box, ...); a search looks through all of this form's lists.
    const bagKey = definition.forms.some((form) => form.key === activeBag) ? activeBag : (definition.forms[0]?.key ?? '');
    const firstPanelOf = (formKey: string) => {
        const form = definition.forms.find((candidate) => candidate.key === formKey);
        return form ? groupSections(form.sections)[0]?.section.key : undefined;
    };
    const isOpen = (formKey: string, sectionKey: string) => openPanels[`${formKey}:${sectionKey}`] ?? sectionKey === firstPanelOf(formKey);
    const togglePanel = (formKey: string, sectionKey: string) => setOpenPanels((state) => ({ ...state, [`${formKey}:${sectionKey}`]: !isOpen(formKey, sectionKey) }));
    const setAllPanels = (form: EmsForm, open: boolean) => setOpenPanels((state) => {
        const next = { ...state };
        for (const { section } of groupSections(form.sections)) next[`${form.key}:${section.key}`] = open;
        return next;
    });
    const chooseBag = (key: string) => {
        setActiveBag(key);
        window.requestAnimationFrame(() => document.getElementById('ems-bags')?.scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' }));
    };
    const jumpToSection = (formKey: string, sectionKey: string) => {
        setOpenPanels((state) => ({ ...state, [`${formKey}:${sectionKey}`]: true }));
        setPendingFocus({ kind: 'section', id: `ems-toggle-${formKey}-${sectionKey}` });
    };

    const focusWithScroll = (element: HTMLElement | null, block: ScrollLogicalPosition = 'center') => {
        if (!element) return;
        element.scrollIntoView({ block, behavior: reducedMotion() ? 'auto' : 'smooth' });
        element.focus({ preventScroll: true });
    };

    // Move focus to an item or a section once it is on screen (a section has to open first, and the review sheet has to finish closing).
    useEffect(() => {
        if (!pendingFocus) return undefined;
        let frame = 0;
        let handle = 0;
        const attempt = () => {
            frame += 1;
            const target = pendingFocus.kind === 'item'
                ? document.getElementById(pendingFocus.id)?.querySelector<HTMLElement>('input.ems-qty-input, button.ems-choice') ?? null
                : document.getElementById(pendingFocus.id);
            if (target && frame >= 2 && !document.querySelector('.ems-sheet')) {
                focusWithScroll(target, pendingFocus.kind === 'item' ? 'center' : 'start');
                setPendingFocus(null);
                return;
            }
            if (frame >= FOCUS_FRAMES) { setPendingFocus(null); return; }
            handle = window.requestAnimationFrame(attempt);
        };
        handle = window.requestAnimationFrame(attempt);
        return () => window.cancelAnimationFrame(handle);
    }, [pendingFocus]);

    const chooseUnit = (value: string) => {
        patch({ targetChoice: value });
        setErrors((current) => ({ ...current, target: undefined }));
    };

    const startNew = () => {
        // After a correction the page is still addressed to the returned form: go to the plain form, which opens the member's own saved request.
        if (isUuid(latestDraft.current?.revises) && goForm) { goForm(family); return; }
        const fresh = blankDraft(undefined, family);
        setResult(null); setDraft(withDefaultTarget(retiredAfterSend.current ? { ...fresh, ...retiredAfterSend.current } : fresh, definition));
        setRestored(false); setQuery(''); setEnteredOnly(false); setOpenPanels({}); setActiveBag(''); setErrors({}); setReviewError(''); setUnitOpen(false);
        window.scrollTo({ top: 0, behavior: reducedMotion() ? 'auto' : 'smooth' });
    };

    const clearForm = () => {
        if (!draft || !window.confirm('Clear everything you entered on this form?')) return;
        void emsDrafts.remove(storeKeyOf(draft));
        setDraft(withDefaultTarget(blankDraft(undefined, family), definition)); setRestored(false); setErrors({}); setQuery(''); setEnteredOnly(false);
    };

    // Throwing away a correction removes only the correction. The returned form stays as Captain 5 sent it back.
    const discardCorrection = async () => {
        if (!draft || !window.confirm('Throw away your changes? The returned form stays as Captain 5 sent it back, and you can fix it again.')) return;
        discardedRef.current = true;
        await emsDrafts.remove(storeKeyOf(draft));
        goMine();
    };

    const ready = draft ? readiness(draft) : null;
    const retired = draft ? retiredCount(draft) : 0;

    const openReview = () => {
        if (!draft || !ready) return;
        if (ready.needsTarget) {
            setUnitOpen(true);
            setErrors((current) => ({ ...current, target: 'Choose a unit, or Detail / Special Event, so Captain 5 knows where the supplies go.' }));
            window.setTimeout(() => focusWithScroll(targetRef.current), 0);
            return;
        }
        if (ready.needsContent) {
            setErrors((current) => ({ ...current, items: 'Enter how many you need of at least one item, or write a note, then review.' }));
            focusWithScroll(searchRef.current);
            return;
        }
        setReviewError(''); setErrors({}); setReviewOpen(true);
    };

    /** "Edit" on a review line: back to the form, on that item's list and section, with its quantity focused. */
    const editFromReview = (target: ReviewEditTarget) => {
        setQuery(''); setEnteredOnly(false); setActiveBag(target.formKey);
        setOpenPanels((state) => ({ ...state, [`${target.formKey}:${target.panelKey}`]: true }));
        setReviewOpen(false);
        setPendingFocus({ kind: 'item', id: itemDomId(target.formKey, target.itemId) });
    };

    const send = async () => {
        if (!draft || sendingRef.current) return; // a double tap cannot send twice
        sendingRef.current = true; setSending(true); setReviewError('');
        const payload = buildPayload(draft, definition);
        const unit = unitForChoice(draft.targetChoice, definition.units);
        const fixing = isUuid(draft.revises);
        try {
            // A request that comes back already replayed (same client_submission_id) is a success like any other.
            const outcome = await api.submit(payload);
            await emsDrafts.remove(storeKeyOf(draft));
            // Entries kept for items that are not on the form right now are not part of what was sent; they stay saved.
            // A correction's own leftovers end with the correction; they never move into the normal saved request.
            retiredAfterSend.current = !fixing && draft.retired ? { retired: draft.retired, labels: draft.labels } : null;
            const leftover = !fixing && draft.retired ? serializeDraft({ ...blankDraft(undefined, family), retired: draft.retired, labels: draft.labels }, undefined, definition) : null;
            if (leftover) await emsDrafts.put(draftStoreKey(owner, family), leftover);
            await outbox.discard(payload.client_submission_id);
            resultRef.current = { kind: 'sent', request: outcome.request, replaces: fixing ? (draft.revisesNumber || null) : null };
            setResult(resultRef.current);
            setReviewOpen(false);
            onSubmitted();
        } catch (error) {
            if (!(error instanceof EmsApiError)) {
                console.error('The medical inventory request could not be sent.', error);
                setReviewError('Something went wrong on this device. Your entries are still here. Try again.');
            } else {
                await handleFailure(error, payload, unit?.label ?? draft.targetChoice, draft);
            }
        } finally {
            sendingRef.current = false; setSending(false);
        }
    };

    const handleFailure = async (error: EmsApiError, payload: ReturnType<typeof buildPayload>, unitLabel: string, sent: EmsDraft) => {
        if (isCannotRevise(error)) {
            // Already corrected or cancelled: say so, keep everything typed (it stays saved on this device), and point to My requests.
            setReviewOpen(false); setStale(true);
            window.scrollTo({ top: 0, behavior: reducedMotion() ? 'auto' : 'smooth' });
            return;
        }
        const kind = classifyFailure({ status: error.status, code: error.code, network: error.network });
        if (kind === 'message') {
            const field = fieldForError(error.code, error.fields);
            if (error.code === 'duplicate_submission') patch({ clientSubmissionId: newSubmissionId() });
            if (field === 'target' || field === 'event' || field === 'note') {
                // A refused unit is never kept: the member picks it again.
                if (error.code === 'unit_mismatch') patch({ targetChoice: '' });
                setReviewOpen(false); setUnitOpen(true);
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
            state: 'pending', unitLabel, itemCount: payloadItemCount(payload), familyShort: familyShortTitle(definition),
            ...(isUuid(sent.revises) ? { revision: { number: sent.revisesNumber ?? '', reason: sent.returnReason ?? '' } } : {}),
        };
        const saved = await outbox.enqueue(entry);
        if (!saved) {
            setReviewError('This could not be sent and this browser could not save it. Keep this page open and try again.');
            return;
        }
        await emsDrafts.remove(storeKeyOf(sent));
        resultRef.current = { kind: 'queued', reason: kind === 'auth' ? 'auth' : error.network ? 'offline' : 'server', message: error.message };
        setResult(resultRef.current);
        setReviewOpen(false);
    };

    if (!draft || !ready) return <div className="ems-app"><p className="ems-loading" role="status">Opening the form</p></div>;

    if (result?.kind === 'sent') {
        return <div className="ems-app"><SentConfirmation request={result.request} replaces={result.replaces} onView={() => goDetail(result.request.id)} onNew={startNew} onBack={goForms} onMine={goMine} /></div>;
    }
    if (result?.kind === 'queued') {
        return <div className="ems-app">
            <QueuedNotice reason={result.reason} message={result.message} outbox={outbox} replaces={revisionBanner(draft) ? (draft.revisesNumber || 'the earlier form') : null} onNew={startNew} onBack={goForms} />
            <PendingRequests outbox={outbox} openRequest={goDetail} editEntry={editEntry} />
        </div>;
    }

    const requestedCount = countRequested(draft);
    const enteredCount = Object.keys(draft.quantities).length + Object.keys(draft.answers).length;
    const isDetail = draft.targetChoice === 'detail';
    const unit = unitForChoice(draft.targetChoice, definition.units);
    const fixing = revisionBanner(draft);
    // A unit the form preselected is shown as one line with "Change unit"; no unit yet, or a refused one, always shows the picker.
    const unitCollapsed = !unitOpen && !errors.target && draft.targetChoice !== '' && draft.targetChoice === presetUnit && !fixing;

    return <div className="ems-app has-bar" ref={topRef} data-family={family}>
        <div className="ems-topline">
            <Button appearance="subtle" icon={<ArrowLeft size={17} />} onClick={goForms}>Forms library</Button>
            <Button appearance="subtle" icon={<ListChecks size={17} />} onClick={goMine}>My medical requests</Button>
        </div>
        <header className="ems-head">
            <p className="of-eyebrow">Captain 5 EMS supply</p>
            <h1>{familyTitle(definition)}</h1>
            <p className={`ems-head-unit${unit ? '' : ' is-empty'}`} aria-live="polite">
                {unit ? <>For <strong>{unit.label}</strong></> : 'Choose a unit to begin.'}
            </p>
            {fixing
                ? <p>Change what Captain 5 asked about, then send it again. The corrected form replaces the earlier one.</p>
                : <p>Find a supply, enter how many you need, and send. Leave the rest blank; a partial request is fine.</p>}
        </header>

        {fixing && !stale && <MessageBar intent="warning" className="ems-revision-banner" role="region" aria-label="Correcting a returned form">
            <MessageBarBody><strong>{fixing.title}</strong> {fixing.reason}</MessageBarBody>
        </MessageBar>}

        {stale && <MessageBar intent="error" className="ems-revision-stale" role="alert">
            <MessageBarBody><MessageBarTitle>This form can no longer be corrected</MessageBarTitle>{CANNOT_REVISE_MESSAGE}</MessageBarBody>
            <MessageBarActions><Button size="small" appearance="primary" onClick={goMine}>Go to My medical requests</Button></MessageBarActions>
        </MessageBar>}

        <PendingRequests outbox={outbox} openRequest={goDetail} editEntry={editEntry} />

        {restored && (fixing
            ? <MessageBar intent="info"><MessageBarBody>Your correction was restored from this device. The note and event text show what you sent before, because they are not kept on the device.</MessageBarBody></MessageBar>
            : <MessageBar intent="info"><MessageBarBody>Your unfinished request was restored from this device. Notes and event text are not kept when you leave, so add yours again if you had one.</MessageBarBody></MessageBar>)}

        {retired > 0 && <MessageBar intent="warning" className="ems-retired"><MessageBarBody>
            {retired === 1 ? '1 saved entry is' : `${retired} saved entries are`} for items that are no longer on this form. {retired === 1 ? 'It is' : 'They are'} kept on this device and not sent; you can see {retired === 1 ? 'it' : 'them'} when you review.
        </MessageBarBody></MessageBar>}

        <section className="ems-card" aria-labelledby="ems-target-label">
            <p id="ems-target-label" className="ems-label">Completing this form for:</p>
            {unitCollapsed && <div className="ems-unit-summary">
                <span>For: <strong>{unit?.label}</strong></span>
                <Button appearance="subtle" aria-expanded={false} aria-controls="ems-target" onClick={() => setUnitOpen(true)}>Change unit</Button>
            </div>}
            <div hidden={unitCollapsed}>
                <UnitPicker
                    ref={targetRef} units={definition.units} value={draft.targetChoice} onChange={chooseUnit}
                    describedBy={`ems-target-hint${errors.target ? ' ems-target-error' : ''}`} invalid={Boolean(errors.target)}
                />
            </div>
            <p id="ems-target-hint" className="ems-hint">Tap your unit. Captain 5 uses this to know where the supplies go.</p>
            {errors.target && <p id="ems-target-error" className="ems-field-error" role="alert"><AlertTriangle size={15} aria-hidden="true" /> {errors.target}</p>}
            {isDetail && <div className="ems-event">
                <label htmlFor="ems-event" className="ems-label">Unit, assignment, or position for this event <span className="ems-optional">optional</span></label>
                <input id="ems-event" className="ems-text" type="text" maxLength={definition.limits.event_assignment_max} autoComplete="off" value={draft.eventAssignment}
                    aria-invalid={Boolean(errors.event)} aria-describedby={errors.event ? 'ems-event-error' : undefined}
                    onChange={(event) => { patch({ eventAssignment: event.target.value }); setErrors((current) => ({ ...current, event: undefined })); }} />
                {errors.event && <p id="ems-event-error" className="ems-field-error" role="alert"><AlertTriangle size={15} aria-hidden="true" /> {errors.event}</p>}
            </div>}
        </section>

        <ItemSearch
            inputRef={searchRef} query={query} onQuery={setQuery} searching={searching} shownQuery={deferredQuery} matches={matches}
            enteredOnly={enteredOnly} onEnteredOnly={toggleEnteredOnly} enteredCount={enteredCount} error={errors.items}
        />

        <ItemBrowser
            definition={definition} draft={draft} query={deferredQuery} enteredOnly={enteredOnly} pinned={pinned} bagKey={bagKey}
            onChooseBag={chooseBag} isOpen={isOpen} onTogglePanel={togglePanel} onSetAllPanels={setAllPanels} onJump={jumpToSection}
            controlledNote={controlledNote} onType={onType} onBump={onBump} onAnswer={onAnswer} onMatches={setMatches}
        />

        <section className="ems-card" aria-labelledby="ems-note-label">
            <label id="ems-note-label" htmlFor="ems-note" className="ems-label">Note for Captain 5 <span className="ems-optional">optional</span></label>
            <textarea id="ems-note" className="ems-textarea" rows={3} maxLength={definition.limits.note_max} value={draft.note}
                aria-invalid={Boolean(errors.note)} aria-describedby={errors.note ? 'ems-note-error' : undefined}
                onChange={(event) => { patch({ note: event.target.value }); setErrors((current) => ({ ...current, note: undefined, items: undefined })); }} />
            {errors.note && <p id="ems-note-error" className="ems-field-error" role="alert"><AlertTriangle size={15} aria-hidden="true" /> {errors.note}</p>}
            {(draft.note.length > definition.limits.note_max * 0.8) && <p className="ems-hint">{draft.note.length} of {definition.limits.note_max} characters</p>}
        </section>

        {fixing
            ? <div className="ems-clear"><Button appearance="subtle" onClick={() => void discardCorrection()}>Discard my changes</Button></div>
            : hasUnsavedWork(draft, definition) && <div className="ems-clear"><Button appearance="subtle" onClick={clearForm}>Clear form</Button></div>}

        <div className="ems-bar" role="region" aria-label="Request summary">
            <div className="ems-bar-summary">
                <strong className="ems-bar-count" role="status" aria-live="polite" aria-atomic="true">{itemsLabel(requestedCount)}</strong>
                {unit && <span className="ems-bar-unit">For <strong>{unit.label}</strong></span>}
                <span className={`ems-bar-hint${ready.ready ? '' : ' is-pending'}`}>{ready.message}</span>
            </div>
            <Button ref={reviewButtonRef} appearance="primary" size="large" className="ems-bar-action" onClick={openReview}>Review and send</Button>
        </div>

        <ReviewSheet
            open={reviewOpen} definition={definition} draft={draft} onDiscardRetired={() => setDraft((d) => (d ? discardRetired(d) : d))} onEditItem={editFromReview}
            unitLabel={unit?.label ?? ''} sending={sending} error={reviewError}
            onBack={() => { if (!sending) { setReviewOpen(false); window.setTimeout(() => reviewButtonRef.current?.focus(), 0); } }} onSend={() => void send()}
        />
    </div>;
}
