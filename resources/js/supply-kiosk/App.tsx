// The supply room screen: ID -> unit -> find -> quantity -> cart -> Take -> Done, and back. It never logs out.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import * as api from './api.ts';
import { addLine, buildWithdrawal, findMember, MAX_LINES, memberIndex, newUuid, recordSearch, removeLine, setLineQuantity, setLineUnit, unitWords } from './cart.ts';
import { CartScreen } from './CartScreen.tsx';
import { DoneScreen } from './DoneScreen.tsx';
import { FindScreen } from './FindScreen.tsx';
import type { FindMode } from './FindScreen.tsx';
import { useInterval, useLastActivity, useNow } from './hooks.ts';
import { IdScreen } from './IdScreen.tsx';
import { PairScreen } from './PairScreen.tsx';
import { CartBar, IdleDialog, NoteDialog, Toast } from './Overlays.tsx';
import { QuantitySheet } from './QuantitySheet.tsx';
import { Notices, undoNotice, withdrawalNotice } from './Notices.tsx';
import type { Notice } from './Notices.tsx';
import { flushQueue, isInFlight, isQueued, queueDelete, queuePut, refusedEntries, waitForSend, whileSending } from './queue.ts';
import type { SendOutcome } from './queue.ts';
import { addPendingUndo, flushUndos, pendingUndos, removePendingUndo } from './undoLater.ts';
import type { UndoOutcome } from './undoLater.ts';
import { buildSearchIndex } from './search.ts';
import type { Bootstrap, CartLine, Member, Receipt, SearchRecord, SupplyItem, UnitChoice, WithdrawalBody, WithdrawalResult } from './types.ts';
import { UnitScreen } from './UnitScreen.tsx';

type Screen = 'loading' | 'unreachable' | 'not_paired' | 'id' | 'unit' | 'find' | 'cart' | 'done';
/** After Undo: put back (200), never arrived (404), or waiting for the connection to send the undo. */
export type UndoState = null | 'put_back' | 'nothing_taken' | 'pending';

const BOOTSTRAP_EVERY_MS = 5 * 60_000;
const HEARTBEAT_EVERY_MS = 60_000;
const IDLE_AFTER_MS = 60_000;
const IDLE_GRACE_MS = 10_000;
const UNDO_MS = 60_000;
const TOAST_MS = 1_800;
const RETRY_LOAD_MS = 15_000;
const RELOAD_HOUR = 3;
const LOADED_AT = Date.now();
const SETUP: readonly Screen[] = ['loading', 'unreachable', 'not_paired'];
const EMPTY_BOOT: Bootstrap = { device: { name: '' }, csrf_token: '', generated_at: '', members: [], units: [], locations: [], items: [], most_taken: [], shorthand: {} };

function receiptFrom(result: WithdrawalResult, replayed: boolean): Receipt {
    const span = Date.parse(result.undo_until) - Date.parse(result.taken_at);
    const local = Number.isFinite(span) ? Date.now() + Math.max(0, Math.min(UNDO_MS, span)) : Date.now() + UNDO_MS;
    const absolute = Date.parse(result.undo_until);
    return {
        uuid: result.uuid, queued: false, number: result.number, employeeName: result.employee_name, unitLabel: result.unit_label,
        lines: result.lines, note: result.note, copy: result.copy,
        // A replay may be an old withdrawal; then trust the server's own deadline.
        undoUntil: replayed && Number.isFinite(absolute) ? Math.min(local, absolute) : local,
    };
}

/** What the ID screen says about a withdrawal the Hub refused: its own words when it gave some. */
function refusalText(failure: unknown): string {
    return failure instanceof api.KioskError && failure.message && failure.status >= 400 && failure.status < 500 ? failure.message : api.plainMessage(failure);
}

async function sendQueued(body: WithdrawalBody): Promise<SendOutcome> {
    try {
        await api.postWithdrawal(body);
        return { kind: 'sent' };
    } catch (failure) {
        if (api.isRetryable(failure) || (failure instanceof api.KioskError && failure.code === 'kiosk_not_paired')) return { kind: 'retry' };
        // The member undid it on the screen before it ever arrived: nothing was taken, so nothing is left to report.
        if (failure instanceof api.KioskError && failure.code === 'withdrawal_undone') return { kind: 'sent' };
        console.error('Supply kiosk: the Hub refused a saved withdrawal; it stays on the ID screen until Captain 5 is told.', failure);
        return { kind: 'refused', message: refusalText(failure) };
    }
}

type UndoAnswer = 'put_back' | 'nothing_taken' | { closed: string };

/** One undo POST. 200 put it back, 404 means it never arrived, 409 (or another refusal) says why not. Throws without an answer. */
async function undoOnce(uuid: string): Promise<UndoAnswer> {
    try {
        await api.postUndo(uuid);
        return 'put_back';
    } catch (failure) {
        if (!(failure instanceof api.KioskError) || api.isRetryable(failure) || failure.code === 'kiosk_not_paired') throw failure;
        if (failure.status === 404) return 'nothing_taken';
        return { closed: refusalText(failure) };
    }
}

async function sendUndo(uuid: string): Promise<UndoOutcome> {
    try {
        const answer = await undoOnce(uuid);
        return typeof answer === 'string' ? { kind: 'done' } : { kind: 'failed', message: answer.closed };
    } catch {
        return { kind: 'retry' };
    }
}

function queuedReceipt(body: WithdrawalBody, member: Member, unit: UnitChoice, cart: CartLine[]): Receipt {
    return {
        uuid: body.client_uuid, queued: true, number: null, employeeName: member.name, unitLabel: unit?.display ?? null,
        lines: cart.map(line => ({ name: line.item.name, quantity: line.quantity, unit_label: unitWords(line.item, line.unit, line.quantity) })),
        note: body.note, copy: null, undoUntil: Date.now() + UNDO_MS,
    };
}

export function App() {
    const [boot, setBoot] = useState<Bootstrap | null>(null);
    const [screen, setScreen] = useState<Screen>('loading');
    const [typed, setTyped] = useState('');
    const [member, setMember] = useState<Member | null>(null);
    const [unit, setUnit] = useState<UnitChoice>(null);
    const [cart, setCart] = useState<CartLine[]>([]);
    const [searches, setSearches] = useState<SearchRecord[]>([]);
    const [note, setNote] = useState('');
    const [mode, setMode] = useState<FindMode>('search');
    const [query, setQuery] = useState('');
    const [sheet, setSheet] = useState<{ item: SupplyItem; term: string } | null>(null);
    const [noteOpen, setNoteOpen] = useState(false);
    const [toast, setToast] = useState<string | null>(null);
    const [idleUntil, setIdleUntil] = useState<number | null>(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [receipt, setReceipt] = useState<Receipt | null>(null);
    const [undone, setUndone] = useState<UndoState>(null);
    const [notices, setNotices] = useState<Notice[]>([]);
    const [undoing, setUndoing] = useState(false);
    const [doneError, setDoneError] = useState<string | null>(null);
    const orderUuid = useRef<string | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const toastTimer = useRef<number | undefined>(undefined);
    const hasBoot = useRef(false);

    const data = boot ?? EMPTY_BOOT;
    const index = useMemo(() => buildSearchIndex(data.items, data.shorthand), [data.items, data.shorthand]);
    const itemsById = useMemo(() => new Map(data.items.map(item => [item.id, item])), [data.items]);
    const mostTaken = useMemo(() => data.most_taken.map(id => itemsById.get(id)).filter((item): item is SupplyItem => !!item), [data.most_taken, itemsById]);
    const membersById = useMemo(() => memberIndex(data.members), [data.members]);
    const longestId = useMemo(() => data.members.reduce((max, m) => Math.max(max, String(m.employee_id).length), 0), [data.members]);
    const cartCounts = useMemo(() => {
        const counts = new Map<number, number>();
        cart.forEach(line => counts.set(line.item.id, (counts.get(line.item.id) ?? 0) + line.quantity));
        return counts;
    }, [cart]);
    const typedMember = findMember(membersById, typed);

    const reset = useCallback(() => {
        setTyped(''); setMember(null); setUnit(null); setCart([]); setSearches([]); setNote(''); setMode('search'); setQuery('');
        setSheet(null); setNoteOpen(false); setIdleUntil(null); setError(null); setReceipt(null); setUndone(null); setUndoing(false);
        setDoneError(null); orderUuid.current = null;
        setScreen(current => (SETUP.includes(current) ? current : 'id'));
        window.scrollTo({ top: 0 });
    }, []);

    const unpaired = useCallback(() => { reset(); setScreen('not_paired'); }, [reset]);

    const refreshNotices = useCallback(async () => {
        const refused = await refusedEntries();
        setNotices([...refused.map(withdrawalNotice), ...pendingUndos().filter(entry => entry.failed).map(undoNotice)]);
    }, []);

    const clearNotice = useCallback(async (notice: Notice) => {
        if (notice.kind === 'withdrawal') await queueDelete(notice.uuid);
        else removePendingUndo(notice.uuid);
        await refreshNotices();
    }, [refreshNotices]);

    // One flush at a time: the withdrawal queue first, then the undos, so an undo never reaches the Hub before its
    // withdrawal. A flush asked for while one runs (the connection coming back, say) runs right after it.
    const flushing = useRef(false);
    const wanted = useRef<{ force: boolean } | null>(null);
    const flush = useCallback((force = false) => {
        wanted.current = { force: (wanted.current?.force ?? false) || force };
        if (flushing.current) return;
        flushing.current = true;
        void (async () => {
            while (wanted.current) {
                const pass = wanted.current;
                wanted.current = null;
                try {
                    await flushQueue(sendQueued, pass);
                    await flushUndos(sendUndo, async uuid => isInFlight(uuid) || await isQueued(uuid), pass);
                } catch (failure) {
                    console.error('Supply kiosk: sending saved work failed.', failure);
                }
                await refreshNotices();
            }
            flushing.current = false;
        })();
    }, [refreshNotices]);

    const backgroundFailure = useCallback((failure: unknown) => {
        if (failure instanceof api.KioskError && failure.code === 'kiosk_not_paired') unpaired();
        else console.warn('Supply kiosk: background request failed.', failure);
    }, [unpaired]);

    const load = useCallback(async () => {
        try {
            await api.fetchBootstrap();
            flush();
        } catch (failure) {
            // Unpaired (revoked) mid-order: clear the member, cart, unit, note and order uuid too, not just the screen.
            if (failure instanceof api.KioskError && failure.code === 'kiosk_not_paired') unpaired();
            else if (!hasBoot.current) setScreen('unreachable');
            else console.warn('Supply kiosk: bootstrap refresh failed; keeping the last list.', failure);
        }
    }, [flush, unpaired]);

    useEffect(() => {
        api.onBootstrap(next => {
            hasBoot.current = true;
            setBoot(next);
            setScreen(current => (SETUP.includes(current) ? 'id' : current));
        });
        void load();
        void refreshNotices();
        const online = () => { flush(true); if (!hasBoot.current) void load(); };
        window.addEventListener('online', online);
        return () => window.removeEventListener('online', online);
    }, [load, flush, refreshNotices]);

    /** After a pairing code is accepted: the device cookie is set, so bootstrap now answers and the ID screen opens. */
    const afterPairing = useCallback(async (): Promise<boolean> => {
        // The next member always starts empty, whatever was on the screen before it was unpaired.
        reset();
        try {
            await api.fetchBootstrap();
            flush();
            return true;
        } catch (failure) {
            console.warn('Supply kiosk: bootstrap after pairing failed.', failure);
            return false;
        }
    }, [flush, reset]);

    const paired = !SETUP.includes(screen);
    useInterval(() => { void load(); }, paired ? BOOTSTRAP_EVERY_MS : RETRY_LOAD_MS);
    useInterval(() => { api.postHeartbeat().catch(backgroundFailure).finally(flush); }, paired ? HEARTBEAT_EVERY_MS : null);
    // A full reload once a day after 03:00, only while nobody is using the screen.
    useInterval(() => {
        const at = new Date();
        at.setHours(RELOAD_HOUR, 0, 0, 0);
        if (Date.now() < at.getTime() || LOADED_AT >= at.getTime() || screen !== 'id' || typed !== '') return;
        api.fetchBootstrap().then(() => window.location.reload()).catch(backgroundFailure);
    }, 60_000);

    // Idle: 60 s untouched mid-order asks "Still there?", then clears after 10 s more.
    const lastActivity = useLastActivity(useCallback(() => setIdleUntil(null), []));
    const midOrder = screen === 'unit' || screen === 'find' || screen === 'cart';
    useInterval(() => {
        const now = Date.now();
        if (idleUntil !== null) {
            if (!midOrder) setIdleUntil(null);
            else if (now >= idleUntil) reset();
            return;
        }
        if (sending || now - lastActivity.current < IDLE_AFTER_MS) return;
        if (midOrder) setIdleUntil(now + IDLE_GRACE_MS);
        else if (screen === 'id' && typed !== '') setTyped('');
    }, 1000);
    const now = useNow(idleUntil !== null);

    useEffect(() => {
        if (screen === 'find' && mode === 'search' && !sheet && !noteOpen) searchRef.current?.focus({ preventScroll: true });
    }, [screen, mode, sheet, noteOpen]);

    const showToast = (message: string) => {
        window.clearTimeout(toastTimer.current);
        setToast(message);
        toastTimer.current = window.setTimeout(() => setToast(null), TOAST_MS);
    };

    const goFind = (next: FindMode) => { setMode(next); setScreen('find'); window.scrollTo({ top: 0 }); };

    const addToCart = (quantity: number, unitKey: string) => {
        if (!sheet) return;
        const { item, term } = sheet;
        if (cart.length >= MAX_LINES && !cart.some(line => line.item.id === item.id && line.unit === unitKey)) {
            setSheet(null);
            showToast(`The cart holds ${MAX_LINES} lines. Take these first.`);
            return;
        }
        setCart(lines => addLine(lines, item, quantity, unitKey));
        if (term.trim() !== '') setSearches(records => recordSearch(records, term, item.id));
        setSheet(null);
        setQuery('');
        showToast('Added. Next item?');
    };

    const take = async (noteText: string = note) => {
        if (!member || sending || (cart.length === 0 && noteText.trim() === '')) return;
        const uuid = (orderUuid.current ??= newUuid());
        const body = buildWithdrawal(uuid, member, unit, cart, noteText, searches);
        const done = (next: Receipt) => {
            setReceipt(next); setUndone(null); setDoneError(null); setScreen('done');
            setTyped(''); setMember(null); setUnit(null); setCart([]); setSearches([]); setNote(''); setQuery(''); orderUuid.current = null;
            window.scrollTo({ top: 0 });
        };
        setSending(true);
        setError(null);
        await queuePut({ client_uuid: uuid, body, queued_at: Date.now(), member_name: member.name });
        try {
            const { result, replayed } = await whileSending(uuid, () => api.postWithdrawal(body));
            await queueDelete(uuid);
            done(receiptFrom(result, replayed));
        } catch (failure) {
            if (api.isRetryable(failure)) { done(queuedReceipt(body, member, unit, cart)); return; }
            await queueDelete(uuid);
            orderUuid.current = null;
            if (failure instanceof api.KioskError && failure.code === 'kiosk_not_paired') { unpaired(); return; }
            setError(api.plainMessage(failure));
        } finally {
            setSending(false);
        }
    };

    /**
     * Undo always asks the Hub, even for a receipt still "waiting to send": the Take may have landed with its answer
     * lost. A send already under way finishes first, then the local copy is dropped so it is never sent after the undo.
     */
    const undo = async () => {
        if (!receipt || undoing) return;
        const { uuid } = receipt;
        setUndoing(true);
        setDoneError(null);
        try {
            if (receipt.queued) {
                await waitForSend(uuid);
                await queueDelete(uuid);
            }
            const answer = await undoOnce(uuid);
            if (typeof answer === 'string') setUndone(answer);
            else setDoneError(answer.closed);
        } catch (failure) {
            if (api.isRetryable(failure)) {
                // No answer: keep trying from this screen until the Hub says put back, never arrived, or too late.
                addPendingUndo(uuid, receipt.employeeName, Date.now());
                setUndone('pending');
            } else {
                setDoneError(api.plainMessage(failure));
            }
        } finally {
            setUndoing(false);
        }
    };

    const saveNote = (text: string) => {
        setNoteOpen(false);
        setNote(text);
        if (cart.length === 0) void take(text);
    };

    const showMember = member && midOrder;
    return (
        <div className="k-app">
            <header className="k-bar">
                <span className="k-bar-title">Supply room</span>
                {showMember ? <span className="k-bar-member">{member.name}{unit ? `, ${unit.display}` : ''}</span> : null}
                {midOrder ? <button type="button" className="k-bar-button" onClick={reset}>Start over</button> : null}
            </header>
            <main className={`k-main${(screen === 'find' || screen === 'unit') && cart.length > 0 ? ' has-cartbar' : ''}`}>
                {screen === 'loading' ? <p className="k-status" role="status">Loading the supply room</p> : null}
                {screen === 'unreachable' ? (
                    <section className="k-screen k-status-screen">
                        <h1 className="k-title">Cannot reach the Hub</h1>
                        <p className="k-sub">This screen keeps trying. Check the network if this stays up.</p>
                        <button type="button" className="k-way" onClick={() => void load()}>Try now</button>
                    </section>
                ) : null}
                {screen === 'not_paired' ? <PairScreen onPaired={afterPairing} /> : null}
                {screen === 'id' ? <Notices notices={notices} onClear={notice => void clearNotice(notice)} /> : null}
                {screen === 'id' ? (
                    <IdScreen typed={typed} member={typedMember} longestId={longestId} error={error} onChange={setTyped}
                        onGo={() => { if (!typedMember) return; setMember(typedMember); setError(null); setScreen('unit'); }} />
                ) : null}
                {screen === 'unit' && member ? (
                    <UnitScreen memberName={member.name} units={data.units} value={unit} onChange={setUnit}
                        onSearch={() => goFind('search')} onBrowse={() => goFind('browse')} />
                ) : null}
                {screen === 'find' ? (
                    <FindScreen mode={mode} query={query} inputRef={searchRef} index={index} items={data.items} mostTaken={mostTaken}
                        locations={data.locations} cartCounts={cartCounts} onMode={setMode} onQuery={setQuery}
                        onPick={(item, fromSearch) => setSheet({ item, term: fromSearch ? query : '' })} onNote={() => setNoteOpen(true)} />
                ) : null}
                {screen === 'cart' ? (
                    <CartScreen lines={cart} unit={unit} units={data.units} note={note} sending={sending} error={error}
                        onQuantity={(key, quantity) => setCart(lines => setLineQuantity(lines, key, quantity))}
                        onLineUnit={(key, next) => setCart(lines => setLineUnit(lines, key, next))}
                        onRemove={key => setCart(lines => removeLine(lines, key))} onUnit={setUnit}
                        onMore={() => goFind(mode)} onNote={() => setNoteOpen(true)} onTake={() => void take()} />
                ) : null}
                {screen === 'done' && receipt ? (
                    <DoneScreen receipt={receipt} undone={undone} undoing={undoing} error={doneError} onUndo={() => void undo()} onStart={reset} />
                ) : null}
            </main>
            {screen === 'find' || screen === 'unit' ? <CartBar count={cart.length} onReview={() => { setError(null); setScreen('cart'); window.scrollTo({ top: 0 }); }} /> : null}
            {sheet ? <QuantitySheet key={sheet.item.id} item={sheet.item} onAdd={addToCart} onClose={() => setSheet(null)} /> : null}
            {noteOpen ? <NoteDialog initial={note} cartEmpty={cart.length === 0} onSave={saveNote} onClose={() => setNoteOpen(false)} /> : null}
            {idleUntil !== null && midOrder ? <IdleDialog secondsLeft={Math.max(0, Math.min(10, Math.ceil((idleUntil - now) / 1000)))} onStay={() => setIdleUntil(null)} /> : null}
            {/* "Added. Next item?" belongs to the search screen; on the cart it would cover Add more items. */}
            <Toast message={screen === 'find' ? toast : null} />
        </div>
    );
}
