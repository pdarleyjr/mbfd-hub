import { useCallback, useEffect, useRef, useState } from 'react';
import {
    Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, MessageBar, MessageBarBody, Spinner,
} from '@fluentui/react-components';
import { ArrowLeft, ChevronRight, Download, Eye, FilePlus2, LoaderCircle, RefreshCw, Send } from 'lucide-react';
import { EmsApiError, type EmsApi } from './emsApi';
import {
    eventDetail, eventLabel, formatEastern, itemsLabel, newSubmissionId, requestedText, statusLabel, statusTone,
} from './emsLogic.js';
import type { EmsOutboxEntry, EmsRequestDetail, EmsRequestLine, EmsRequestSummary } from './emsTypes';
import { PendingRequests } from './PendingRequests';
import type { EmsOutbox } from './useEmsOutbox';

const BAG_NAMES: Record<string, string> = { 'airway-bag': 'Airway Bag', 'als-med-bag': 'ALS Med Bag/Box', 'als-transport-vehicle': 'ALS Transport Vehicle' };
const MESSAGE_MAX = 2000;
const CANCEL_REASON_MAX = 255;

function StatusTag({ status }: { status: string }) {
    return <span className={`ems-status is-${statusTone(status)}`}>{statusLabel(status)}</span>;
}

function problemText(error: unknown, fallback: string) {
    return error instanceof EmsApiError ? error.message : fallback;
}

// ---------------------------------------------------------------- list

export function MyRequestsList({ api, outbox, goForm, goDetail, goForms, editEntry }: {
    api: EmsApi; outbox: EmsOutbox; goForm: () => void; goDetail: (id: string) => void; goForms: () => void; editEntry: (entry: EmsOutboxEntry) => void;
}) {
    const [requests, setRequests] = useState<EmsRequestSummary[] | null>(null);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const load = useCallback(async (target: number) => {
        setLoading(true); setError('');
        try {
            const result = await api.list(target);
            setRequests((current) => (target === 1 || !current ? result.requests : [...current, ...result.requests]));
            setPage(target); setLastPage(result.lastPage);
        } catch (caught) {
            setError(problemText(caught, 'Your requests could not be loaded.'));
        } finally { setLoading(false); }
    }, [api]);

    useEffect(() => { void load(1); }, [load]);
    // A queued request that just went through belongs in the list.
    const sentCount = outbox.sent.length;
    useEffect(() => { if (sentCount > 0) void load(1); }, [sentCount, load]);

    return <div className="ems-app">
        <div className="ems-topline">
            <Button appearance="subtle" icon={<ArrowLeft size={17} />} onClick={goForms}>Forms library</Button>
            <Button appearance="subtle" icon={<RefreshCw size={16} />} disabled={loading} onClick={() => void load(1)}>Refresh</Button>
        </div>
        <header className="ems-head">
            <p className="of-eyebrow">Captain 5 EMS supply</p>
            <h1>My medical requests</h1>
            <p>Every Medical Inventory Request you have sent, newest first.</p>
        </header>
        <div className="ems-head-actions"><Button appearance="primary" icon={<FilePlus2 size={17} />} onClick={goForm}>New request</Button></div>
        <PendingRequests outbox={outbox} openRequest={goDetail} editEntry={editEntry} />
        {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
        {requests === null && loading && <div className="ems-loading"><Spinner label="Loading your requests" /></div>}
        {requests !== null && requests.length === 0 && !error && <div className="ems-empty">
            <p><strong>No requests yet.</strong></p>
            <p>When you send a Medical Inventory Request it shows up here with its status.</p>
        </div>}
        {requests !== null && requests.length > 0 && <ul className="ems-request-list">
            {requests.map((request) => <li key={request.id}>
                <button type="button" className="ems-request-row" onClick={() => goDetail(request.id)} aria-label={`${request.number}, ${statusLabel(request.status)}, ${request.target_label}. Open request`}>
                    <span className="ems-request-main">
                        <span className="ems-request-top"><strong className="ems-request-number">{request.number}</strong><StatusTag status={request.status} /></span>
                        <span className="ems-request-unit">{request.target_label}{request.event_assignment ? `, ${request.event_assignment}` : ''}</span>
                        <span className="ems-request-meta">
                            {formatEastern(request.submitted_at)}
                            {' · '}
                            {request.issued_line_count !== undefined ? `${request.issued_line_count} of ${itemsLabel(request.line_count)} issued` : itemsLabel(request.line_count)}
                        </span>
                    </span>
                    <ChevronRight size={18} aria-hidden="true" />
                </button>
            </li>)}
        </ul>}
        {page < lastPage && <div className="ems-more"><Button disabled={loading} onClick={() => void load(page + 1)}>{loading ? 'Loading' : 'Show older requests'}</Button></div>}
    </div>;
}

// ---------------------------------------------------------------- detail

function LineRow({ line }: { line: EmsRequestLine }) {
    const yesNo = line.response_type === 'yes_no';
    return <li className="ems-line">
        <div className="ems-line-text">
            <span className="ems-item-label">{line.label}</span>
            <span className="ems-item-meta">
                {line.bag_key && <span className="ems-min">{BAG_NAMES[line.bag_key] ?? line.bag_key}</span>}
                {line.section_label && <span className="ems-min">{line.section_label}</span>}
                {line.refrigerated && <span className="ems-tag">Refrigerated</span>}
                {line.controlled_substance && <span className="ems-tag">Controlled</span>}
            </span>
            {line.not_issued_reason && <span className="ems-line-reason">{line.not_issued_reason}</span>}
        </div>
        <dl className="ems-line-numbers">
            <div><dt>Requested</dt><dd>{requestedText(line)}</dd></div>
            {!yesNo && <div><dt>Issued</dt><dd>{line.issued_qty}</dd></div>}
            <div><dt>Status</dt><dd>{line.line_status_label}</dd></div>
        </dl>
    </li>;
}

export function RequestDetail({ api, id, goMine, goForm, goForms, goDetail, openPdf, outbox, editEntry }: {
    api: EmsApi; id: string; goMine: () => void; goForm: () => void; goForms: () => void; goDetail: (id: string) => void;
    openPdf: (url: string, name: string) => void; outbox: EmsOutbox; editEntry: (entry: EmsOutboxEntry) => void;
}) {
    const [detail, setDetail] = useState<EmsRequestDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notFound, setNotFound] = useState(false);
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [messageError, setMessageError] = useState('');
    const messageKey = useRef(newSubmissionId());
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    const [cancelling, setCancelling] = useState(false);
    const [cancelError, setCancelError] = useState('');

    const load = useCallback(async (quiet = false) => {
        if (!quiet) setLoading(true);
        setError('');
        try {
            setDetail(await api.show(id)); setNotFound(false);
        } catch (caught) {
            if (caught instanceof EmsApiError && caught.status === 404) setNotFound(true);
            else if (!quiet) setError(problemText(caught, 'This request could not be loaded.'));
        } finally { if (!quiet) setLoading(false); }
    }, [api, id]);

    useEffect(() => { void load(); }, [load]);
    useEffect(() => {
        const visible = () => { if (document.visibilityState === 'visible') void load(true); };
        document.addEventListener('visibilitychange', visible);
        return () => document.removeEventListener('visibilitychange', visible);
    }, [load]);

    const sendMessage = async () => {
        const text = body.trim();
        if (!detail || text === '' || sending) return;
        setSending(true); setMessageError('');
        try {
            const message = await api.message(detail.id, text, messageKey.current);
            setDetail((current) => (current ? { ...current, messages: [...current.messages.filter((item) => item.id !== message.id), message] } : current));
            setBody(''); messageKey.current = newSubmissionId();
        } catch (caught) {
            setMessageError(problemText(caught, 'The message could not be sent. Try again.'));
        } finally { setSending(false); }
    };

    const cancelRequest = async () => {
        if (!detail || cancelling) return;
        setCancelling(true); setCancelError('');
        try {
            setDetail(await api.cancel(detail.id, cancelReason));
            setCancelOpen(false); setCancelReason('');
        } catch (caught) {
            setCancelError(problemText(caught, 'The request could not be cancelled. Try again.'));
        } finally { setCancelling(false); }
    };

    const back = <div className="ems-topline">
        <Button appearance="subtle" icon={<ArrowLeft size={17} />} onClick={goMine}>My medical requests</Button>
        <Button appearance="subtle" icon={<RefreshCw size={16} />} disabled={loading} onClick={() => void load()}>Refresh</Button>
    </div>;

    if (loading && !detail) return <div className="ems-app">{back}<div className="ems-loading"><Spinner label="Loading request" /></div></div>;
    if (notFound || !detail) {
        return <div className="ems-app">{back}
            <PendingRequests outbox={outbox} openRequest={goDetail} editEntry={editEntry} />
            <div className="ems-empty">
                <p><strong>{notFound ? 'That request is not in your list.' : 'This request could not be loaded.'}</strong></p>
                <p>{notFound ? 'It may have been sent from a different account. Your own requests are under My medical requests.' : error}</p>
                <div className="ems-result-actions"><Button appearance="primary" onClick={goMine}>My medical requests</Button><Button onClick={goForm}>New request</Button><Button appearance="subtle" onClick={goForms}>Back to Forms</Button></div>
            </div>
        </div>;
    }

    const copy = detail.files_copy;
    const cancelled = detail.status === 'cancelled';
    return <div className="ems-app">
        {back}
        <PendingRequests outbox={outbox} openRequest={goDetail} editEntry={editEntry} />
        {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
        <header className="ems-head">
            <p className="of-eyebrow">Medical Inventory Request</p>
            <h1 className="ems-detail-title">{detail.number} <StatusTag status={detail.status} /></h1>
            <dl className="ems-review-facts">
                <div><dt>For</dt><dd>{detail.target_label}{detail.event_assignment ? `, ${detail.event_assignment}` : ''}</dd></div>
                <div><dt>Sent</dt><dd>{formatEastern(detail.submitted_at)}</dd></div>
                <div><dt>Items</dt><dd>{itemsLabel(detail.lines.length)}</dd></div>
            </dl>
            {cancelled && <p className="ems-hint">This request was cancelled{detail.cancel_reason ? `: ${detail.cancel_reason}` : '.'}</p>}
            {detail.status === 'closed' && detail.close_reason && <p className="ems-hint">Closed: {detail.close_reason}</p>}
        </header>

        <section className="ems-card" aria-labelledby="ems-copy-title">
            <h2 id="ems-copy-title">PDF copy</h2>
            {copy.status === 'ready' && copy.preview_url
                ? <div className="ems-copy-actions">
                    <Button appearance="primary" icon={<Eye size={16} />} onClick={() => openPdf(copy.preview_url as string, copy.display_name || `${detail.number}.pdf`)}>View PDF copy</Button>
                    {copy.download_url && <Button as="a" href={copy.download_url} icon={<Download size={16} />}>Download</Button>}
                    <p className="ems-hint">Also saved under Recent records in Forms.</p>
                </div>
                : <div className="ems-copy-actions">
                    <p className="ems-hint" role="status">Your PDF copy is being prepared. It will be under Recent records in Forms in a moment.</p>
                    <Button icon={<RefreshCw size={16} />} onClick={() => void load()}>Check again</Button>
                </div>}
        </section>

        <section className="ems-card" aria-labelledby="ems-lines-title">
            <h2 id="ems-lines-title">What you asked for</h2>
            {detail.lines.length === 0 ? <p className="ems-hint">No items were listed. This request is a note only.</p> : <ul className="ems-lines">{detail.lines.map((line) => <LineRow key={line.id} line={line} />)}</ul>}
            {detail.member_note && <div className="ems-note-block"><h3>Your note</h3><p>{detail.member_note}</p></div>}
        </section>

        <section className="ems-card" aria-labelledby="ems-history-title">
            <h2 id="ems-history-title">Status history</h2>
            <ol className="ems-history">
                {detail.history.map((event) => <li key={event.id}>
                    <span className="ems-history-what">{eventLabel(event.action)}</span>
                    {eventDetail(event.payload) && <span className="ems-history-detail">{eventDetail(event.payload)}</span>}
                    <span className="ems-history-who">{[event.actor_context === 'member' ? 'You' : event.actor_name, event.actor_context === 'member' ? '' : event.actor_context_label].filter(Boolean).join(', ')} · {formatEastern(event.created_at)}</span>
                </li>)}
            </ol>
        </section>

        <section className="ems-card" aria-labelledby="ems-messages-title">
            <h2 id="ems-messages-title">Messages</h2>
            {detail.messages.length === 0 && <p className="ems-hint">No messages yet. Write to Captain 5 about this request below.</p>}
            <ul className="ems-messages">
                {detail.messages.map((message) => <li key={message.id} className={message.author_context === 'member' ? 'is-mine' : ''}>
                    <span className="ems-message-who">{message.author_context === 'member' ? 'You' : `${message.author_name ?? 'Staff'}, ${message.author_context_label}`} · {formatEastern(message.created_at)}</span>
                    <p>{message.body}</p>
                </li>)}
            </ul>
            {cancelled
                ? <p className="ems-hint">Messages are closed because this request was cancelled.</p>
                : <form className="ems-compose" onSubmit={(event) => { event.preventDefault(); void sendMessage(); }}>
                    <label htmlFor="ems-message" className="ems-label">Message to Captain 5</label>
                    <textarea id="ems-message" className="ems-textarea" rows={3} maxLength={MESSAGE_MAX} value={body} onChange={(event) => setBody(event.target.value)} aria-describedby={messageError ? 'ems-message-error' : undefined} />
                    {messageError && <p id="ems-message-error" className="ems-field-error" role="alert">{messageError}</p>}
                    <div className="ems-compose-actions"><Button type="submit" appearance="primary" icon={sending ? <LoaderCircle className="spin" size={16} /> : <Send size={16} />} disabled={sending || body.trim() === ''}>{sending ? 'Sending' : 'Send message'}</Button></div>
                </form>}
        </section>

        {detail.can_cancel && <section className="ems-card" aria-labelledby="ems-cancel-title">
            <h2 id="ems-cancel-title">Cancel this request</h2>
            <p className="ems-hint">You can cancel until Captain 5 issues something from it.</p>
            <Button onClick={() => { setCancelError(''); setCancelOpen(true); }}>Cancel request</Button>
        </section>}

        <Dialog open={cancelOpen} modalType="modal" onOpenChange={(_, data) => { if (!data.open && !cancelling) setCancelOpen(false); }}>
            <DialogSurface className="ems-sheet">
                <DialogBody>
                    <DialogTitle>Cancel {detail.number}?</DialogTitle>
                    <DialogContent>
                        <p>Captain 5 will see that you cancelled it. You can send a new request any time.</p>
                        <label htmlFor="ems-cancel-reason" className="ems-label">Reason <span className="ems-optional">optional</span></label>
                        <textarea id="ems-cancel-reason" className="ems-textarea" rows={2} maxLength={CANCEL_REASON_MAX} value={cancelReason} onChange={(event) => setCancelReason(event.target.value)} />
                        {cancelError && <MessageBar intent="error" role="alert"><MessageBarBody>{cancelError}</MessageBarBody></MessageBar>}
                    </DialogContent>
                    <DialogActions className="ems-sheet-actions">
                        <Button onClick={() => setCancelOpen(false)} disabled={cancelling}>Keep request</Button>
                        <Button appearance="primary" disabled={cancelling} icon={cancelling ? <LoaderCircle className="spin" size={16} /> : undefined} onClick={() => void cancelRequest()}>{cancelling ? 'Cancelling' : 'Cancel request'}</Button>
                    </DialogActions>
                </DialogBody>
            </DialogSurface>
        </Dialog>
    </div>;
}
