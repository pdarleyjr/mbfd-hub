import { useEffect, useRef } from 'react';
import { Button } from '@fluentui/react-components';
import { Check, WifiOff } from 'lucide-react';
import { itemsLabel, requestFamilyShort, statusLabel, statusTone } from './emsLogic.js';
import type { EmsRequestDetail } from './emsTypes';
import type { EmsOutbox } from './useEmsOutbox';

/** The screen after a request was accepted: its number, status and unit, where the PDF copy is, and the next step. */
export function SentConfirmation({ request, replaces, onView, onNew, onBack, onMine }: {
    request: EmsRequestDetail; replaces: string | null; onView: () => void; onNew: () => void; onBack: () => void; onMine: () => void;
}) {
    const items = request.lines.filter((line) => (line.response_type === 'yes_no' ? line.requested_flag === true : (line.requested_qty ?? 0) > 0)).length;
    const headingRef = useRef<HTMLHeadingElement | null>(null);
    useEffect(() => { headingRef.current?.focus(); }, []);
    const copyReady = request.files_copy?.status === 'ready';
    return <section className="ems-result" aria-labelledby="ems-sent-title">
        <div className="ems-result-icon is-success"><Check size={28} aria-hidden="true" /></div>
        <h1 id="ems-sent-title" tabIndex={-1} ref={headingRef}>{replaces ? 'Corrected form sent' : 'Request sent'}</h1>
        <p className="ems-result-number" role="status">{request.number}</p>
        <p><span className={`ems-status is-${statusTone(request.status)}`}>{statusLabel(request.status, request.status_label)}</span></p>
        <p className="ems-unit-banner">Sent for <strong>{request.target_label}</strong></p>
        {replaces && <p className="ems-hint" role="status">This replaces {replaces}. Captain 5 will review this one instead.</p>}
        <dl className="ems-review-facts">
            <div><dt>Form</dt><dd>{requestFamilyShort(request)}</dd></div>
            <div><dt>For</dt><dd>{request.target_label}{request.event_assignment ? `, ${request.event_assignment}` : ''}</dd></div>
            <div><dt>Items</dt><dd>{itemsLabel(items)}</dd></div>
        </dl>
        <p className="ems-hint">
            {copyReady ? 'A PDF copy is saved under Recent records in Forms.' : 'A PDF copy is being prepared and will be under Recent records in Forms in a moment.'}
            {' '}Captain 5 will review it, and you can follow its status or send a message from My medical requests.
        </p>
        <div className="ems-result-actions">
            <Button appearance="primary" onClick={onView}>View request</Button>
            <Button onClick={onMine}>My medical requests</Button>
            <Button onClick={onBack}>Files copy in Forms</Button>
            <Button appearance="subtle" onClick={onNew}>New request</Button>
        </div>
    </section>;
}

/** The screen after a send that could not reach the server: the request waits on this device and goes out by itself. */
export function QueuedNotice({ reason, message, outbox, replaces, onNew, onBack }: {
    reason: 'offline' | 'server' | 'auth'; message: string; outbox: EmsOutbox; replaces: string | null; onNew: () => void; onBack: () => void;
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
        {replaces && <p className="ems-hint">When it goes through, it replaces {replaces}.</p>}
        <div className="ems-result-actions">
            {reason === 'auth'
                ? <Button appearance="primary" onClick={() => window.location.reload()}>Sign in again</Button>
                : <Button appearance="primary" disabled={outbox.flushing} onClick={() => void outbox.flush()}>{outbox.flushing ? 'Sending' : 'Try sending now'}</Button>}
            <Button onClick={onNew}>{replaces ? 'New request' : 'Start another request'}</Button>
            <Button appearance="subtle" onClick={onBack}>Back to Forms</Button>
        </div>
    </section>;
}
