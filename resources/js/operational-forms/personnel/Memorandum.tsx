import { useCallback, useEffect, useRef, useState } from 'react';
import { Button, MessageBar, MessageBarBody, Spinner } from '@fluentui/react-components';
import { ArrowLeft, CheckCircle2, Download, Eye, Inbox, LoaderCircle, Mail, Send, Trash2, UserRound } from 'lucide-react';
import { PersonnelApiError, type PersonnelApi } from './personnelApi';
import { MEMO_LIMITS, characterCount, memoErrors, memoPayload, newClientKey } from './personnelLogic.js';
import { memoDrafts } from './personnelStore';
import { FieldError, Notice } from './personnelUi';
import type { MemoDestination, MemoFields, MemoFormDefinition } from './personnelTypes';

type Busy = null | 'preview' | 'download' | 'email' | 'send';

const EMPTY: MemoFields = { to: '', from: '', date: '', subject: '', body: '' };

function saveBlob(blob: Blob, fileName: string) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

/**
 * The MBFD Interoffice Memorandum. To Support Services it is delivered into the Support Services inbox; to anyone
 * else the member downloads the PDF (or emails a copy to their own City address) and sends it themselves. A memo to
 * someone else is never stored by the Hub; the copy on this device is cleared once it is downloaded or emailed.
 */
export function Memorandum({ api, owner, goForms, openPdf }: { api: PersonnelApi; owner: string; goForms: () => void; openPdf: (url: string, name: string) => void }) {
    const [definition, setDefinition] = useState<MemoFormDefinition | null>(null);
    const [loadError, setLoadError] = useState('');
    const [destination, setDestination] = useState<MemoDestination | null>(null);
    const [memo, setMemo] = useState<MemoFields>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<Busy>(null);
    const [message, setMessage] = useState<{ intent: 'error' | 'success' | 'info'; text: string } | null>(null);
    const [sent, setSent] = useState<{ thread_url: string; message: string } | null>(null);
    const [restored, setRestored] = useState(false);
    const clientKey = useRef(newClientKey());
    const loaded = useRef(false);
    const previewUrl = useRef<string | null>(null);

    const load = useCallback(async () => {
        setLoadError('');
        try {
            const form = await api.memoForm();
            const local = await memoDrafts.get(owner);
            setDefinition(form);
            if (local && (local.subject || local.body || local.to)) {
                setMemo({ to: local.to, from: local.from || form.from, date: local.date || form.date, subject: local.subject, body: local.body });
                setDestination(local.destination);
                clientKey.current = local.clientKey || newClientKey();
                setRestored(true);
            } else {
                setMemo({ ...EMPTY, from: form.from, date: form.date });
            }
            loaded.current = true;
        } catch (caught) {
            setLoadError(caught instanceof PersonnelApiError ? caught.message : 'The memorandum could not be opened.');
        }
    }, [api, owner]);
    useEffect(() => { void load(); }, [load]);
    useEffect(() => () => { if (previewUrl.current) URL.revokeObjectURL(previewUrl.current); }, []);

    // The copy on this device, so a refresh or a dropped connection never loses the text.
    useEffect(() => {
        if (!loaded.current || sent) return undefined;
        const timer = window.setTimeout(() => {
            if (!memo.subject && !memo.body && !memo.to) { void memoDrafts.remove(owner); return; }
            void memoDrafts.put({ ...memo, owner, destination, clientKey: clientKey.current, savedAt: new Date().toISOString() });
        }, 400);
        return () => window.clearTimeout(timer);
    }, [memo, destination, owner, sent]);

    const update = (key: keyof MemoFields, value: string) => {
        setMemo((current) => ({ ...current, [key]: value }));
        setErrors((current) => { if (!(key in current)) return current; const next = { ...current }; delete next[key]; return next; });
    };

    const ready = (): MemoFields | null => {
        if (!definition || !destination) { setMessage({ intent: 'error', text: 'Choose who the memorandum is for first.' }); return null; }
        const found = memoErrors(destination === 'support' ? { ...memo, from: definition.from } : memo, destination);
        setErrors(found);
        if (Object.keys(found).length > 0) {
            setMessage({ intent: 'error', text: 'Complete the highlighted fields.' });
            window.setTimeout(() => document.getElementById(`pf-memo-${Object.keys(found)[0]}`)?.focus(), 0);
            return null;
        }
        setMessage(null);
        // The server sets From for Support Services from the signed-in member; the preview shows the same.
        return memoPayload(destination === 'support' ? { ...memo, from: definition.from } : memo, destination, definition.support_services_label);
    };

    const fail = (caught: unknown, fallback: string) => {
        if (caught instanceof PersonnelApiError) {
            setErrors((current) => ({ ...current, ...caught.fields }));
            setMessage({ intent: 'error', text: caught.network ? 'You are offline. Nothing was sent; your memorandum is kept on this device.' : caught.message });
        } else {
            console.error(fallback, caught);
            setMessage({ intent: 'error', text: fallback });
        }
    };

    const preview = async () => {
        const payload = ready();
        if (!payload) return;
        setBusy('preview');
        try {
            const { blob } = await api.memoPdf(payload, false);
            if (previewUrl.current) URL.revokeObjectURL(previewUrl.current);
            previewUrl.current = URL.createObjectURL(blob);
            openPdf(previewUrl.current, `Preview: ${payload.subject}`);
        } catch (caught) { fail(caught, 'The preview could not be produced.'); } finally { setBusy(null); }
    };

    const download = async () => {
        const payload = ready();
        if (!payload) return;
        setBusy('download');
        try {
            const { blob, fileName } = await api.memoPdf(payload, true);
            saveBlob(blob, fileName);
            await memoDrafts.remove(owner);
            setMessage({ intent: 'success', text: `Downloaded "${fileName}". Send it to ${payload.to} as you normally would. The Hub kept no copy.` });
        } catch (caught) { fail(caught, 'The PDF could not be downloaded.'); } finally { setBusy(null); }
    };

    const email = async () => {
        const payload = ready();
        if (!payload) return;
        setBusy('email');
        try {
            const result = await api.memoEmailCopy(payload);
            if (result.sent === true) {
                await memoDrafts.remove(owner);
                setMessage({ intent: 'success', text: result.message });
            } else {
                setMessage({ intent: 'error', text: 'The email was not sent. Download the PDF instead; nothing was lost.' });
            }
        } catch (caught) { fail(caught, 'The email was not sent. Download the PDF instead; nothing was lost.'); } finally { setBusy(null); }
    };

    const send = async () => {
        const payload = ready();
        if (!payload) return;
        setBusy('send');
        try {
            const result = await api.memoToSupportServices(payload, clientKey.current);
            await memoDrafts.remove(owner);
            setSent(result);
        } catch (caught) { fail(caught, 'The memorandum could not be sent.'); } finally { setBusy(null); }
    };

    const discard = async () => {
        if ((memo.subject || memo.body) && !window.confirm('Discard this memorandum? The text on this device will be deleted.')) return;
        await memoDrafts.remove(owner);
        clientKey.current = newClientKey();
        setMemo({ ...EMPTY, from: definition?.from ?? '', date: definition?.date ?? '' });
        setDestination(null); setErrors({}); setMessage(null); setRestored(false);
    };

    if (loadError) return <div className="pf-app">
        <MessageBar intent="error" role="alert"><MessageBarBody>{loadError}</MessageBarBody></MessageBar>
        <div className="pf-actions"><Button appearance="primary" onClick={() => void load()}>Try again</Button><Button appearance="subtle" onClick={goForms}>Back to Forms</Button></div>
    </div>;
    if (!definition) return <div className="pf-app"><div className="pf-loading"><Spinner label="Opening the memorandum" /></div></div>;

    if (sent) return <div className="pf-app">
        <section className="pf-result" aria-labelledby="pf-memo-sent">
            <span className="pf-result-icon is-success"><CheckCircle2 size={34} /></span>
            <h1 id="pf-memo-sent" tabIndex={-1} ref={(node) => node?.focus()}>Sent to Support Services</h1>
            <p>{sent.message}</p>
            <div className="pf-actions is-center">
                <Button as="a" appearance="primary" href={sent.thread_url}>Open the conversation</Button>
                <Button onClick={() => { setSent(null); clientKey.current = newClientKey(); setMemo({ ...EMPTY, from: definition.from, date: definition.date }); setDestination(null); setMessage(null); }}>Write another memorandum</Button>
                <Button appearance="subtle" onClick={goForms}>Back to Forms</Button>
            </div>
        </section>
    </div>;

    const support = destination === 'support';
    const bodyCount = characterCount(memo.body, MEMO_LIMITS.body);
    const disabled = busy !== null;
    const field = (key: keyof MemoFields, label: string, type = 'text', hint?: string) => {
        const id = `pf-memo-${key}`;
        return <div className="pf-field">
            <label className="pf-label" htmlFor={id}>{label}</label>
            {hint && <p className="pf-hint" id={`${id}-hint`}>{hint}</p>}
            <input
                id={id} className="pf-input" type={type} value={memo[key]} maxLength={type === 'date' ? undefined : MEMO_LIMITS[key as keyof typeof MEMO_LIMITS]} autoComplete="off"
                aria-invalid={errors[key] ? true : undefined} aria-describedby={[hint ? `${id}-hint` : '', errors[key] ? `${id}-error` : ''].filter(Boolean).join(' ') || undefined}
                onChange={(event) => update(key, event.target.value)}
            />
            <FieldError id={`${id}-error`} message={errors[key]} />
        </div>;
    };

    return <div className="pf-app">
        <div className="pf-topline"><Button appearance="subtle" icon={<ArrowLeft size={17} />} onClick={goForms}>Forms</Button></div>
        <header className="pf-head">
            <p className="of-eyebrow">Miami Beach Fire Department</p>
            <h1>Interoffice Memorandum</h1>
        </header>
        {restored && <MessageBar intent="info"><MessageBarBody>The memorandum you were writing on this device was put back. <Button appearance="transparent" onClick={() => setRestored(false)}>Dismiss</Button></MessageBarBody></MessageBar>}

        <fieldset className="pf-card pf-destinations">
            <legend className="pf-label">Who is it for?</legend>
            <label className={`pf-destination${support ? ' is-on' : ''}`}>
                <input type="radio" name="pf-memo-destination" checked={support} onChange={() => setDestination('support')} />
                <Inbox size={22} aria-hidden="true" />
                <span><strong>Support Services</strong><small>Delivered to the Support Services inbox. You follow the conversation in My Messages, and the PDF is kept in My Documents.</small></span>
            </label>
            <label className={`pf-destination${destination === 'other' ? ' is-on' : ''}`}>
                <input type="radio" name="pf-memo-destination" checked={destination === 'other'} onChange={() => setDestination('other')} />
                <UserRound size={22} aria-hidden="true" />
                <span><strong>Someone else (download / email)</strong><small>Download the PDF{definition.email_copy_available ? ' or email it to yourself' : ''} and send it to the recipient. The Hub keeps no copy.</small></span>
            </label>
        </fieldset>

        {destination && <section className="pf-card">
            <div className="pf-fields">
                {support
                    ? <div className="pf-field"><span className="pf-label">To</span><p className="pf-fixed">{definition.support_services_label}</p></div>
                    : field('to', 'To', 'text', 'Name and title, for example Chief of Department.')}
                {field('date', 'Date', 'date')}
                {support
                    ? <div className="pf-field"><span className="pf-label">From</span><p className="pf-fixed">{definition.from}</p><p className="pf-hint">Memoranda to Support Services are always from your own Hub record.</p></div>
                    : field('from', 'From')}
                {field('subject', 'Subject')}
                <div className="pf-field is-wide">
                    <label className="pf-label" htmlFor="pf-memo-body">Memorandum</label>
                    <p className="pf-hint" id="pf-memo-body-hint">Plain text. Leave a blank line between paragraphs.</p>
                    <textarea
                        id="pf-memo-body" className="pf-input pf-textarea pf-memo-body" rows={14} maxLength={MEMO_LIMITS.body} value={memo.body}
                        aria-invalid={errors.body ? true : undefined} aria-describedby={`pf-memo-body-hint pf-memo-body-count${errors.body ? ' pf-memo-body-error' : ''}`}
                        onChange={(event) => update('body', event.target.value)}
                    />
                    <p className={`pf-count${bodyCount.near ? ' is-near' : ''}`} id="pf-memo-body-count">{bodyCount.text}</p>
                    <FieldError id="pf-memo-body-error" message={errors.body} />
                </div>
            </div>
        </section>}

        {message && <MessageBar intent={message.intent} role={message.intent === 'error' ? 'alert' : 'status'}><MessageBarBody>{message.text}</MessageBarBody></MessageBar>}

        {destination && <section className="pf-card pf-memo-actions" aria-label="Finish the memorandum">
            <div className="pf-actions">
                <Button icon={busy === 'preview' ? <LoaderCircle className="spin" size={17} /> : <Eye size={17} />} disabled={disabled} onClick={() => void preview()}>Preview PDF</Button>
                {support
                    ? <Button appearance="primary" size="large" icon={busy === 'send' ? <LoaderCircle className="spin" size={18} /> : <Send size={18} />} disabled={disabled} onClick={() => void send()}>{busy === 'send' ? 'Sending…' : 'Send to Support Services'}</Button>
                    : <>
                        <Button appearance="primary" size="large" icon={busy === 'download' ? <LoaderCircle className="spin" size={18} /> : <Download size={18} />} disabled={disabled} onClick={() => void download()}>Download PDF</Button>
                        {definition.email_copy_available && <Button size="large" icon={busy === 'email' ? <LoaderCircle className="spin" size={18} /> : <Mail size={18} />} disabled={disabled} onClick={() => void email()}>Email a copy to my City email</Button>}
                    </>}
            </div>
            {!support && <p className="pf-hint">
                {definition.email_copy_available && definition.email_address_hint ? `The copy goes only to your City email (${definition.email_address_hint}). ` : ''}
                Then send the PDF to the recipient yourself. Nothing is delivered for you, and the Hub keeps no copy.
            </p>}
            {support && <Notice tone="info">Support Services receives the memorandum as a conversation in their inbox. You can follow replies in My Messages.</Notice>}
            <div><Button appearance="subtle" icon={<Trash2 size={16} />} disabled={disabled} onClick={() => void discard()}>Discard memorandum</Button></div>
        </section>}
    </div>;
}
