import { useEffect, useRef, useState } from 'react';
import { Button, MessageBar, MessageBarBody, Spinner } from '@fluentui/react-components';
import { ArrowLeft, ListChecks } from 'lucide-react';
import { EmsApiError, type EmsApi } from './emsApi';
import { CANNOT_REVISE_SHORT, needsCorrection, reviseFromStored, reviseStart, reviseStoreKey } from './emsLogic.js';
import { emsDrafts } from './emsStore';
import type { EmsDraft, EmsFormDefinition, EmsOutboxEntry, EmsRequestDetail } from './emsTypes';
import { MedicalRequestForm } from './MedicalRequestForm';
import { PendingRequests } from './PendingRequests';
import type { EmsOutbox } from './useEmsOutbox';

type Phase =
    | { kind: 'loading' }
    | { kind: 'ready'; draft: EmsDraft; resumed: boolean }
    | { kind: 'waiting'; number: string }
    | { kind: 'blocked'; request: EmsRequestDetail | null }
    | { kind: 'error'; message: string };

export interface FixAndResendProps {
    api: EmsApi;
    owner: string;
    definition: EmsFormDefinition;
    /** The returned request being corrected. */
    requestId: string;
    outbox: EmsOutbox;
    goForm: () => void;
    goForms: () => void;
    goMine: () => void;
    goDetail: (id: string) => void;
    editEntry: (entry: EmsOutboxEntry) => void;
    onSubmitted: () => void;
}

/**
 * "Fix and resend": finds where the correction starts, then hands it to the ordinary request form.
 *  - a correction already waiting in the queue on this device: say so instead of starting a second one;
 *  - a correction in progress on this device (its own stored row): resume that, not the sent copy;
 *  - otherwise the request as it was sent, loaded from the server (it must still be waiting for a correction).
 * Offline, a stored correction can still be resumed without loading the request.
 */
export function FixAndResend({ api, owner, definition, requestId, outbox, goForm, goForms, goMine, goDetail, editEntry, onSubmitted }: FixAndResendProps) {
    const [phase, setPhase] = useState<Phase>({ kind: 'loading' });
    const [attempt, setAttempt] = useState(0);
    // The queue is read once when the view opens; later changes (a send that just went through) must not restart it.
    const entriesRef = useRef(outbox.entries);
    entriesRef.current = outbox.entries;

    useEffect(() => {
        if (!outbox.loaded) return undefined;
        let active = true;
        const resolve = async () => {
            setPhase({ kind: 'loading' });
            const queued = entriesRef.current.find((entry) => entry.payload.revises === requestId);
            if (queued) { setPhase({ kind: 'waiting', number: queued.revision?.number ?? '' }); return; }
            const stored = await emsDrafts.get(reviseStoreKey(owner, requestId));
            try {
                const request = await api.show(requestId);
                if (!active) return;
                if (!needsCorrection(request)) { setPhase({ kind: 'blocked', request }); return; }
                const start = reviseStart(request, stored, definition);
                setPhase({ kind: 'ready', draft: start.draft, resumed: start.resumed });
            } catch (caught) {
                if (!active) return;
                if (caught instanceof EmsApiError && caught.status === 404) { setPhase({ kind: 'blocked', request: null }); return; }
                // No connection: a correction already started on this device can still be picked up.
                const offline = caught instanceof EmsApiError && caught.network ? reviseFromStored(stored, definition, requestId) : null;
                if (offline) { setPhase({ kind: 'ready', draft: offline, resumed: true }); return; }
                setPhase({ kind: 'error', message: caught instanceof EmsApiError ? caught.message : 'This form could not be opened for correction.' });
            }
        };
        void resolve();
        return () => { active = false; };
    }, [outbox.loaded, requestId, api, owner, definition, attempt]);

    const topline = <div className="ems-topline">
        <Button appearance="subtle" icon={<ArrowLeft size={17} />} onClick={goForms}>Forms library</Button>
        <Button appearance="subtle" icon={<ListChecks size={17} />} onClick={goMine}>My medical requests</Button>
    </div>;

    if (phase.kind === 'ready') {
        return <MedicalRequestForm
            key={requestId} api={api} owner={owner} definition={definition} outbox={outbox} startFrom={phase.draft} resumed={phase.resumed} onStartConsumed={() => undefined}
            goForm={goForm} goForms={goForms} goMine={goMine} goDetail={goDetail} editEntry={editEntry} onSubmitted={onSubmitted}
        />;
    }

    if (phase.kind === 'loading') {
        return <div className="ems-app">{topline}<div className="ems-loading"><Spinner label="Opening your returned form" /></div></div>;
    }

    if (phase.kind === 'waiting') {
        return <div className="ems-app">
            {topline}
            <PendingRequests outbox={outbox} openRequest={goDetail} editEntry={editEntry} />
            <div className="ems-empty">
                <p><strong>Your corrected form is already waiting to send.</strong></p>
                <p>It is saved on this device and will replace {phase.number || 'the returned form'} as soon as it goes through.</p>
                <div className="ems-result-actions"><Button appearance="primary" onClick={goMine}>My medical requests</Button></div>
            </div>
        </div>;
    }

    if (phase.kind === 'blocked') {
        return <div className="ems-app">
            {topline}
            <div className="ems-empty" role="alert">
                <p><strong>This form can no longer be corrected.</strong></p>
                <p>{CANNOT_REVISE_SHORT}</p>
                <div className="ems-result-actions">
                    <Button appearance="primary" onClick={goMine}>My medical requests</Button>
                    {phase.request && <Button onClick={() => goDetail(phase.request?.id ?? requestId)}>Open {phase.request.number}</Button>}
                </div>
            </div>
        </div>;
    }

    return <div className="ems-app">
        {topline}
        <MessageBar intent="error"><MessageBarBody>{phase.message}</MessageBarBody></MessageBar>
        <div className="ems-result-actions"><Button appearance="primary" onClick={() => setAttempt((count) => count + 1)}>Try again</Button><Button appearance="subtle" onClick={goMine}>My medical requests</Button></div>
    </div>;
}
