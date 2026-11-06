import { useEffect, useState } from 'react';
import { Button, MessageBar, MessageBarBody, Spinner } from '@fluentui/react-components';
import './ems.css';
import { type EmsApi } from './emsApi';
import { draftFromPayload, familyOfPayload, familyOfView, familyTitle, revisesFromSearch } from './emsLogic.js';
import type { EmsDraft, EmsOutboxEntry, EmsView } from './emsTypes';
import { FixAndResend } from './FixAndResend';
import { MedicalRequestForm } from './MedicalRequestForm';
import { MyRequestsList, RequestDetail } from './MyRequests';
import { useEmsDefinitions, useWantFamily } from './useEmsDefinitions';
import type { EmsOutbox } from './useEmsOutbox';

export interface EmsNewFormChoice {
    family: string;
    title: string;
    /** "ALS Transport": the title without "Inventory Form", for a button. */
    short: string;
}

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
    /** The forms the Forms library offers (from the page's library cards), so "New request" can name them. */
    newForms?: EmsNewFormChoice[];
}

/** The Medical Inventory views inside the Forms app: one of the four forms, the member's own list, and one request. */
export function EmsApp({ api, owner, view, outbox, edit, onEditConsumed, navigate, goForms, editEntry, openPdf, onSubmitted, newForms = [] }: EmsAppProps) {
    const definitions = useEmsDefinitions(api);
    const [startFrom, setStartFrom] = useState<EmsDraft | null>(null);

    const revisesId = view.name === 'form' ? (view.revises ?? revisesFromSearch(window.location.search)) : null;
    const fixing = revisesId !== null && edit === null && startFrom === null;
    // Which form's lists this view needs: a queued request being edited opens in its own form; "Fix and resend" works out its own.
    const family = edit ? familyOfPayload(edit.payload) : familyOfView(view);
    const wantsForm = !fixing && (view.name === 'form' || edit !== null);
    useWantFamily(wantsForm ? family : null, definitions);
    const definition = definitions.definitions[family] ?? null;
    const loadError = definitions.errors[family] ?? '';

    // "Edit and resend": turn the unsent payload back into a draft, then take it off the queue.
    useEffect(() => {
        if (!edit || !definition) return;
        setStartFrom(draftFromPayload(edit.payload, definition, edit.revision));
        void outbox.discard(edit.clientSubmissionId);
        onEditConsumed();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [edit, definition]);

    const goForm = (target?: string) => navigate({ name: 'form', family: target ?? family });
    const goMine = () => navigate({ name: 'mine' });
    const goDetail = (id: string) => navigate({ name: 'detail', id });
    // "Fix and resend": the page address carries the returned request, so a refresh or a reopened tab comes back to the correction.
    // The correction opens in the request's own form; the address only has to name something that opens the form app.
    const fix = (id: string, target?: string) => navigate({ name: 'form', family: target, revises: id });

    let content;
    if (view.name === 'mine') {
        content = <MyRequestsList api={api} outbox={outbox} newForms={newForms} goForm={goForm} goDetail={goDetail} goForms={goForms} editEntry={editEntry} onFix={fix} />;
    } else if (view.name === 'detail') {
        content = <RequestDetail key={view.id} api={api} id={view.id} goMine={goMine} goForm={goForm} goForms={goForms} goDetail={goDetail} openPdf={openPdf} outbox={outbox} editEntry={editEntry} onFix={fix} />;
    } else if (fixing && revisesId) {
        content = <FixAndResend
            key={revisesId} api={api} owner={owner} loadDefinition={definitions.load} requestId={revisesId} outbox={outbox}
            goForm={goForm} goForms={goForms} goMine={goMine} goDetail={goDetail} editEntry={editEntry} onSubmitted={onSubmitted}
        />;
    } else if (loadError) {
        content = <div className="ems-app">
            <MessageBar intent="error"><MessageBarBody>{loadError}</MessageBarBody></MessageBar>
            <div className="ems-result-actions"><Button appearance="primary" onClick={() => void definitions.load(family).catch(() => undefined)}>Try again</Button><Button appearance="subtle" onClick={goForms}>Back to Forms</Button></div>
        </div>;
    } else if (!definition || (edit !== null && startFrom === null)) {
        content = <div className="ems-app"><div className="ems-loading"><Spinner label="Opening the form" /></div></div>;
    } else if (definition.forms.length === 0) {
        content = <div className="ems-app"><div className="ems-empty">
            <p><strong>{familyTitle(definition)} is not available yet.</strong></p>
            <p>Contact the EMS office if this keeps happening.</p>
            <Button appearance="subtle" onClick={goForms}>Back to Forms</Button>
        </div></div>;
    } else {
        // One form instance per family, so a draft, a search or a half-typed quantity can never carry over into another form.
        content = <MedicalRequestForm
            key={family} api={api} owner={owner} definition={definition} outbox={outbox} startFrom={startFrom} onStartConsumed={() => setStartFrom(null)}
            goForm={goForm} goForms={goForms} goMine={goMine} goDetail={goDetail} editEntry={editEntry} onSubmitted={onSubmitted}
        />;
    }

    return <div className="ems">{content}</div>;
}
