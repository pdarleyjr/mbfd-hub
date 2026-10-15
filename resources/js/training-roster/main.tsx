import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '@fontsource-variable/plus-jakarta-sans';
import './roster.css';

type Member = { id: number; first_name: string; last_name: string; name: string; rank: string; shift: string; term_label: string; identifier_hint: string };
type Roster = { course_name: string; held_on: string; location: string | null; status: string; revision: number; attendance: { name: string; rank: string | null; shift: string | null; signed_at: string }[]; csrf_token: string };
type Step = 'roster' | 'shift' | 'rank' | 'name' | 'signature' | 'success';
type Submission = { employee_profile_id: number; signature: string; client_submission_id: string };
type Receipt = { recorded: boolean; status: 'recorded' | 'unknown' | 'corrected' | 'voided' };
const base = window.location.pathname.replace(/\/$/, '');
const storageKey = `mbfd.training.pending.${base}`;
let csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
let fragmentKey = new URLSearchParams(window.location.hash.slice(1)).get('key');
if (window.location.hash) window.history.replaceState(null, '', window.location.pathname);
class ApiError extends Error { constructor(message: string, readonly status: number) { super(message); } }

async function api<T>(path: string, body?: unknown): Promise<T> {
    const response = await fetch(`${base}/${path}`, {
        method: body === undefined ? 'GET' : 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(15000),
    });
    const data = await response.json().catch(() => ({}));
    if (data.csrf_token) csrf = data.csrf_token;
    if (!response.ok) {
        const errors = data.errors ? Object.values(data.errors).flat().join(' ') : null;
        throw new ApiError(errors || data.message || 'We could not connect to the roster. Please try again.', response.status);
    }
    return data as T;
}

function readReceipt(): string | null {
    try {
        const raw = sessionStorage.getItem(storageKey);
        if (!raw) return null;
        const item = JSON.parse(raw) as { id: string; at: number };
        return Date.now() - item.at < 12 * 3600_000 ? item.id : null;
    } catch { return null; }
}
function rememberReceipt(id?: string) {
    try {
        if (id) sessionStorage.setItem(storageKey, JSON.stringify({ id, at: Date.now() }));
        else sessionStorage.removeItem(storageKey);
    } catch { /* A locked-down browser can still sign using the in-memory receipt. */ }
}
const dateLabel = (date: string) => new Intl.DateTimeFormat('en-US', { dateStyle: 'full', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`));

function Signature({ onSubmit, busy, locked }: { onSubmit: (image: string) => void; busy: boolean; locked: boolean }) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const [ink, setInk] = useState(false);
    const point = (event: React.PointerEvent<HTMLCanvasElement>) => {
        const rect = event.currentTarget.getBoundingClientRect();
        return [(event.clientX - rect.left) * 1000 / rect.width, (event.clientY - rect.top) * 500 / rect.height];
    };
    const clear = () => {
        canvas.current?.getContext('2d')?.clearRect(0, 0, 1000, 500);
        setInk(false);
    };
    return <>
        <div className="signature-frame">
            <canvas ref={canvas} width={1000} height={500} aria-label="Draw your signature with a finger, stylus, or mouse"
                onPointerDown={event => {
                    if (busy || locked) return;
                    event.currentTarget.setPointerCapture(event.pointerId);
                    const context = event.currentTarget.getContext('2d');
                    if (!context) return;
                    drawing.current = true;
                    context.beginPath();
                    const [x, y] = point(event);
                    context.moveTo(x, y);
                    context.strokeStyle = '#102c4c'; context.lineWidth = 3.2; context.lineCap = 'round'; context.lineJoin = 'round';
                }}
                onPointerMove={event => {
                    if (!drawing.current || busy || locked) return;
                    const context = event.currentTarget.getContext('2d');
                    const [x, y] = point(event);
                    context?.lineTo(x, y); context?.stroke(); setInk(true);
                }}
                onPointerUp={() => { drawing.current = false; }}
                onPointerCancel={() => { drawing.current = false; }}
            />
            <span aria-hidden="true">Sign here</span>
        </div>
        <p className="hint">Use your finger, stylus, or mouse. Ask the instructor if you need assistance.</p>
        <div className="signature-actions">
            <button className="secondary" disabled={busy || locked || !ink} onClick={clear}>Clear signature</button>
            <button className="primary" disabled={!ink || busy || locked} onClick={() => { if (canvas.current) onSubmit(canvas.current.toDataURL('image/png')); }}>
                {busy ? 'Saving attendance…' : 'Complete sign-in'}
            </button>
        </div>
    </>;
}

function App() {
    const [roster, setRoster] = useState<Roster | null>(null);
    const [step, setStep] = useState<Step>('roster');
    const [members, setMembers] = useState<Member[]>([]);
    const [shift, setShift] = useState('');
    const [rank, setRank] = useState('');
    const [member, setMember] = useState<Member | null>(null);
    const [query, setQuery] = useState('');
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [pending, setPending] = useState<Submission | null>(null);
    const [uncertain, setUncertain] = useState(false);
    const heading = useRef<HTMLHeadingElement>(null);
    const initial = useRef(false);
    const refresh = async () => { const state = await api<Roster>('state'); setRoster(state); return state; };
    const reset = () => {
        setStep('roster'); setMember(null); setMembers([]); setShift(''); setRank(''); setQuery(''); setError(''); setPending(null); setUncertain(false);
    };
    const saved = () => {
        rememberReceipt(); setPending(null); setUncertain(false); setMember(null); setMembers([]); setShift(''); setRank(''); setQuery(''); setError(''); setStep('success');
        void refresh().catch(() => setNotice('Attendance saved. The roster list will refresh when the connection returns.'));
    };

    const connect = async () => {
        setBusy(true); setError('');
            try {
                await api('csrf');
                if (fragmentKey) { await api('authorize', { token: fragmentKey }); fragmentKey = null; }
                await refresh();
                const receipt = readReceipt();
                if (receipt) {
                    const result = await api<Receipt>('receipt', { client_submission_id: receipt });
                    if (result.recorded) { saved(); return; }
                    rememberReceipt();
                    setNotice(['corrected', 'voided'].includes(result.status)
                        ? 'The instructor has changed the previous attendance record. Ask the instructor to confirm your attendance before signing again.'
                        : 'The previous attempt has no saved receipt. Select your name to sign. Existing attendance is protected from duplicates.');
                }
            } catch (err) { setError((err as Error).message); }
            finally { setBusy(false); }
    };
    useEffect(() => {
        if (initial.current || !base.startsWith('/r/')) return;
        initial.current = true;
        void connect();
    }, []);
    useEffect(() => {
        if (!roster || step !== 'roster') return;
        const timer = window.setInterval(() => {
            if (!document.hidden) void refresh().then(() => setNotice('')).catch(() => setNotice('Connection interrupted. The displayed roster may be out of date.'));
        }, 15000);
        return () => window.clearInterval(timer);
    }, [step, Boolean(roster)]);
    useEffect(() => { heading.current?.focus(); }, [step]);
    useEffect(() => {
        if (step !== 'success') return;
        const timer = window.setTimeout(reset, 3500);
        return () => window.clearTimeout(timer);
    }, [step]);

    const start = async () => {
        setBusy(true); setError(''); setNotice('');
        try {
            const state = await refresh();
            if (state.status !== 'open') throw new Error('Sign-in is closed. Ask the instructor for assistance.');
            const result = await api<{ members: Member[] }>('candidates');
            if (!result.members.length) throw new Error('No current assignments are available. Ask the instructor to record an attendance exception.');
            setMembers(result.members); setStep('shift');
        } catch (err) { setError((err as Error).message); }
        finally { setBusy(false); }
    };
    const submit = async (signature?: string) => {
        if (!member && !pending) return;
        const body = pending ?? { employee_profile_id: member!.id, signature: signature!, client_submission_id: crypto.randomUUID() };
        setPending(body); rememberReceipt(body.client_submission_id); setBusy(true); setError('');
        try {
            await api('attendance', body);
            saved();
        } catch (err) {
            try {
                const receipt = await api<{ recorded: boolean }>('receipt', { client_submission_id: body.client_submission_id });
                if (receipt.recorded) { saved(); return; }
                if (err instanceof ApiError && [403, 409, 422].includes(err.status)) {
                    setPending(null); setUncertain(false); rememberReceipt();
                    setError(err.message);
                    return;
                }
                setUncertain(true);
                setError(`${(err as Error).message} Your signature is retained on this screen. Retry this sign-in to check and save the same request.`);
            } catch {
                setUncertain(true);
                setError('We could not confirm whether attendance was saved. Keep this screen open and retry when connected. Do not sign again on another device.');
            }
        } finally { setBusy(false); }
    };
    const cancel = async () => {
        if (pending) {
            setBusy(true);
            try {
                const result = await api<{ recorded: boolean }>('receipt', { client_submission_id: pending.client_submission_id });
                if (result.recorded) { saved(); return; }
                setError('There is no confirmed receipt yet. Retry this sign-in before leaving the screen; the original request may still be processing.');
            } catch { setError('The saved status is still unknown. Keep this screen open and ask the instructor for assistance.'); }
            finally { setBusy(false); }
        } else reset();
    };
    const shifts = [...new Set(members.map(item => item.shift))].sort();
    const ranks = [...new Set(members.filter(item => item.shift === shift).map(item => item.rank))].sort();
    const terms = [...new Set(members.map(item => item.term_label))];
    const shiftLabel = terms.length === 1 && terms[0] !== 'Current assignment' ? `${terms[0]} shift` : 'Current assignment · shift';
    const filtered = members.filter(item => item.shift === shift && item.rank === rank && `${item.first_name} ${item.last_name} ${item.identifier_hint}`.toLocaleLowerCase().includes(query.toLocaleLowerCase()));

    return <div className="kiosk-shell">
        <header className="brand-bar">
            <img src="/images/training/mbfd-logo.png" alt="Miami Beach Fire Department insignia" width="70" height="70" />
            <div><p className="eyebrow">Miami Beach Fire Department</p><p className="brand-title">Training Division</p></div>
            <span className="roster-label">Attendance roster</span>
        </header>
        <main>
            {error && <div role="alert" className="message error">{error}</div>}
            {notice && <div role="status" className="message">{notice}</div>}
            {!roster && <section className="empty-state">
                <p className="eyebrow">Ready for your next class</p>
                <h1>No roster is open on this screen</h1>
                <p>Your instructor can open a class and share its sign-in link.</p>
                {base.startsWith('/r/') && <button className="secondary" disabled={busy} onClick={() => void connect()}>{busy ? 'Connecting…' : 'Check connection'}</button>}
            </section>}
            {roster && step === 'roster' && <>
                <section className="course-heading"><p className="eyebrow">{dateLabel(roster.held_on)}</p><h1 ref={heading} tabIndex={-1}>{roster.course_name}</h1>
                    {roster.location && <p>{roster.location}</p>}
                    <div className="roster-meta"><span className={`status ${roster.status === 'open' ? 'open' : ''}`}>{roster.status === 'open' ? 'Sign-in open' : 'Sign-in closed'}</span><span>{roster.attendance.length} {roster.attendance.length === 1 ? 'member' : 'members'} signed</span></div>
                </section>
                <section className="attendance-list" aria-label="Members who have signed">
                    {roster.attendance.length === 0 ? <div className="first-sign"><h2>Be the first to sign</h2><p>Select your current shift, rank, and name. Then add your signature.</p></div>
                        : <table><thead><tr><th scope="col">#</th><th scope="col">Member</th><th scope="col" className="wide">Rank / shift</th><th scope="col">Attendance</th></tr></thead><tbody>
                            {roster.attendance.map((row, index) => <tr key={`${row.name}-${row.signed_at}-${index}`}><td>{index + 1}</td><td><strong>{row.name}</strong><span className="narrow">{[row.rank, row.shift].filter(Boolean).join(' · ')}</span></td><td className="wide">{[row.rank, row.shift].filter(Boolean).join(' · ')}</td><td><span className="signed">Signed</span></td></tr>)}
                        </tbody></table>}
                </section>
                <footer className="sign-dock"><p>Here for this class?<span>Record your attendance below.</span></p><button className="primary sign-button" disabled={busy || roster.status !== 'open'} onClick={start}>{busy ? 'Getting current assignments…' : roster.status === 'open' ? 'SIGN ROSTER' : 'SIGN-IN CLOSED'}<span aria-hidden="true"> →</span></button></footer>
            </>}
            {roster && !['roster', 'success'].includes(step) && <section className="wizard">
                <div className="wizard-top"><button className="text-button" disabled={busy} onClick={() => void cancel()}>Cancel sign-in</button><span>{roster.course_name}</span></div>
                <div className="progress" aria-label="Sign-in steps">{['Shift', 'Rank', 'Name', 'Signature'].map((label, index) => <span key={label} className={['shift', 'rank', 'name', 'signature'].indexOf(step) >= index ? 'reached' : ''}>{index + 1}<b>{label}</b></span>)}</div>
                {step === 'shift' && <><p className="eyebrow">{shiftLabel}</p><h1 ref={heading} tabIndex={-1}>Select your shift</h1><div className="choice-grid">{shifts.map(value => <button className="choice" key={value} onClick={() => { setShift(value); setStep('rank'); }}>{/^[ABCD]$/.test(value) ? `${value} Shift` : value}<span aria-hidden="true">→</span></button>)}</div></>}
                {step === 'rank' && <><button className="text-button" onClick={() => setStep('shift')}>← Change shift</button><p className="eyebrow">{shift}</p><h1 ref={heading} tabIndex={-1}>Select your rank</h1><div className="choice-grid">{ranks.map(value => <button className="choice" key={value} onClick={() => { setRank(value); setStep('name'); }}>{value}<span aria-hidden="true">→</span></button>)}</div></>}
                {step === 'name' && <><button className="text-button" onClick={() => setStep('rank')}>← Change rank</button><p className="eyebrow">{rank} · {shift}</p><h1 ref={heading} tabIndex={-1}>Select your name</h1><label className="search-label" htmlFor="member-search">Find your name</label><input id="member-search" type="search" autoComplete="off" value={query} onChange={event => setQuery(event.target.value)} placeholder="Search by name" />
                    <div className="member-list">{filtered.map(value => <button className="choice member" key={value.id} onClick={() => { setMember(value); setStep('signature'); }}><span>{value.last_name}, {value.first_name}<small>ID ending {value.identifier_hint}</small></span><span aria-hidden="true">→</span></button>)}</div>
                    {!filtered.length && <p>No matching members. Check your shift and rank, or ask the instructor for assistance.</p>}</>}
                {step === 'signature' && member && <><button className="text-button" disabled={busy || Boolean(pending)} onClick={() => setStep('name')}>← Choose another name</button><p className="eyebrow">{member.rank} · {member.shift}</p><h1 ref={heading} tabIndex={-1}>{member.name}</h1><p>Please sign below to confirm your attendance.</p><Signature onSubmit={image => void submit(image)} busy={busy} locked={Boolean(pending)} />
                    {uncertain && <button className="primary retry-button" disabled={busy} onClick={() => void submit()}>{busy ? 'Checking saved attendance…' : 'Retry this sign-in'}</button>}</>}
            </section>}
            {step === 'success' && <section className="success-state" role="status"><div className="success-mark" aria-hidden="true">✓</div><p className="eyebrow">Attendance recorded</p><h1 ref={heading} tabIndex={-1}>Roster signed successfully</h1><p>You’re all set. This screen is ready for the next member.</p><button className="secondary" onClick={reset}>Return to roster</button></section>}
        </main>
    </div>;
}

const root = document.getElementById('training-roster-root');
if (root) createRoot(root).render(<App />);
