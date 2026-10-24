import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, MessageBar, MessageBarBody } from '@fluentui/react-components';
import { LoaderCircle, Send } from 'lucide-react';
import { countRequested, itemsLabel, reviewGroups } from './emsLogic.js';
import type { EmsDraft, EmsFormDefinition } from './emsTypes';

/** Everything that will be sent, listed once more before the final Send. Opens as a bottom sheet on phones. */
export function ReviewSheet({ open, definition, draft, unitLabel, sending, error, onBack, onSend }: {
    open: boolean;
    definition: EmsFormDefinition;
    draft: EmsDraft;
    unitLabel: string;
    sending: boolean;
    error: string;
    onBack: () => void;
    onSend: () => void;
}) {
    const groups = reviewGroups(draft, definition);
    const requested = countRequested(draft);
    const note = draft.note.trim();
    const event = draft.targetChoice === 'detail' ? draft.eventAssignment.trim() : '';

    return <Dialog open={open} modalType="modal" onOpenChange={(_, data) => { if (!data.open && !sending) onBack(); }}>
        <DialogSurface className="ems-sheet" aria-describedby="ems-review-summary">
            <DialogBody>
                <DialogTitle>Review your request</DialogTitle>
                <DialogContent>
                    <div className="ems-review" id="ems-review-summary">
                        <dl className="ems-review-facts">
                            <div><dt>For</dt><dd>{unitLabel}</dd></div>
                            {event && <div><dt>Event or assignment</dt><dd>{event}</dd></div>}
                            <div><dt>Items</dt><dd>{itemsLabel(requested)}</dd></div>
                        </dl>
                        {groups.length === 0 && <p className="ems-review-empty">No items are selected. Only your note will be sent.</p>}
                        {groups.map((group) => <section key={group.key} aria-label={group.name}>
                            <h3>{group.name}</h3>
                            <ul className="ems-review-list">
                                {group.rows.map((row) => <li key={row.itemKey}><span>{row.label}</span><strong>{row.answered ? row.detail : `${row.detail} needed`}</strong></li>)}
                            </ul>
                        </section>)}
                        {note && <section aria-label="Your note"><h3>Your note</h3><p className="ems-review-note">{note}</p></section>}
                    </div>
                    {error && <MessageBar intent="error" role="alert"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
                </DialogContent>
                <DialogActions className="ems-sheet-actions">
                    <Button onClick={onBack} disabled={sending}>Back to edit</Button>
                    <Button appearance="primary" icon={sending ? <LoaderCircle className="spin" size={17} /> : <Send size={17} />} disabled={sending} onClick={onSend}>{sending ? 'Sending' : 'Send request'}</Button>
                </DialogActions>
            </DialogBody>
        </DialogSurface>
    </Dialog>;
}
