import { useCallback, useEffect, useState } from 'react';
import { Button, MessageBar, MessageBarBody, Spinner } from '@fluentui/react-components';
import './ems.css';
import { EmsApiError, type EmsApi } from './emsApi';
import { draftFromPayload, revisesFromSearch } from './emsLogic.js';
import type { EmsDraft, EmsFormDefinition, EmsOutboxEntry, EmsView } from './emsTypes';
import { FixAndResend } from './FixAndResend';
import { MedicalRequestForm } from './MedicalRequestForm';
import { MyRequestsList, RequestDetail } from './MyRequests';
import type { EmsOutbox } from './useEmsOutbox';

export interface EmsAppProps {
    api: EmsApi;
    owner: string;
    view: EmsView;
    outbox: EmsOutbox;
    /** A request that was not accepted, sent back to the form for fixing. */
    edit: EmsOutboxEntry | null;
    onEditConsumed: () => void;
    navigate: (view: EmsView) => void;
    goForms: () => void;
    editEntry: (entry: EmsOutboxEntry) => void;
    openPdf: (url: string, name: string) => void;
    onSubmitted: () => void;
}

/** The Medical Inventory Request views inside the Forms app: the form, the member's own list, and one request. */
export function EmsApp({ api, owner, view, outbox, edit, onEditConsumed, navigate, goForms, editEntry, openPdf, onSubmitted }: EmsAppProps) {
    const [definition, setDefinition] = useState<EmsFormDefinition | null>(null);
    const [loadError, setLoadError] = useState('');
    const [loading, setLoading] = useState(false);
    const [startFrom, setStartFrom] = useState<EmsDraft | null>(null);

    const needsDefinition = view.name === 'form' || edit !== null;

    const loadDefinition = useCallback(async () => {
        setLoading(true); setLoadError('');
        try {
            setDefinition(await api.form());
        } catch (caught) {
            setLoadError(caught instanceof EmsApiError ? caught.message : 'The request form could not be loaded.');
        } finally { setLoading(false); }
    }, [api]);

    useEffect(() => { if (needsDefinition && !definition && !loading && !loadError) void loadDefinition(); }, [needsDefinition, definition, loading, loadError, loadDefinition]);

    // "Edit and resend": turn the unsent payload back into a draft, then take it off the queue.
    useEffect(() => {
        if (!edit || !definition) return;
        setStartFrom(draftFromPayload(edit.payload, definition, edit.revision));
        void outbox.discard(edit.clientSubmissionId);
        onEditConsumed();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [edit, definition]);

    const goForm = () => navigate({ name: 'form' });
    const goMine = () => navigate({ name: 'mine' });
    const goDetail = (id: string) => navigate({ name: 'detail', id });
    // "Fix and resend": the page address carries the returned request, so a refresh or a reopened tab comes back to the correction.
    const fix = (id: string) => navigate({ name: 'form', revises: id });
    const revisesId = view.name === 'form' ? (view.revises ?? revisesFromSearch(window.location.search)) : null;

    let content;
    if (view.name === 'mine') {
        content = <MyRequestsList api={api} outbox={outbox} goForm={goForm} goDetail={goDetail} goForms={goForms} editEntry={editEntry} onFix={fix} />;
    } else if (view.name === 'detail') {
        content = <RequestDetail key={view.id} api={api} id={view.id} goMine={goMine} goForm={goForm} goForms={goForms} goDetail={goDetail} openPdf={openPdf} outbox={outbox} editEntry={editEntry} onFix={fix} />;
    } else if (loadError) {
        content = <div className="ems-app">
            <MessageBar intent="error"><MessageBarBody>{loadError}</MessageBarBody></MessageBar>
            <div className="ems-result-actions"><Button appearance="primary" onClick={() => void loadDefinition()}>Try again</Button><Button appearance="subtle" onClick={goForms}>Back to Forms</Button></div>
        </div>;
    } else if (!definition || (edit !== null && startFrom === null)) {
        content = <div className="ems-app"><div className="ems-loading"><Spinner label="Opening the Medical Inventory Request" /></div></div>;
    } else if (definition.forms.length === 0) {
        content = <div className="ems-app"><div className="ems-empty">
            <p><strong>The medical inventory forms are not available yet.</strong></p>
            <p>Contact the EMS office if this keeps happening.</p>
            <Button appearance="subtle" onClick={goForms}>Back to Forms</Button>
        </div></div>;
    } else if (revisesId && edit === null && startFrom === null) {
        content = <FixAndResend
            key={revisesId} api={api} owner={owner} definition={definition} requestId={revisesId} outbox={outbox}
            goForm={goForm} goForms={goForms} goMine={goMine} goDetail={goDetail} editEntry={editEntry} onSubmitted={onSubmitted}
        />;
    } else {
        content = <MedicalRequestForm
            api={api} owner={owner} definition={definition} outbox={outbox} startFrom={startFrom} onStartConsumed={() => setStartFrom(null)}
            goForm={goForm} goForms={goForms} goMine={goMine} goDetail={goDetail} editEntry={editEntry} onSubmitted={onSubmitted}
        />;
    }

    return <div className="ems">{content}</div>;
}
