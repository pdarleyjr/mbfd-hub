import { Button, MessageBar, MessageBarActions, MessageBarBody, MessageBarTitle } from '@fluentui/react-components';
import { LoaderCircle } from 'lucide-react';
import { itemsLabel } from './emsLogic.js';
import type { EmsOutboxEntry } from './emsTypes';
import type { EmsOutbox } from './useEmsOutbox';

/** Requests waiting on this device, and the ones that just went through. Shown in the library and in the request views. */
export function PendingRequests({ outbox, openRequest, editEntry }: {
    outbox: EmsOutbox;
    openRequest: (id: string) => void;
    editEntry: (entry: EmsOutboxEntry) => void;
}) {
    if (outbox.entries.length === 0 && outbox.sent.length === 0) return null;

    return <div className="ems-pending" role="status" aria-live="polite">
        {outbox.sent.map((notice) => <MessageBar key={notice.id} intent="success">
            <MessageBarBody><MessageBarTitle>{notice.number} was sent</MessageBarTitle>Your request for {notice.unitLabel} reached Captain 5.</MessageBarBody>
            <MessageBarActions>
                <Button size="small" onClick={() => openRequest(notice.id)}>View request</Button>
                <Button size="small" appearance="subtle" onClick={() => outbox.dismissSent(notice.id)}>Dismiss</Button>
            </MessageBarActions>
        </MessageBar>)}
        {outbox.entries.map((entry) => entry.state === 'rejected'
            ? <MessageBar key={entry.clientSubmissionId} intent="error">
                <MessageBarBody><MessageBarTitle>A request for {entry.unitLabel} was not accepted</MessageBarTitle>{entry.message} Nothing was lost. You can fix it and send it again.</MessageBarBody>
                <MessageBarActions>
                    <Button size="small" onClick={() => editEntry(entry)}>Edit and resend</Button>
                    <Button size="small" appearance="subtle" onClick={() => void outbox.discard(entry.clientSubmissionId)}>Discard</Button>
                </MessageBarActions>
            </MessageBar>
            : <MessageBar key={entry.clientSubmissionId} intent="warning">
                <MessageBarBody>
                    <MessageBarTitle>{entry.revision ? `Waiting to send the corrected form: ${entry.unitLabel}, ${itemsLabel(entry.itemCount)}` : `Waiting to send: ${entry.unitLabel}, ${itemsLabel(entry.itemCount)}`}</MessageBarTitle>
                    Saved on this device. We&apos;ll send it when you&apos;re back online.{entry.revision?.number ? ` It will replace ${entry.revision.number}.` : ''}
                </MessageBarBody>
                <MessageBarActions>
                    <Button size="small" icon={outbox.flushing ? <LoaderCircle className="spin" size={14} /> : undefined} disabled={outbox.flushing} onClick={() => void outbox.flush()}>{outbox.flushing ? 'Sending' : 'Send now'}</Button>
                    <Button size="small" appearance="subtle" disabled={outbox.flushing} onClick={() => void outbox.discard(entry.clientSubmissionId)}>Discard</Button>
                </MessageBarActions>
            </MessageBar>)}
    </div>;
}
