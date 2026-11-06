import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, MessageBar, MessageBarBody } from '@fluentui/react-components';
import { LoaderCircle, Send } from 'lucide-react';
import { countRequested, familyShortTitle, itemsLabel, retiredEntries, revisionBanner, reviewGroups } from './emsLogic.js';
import type { EmsDraft, EmsFormDefinition } from './emsTypes';

/** The row a member can send back to the form to change: where the item lives, so the form can open it. */
export interface ReviewEditTarget {
    formKey: string;
    itemId: string;
    sectionKey: string;
    panelKey: string;
}

/**
 * Everything that will be sent, listed once more before the final Send: the form, the unit, then only the items with a quantity,
 * grouped by list and section, then the note. Items the member left blank are not shown. Opens as a bottom sheet on phones.
 */
export function ReviewSheet({ open, definition, draft, onDiscardRetired, onEditItem, unitLabel, sending, error, onBack, onSend }: {
    open: boolean;
    definition: EmsFormDefinition;
    draft: EmsDraft;
    /** Entries kept for items that are no longer on the form. They are listed here by name, never sent, and can be discarded. */
    onDiscardRetired: () => void;
    /** "Edit" on a line: close the sheet and go to that exact item. */
    onEditItem: (target: ReviewEditTarget) => void;
    unitLabel: string;
    sending: boolean;
    error: string;
    onBack: () => void;
    onSend: () => void;
}) {
    const groups = reviewGroups(draft, definition);
    const leftBehind = retiredEntries(draft);
    const requested = countRequested(draft);
    const note = draft.note.trim();
    const event = draft.targetChoice === 'detail' ? draft.eventAssignment.trim() : '';
    const correcting = revisionBanner(draft);

    return <Dialog open={open} modalType="modal" onOpenChange={(_, data) => { if (!data.open && !sending) onBack(); }}>
        <DialogSurface className="ems-sheet" aria-describedby="ems-review-summary">
            <DialogBody>
                <DialogTitle>Review your request</DialogTitle>
                <DialogContent>
                    <div className="ems-review" id="ems-review-summary">
                        {correcting && <p className="ems-review-replaces" id="ems-review-replaces">
                            This corrected form replaces {draft.revisesNumber ? <strong>{draft.revisesNumber}</strong> : 'the earlier form'}. Captain 5 will review this one instead.
                        </p>}
                        <p className="ems-unit-banner">For <strong>{unitLabel || 'no unit chosen'}</strong></p>
                        <dl className="ems-review-facts">
                            <div><dt>Form</dt><dd>{familyShortTitle(definition)}</dd></div>
                            <div><dt>For</dt><dd>{unitLabel}</dd></div>
                            {event && <div><dt>Event or assignment</dt><dd>{event}</dd></div>}
                            <div><dt>Items</dt><dd>{itemsLabel(requested)}</dd></div>
                        </dl>
                        {groups.length === 0 && <p className="ems-review-empty">No items are selected. Only your note will be sent.</p>}
                        {groups.map((group) => <section key={group.key} aria-label={group.name}>
                            <h3>{group.name}</h3>
                            {group.sections.map((section) => <div key={section.key} className="ems-review-section">
                                {group.sections.length > 1 || section.label !== group.name ? <h4>{section.label}</h4> : null}
                                <ul className="ems-review-list">
                                    {section.rows.map((row) => <li key={row.itemKey}>
                                        <span className="ems-review-label">{row.label}</span>
                                        <strong>{row.answered ? row.detail : `${row.detail} needed`}</strong>
                                        <Button
                                            size="small" appearance="subtle" className="ems-review-edit" aria-label={`Edit ${row.label}`} disabled={sending}
                                            onClick={() => onEditItem({ formKey: row.formKey, itemId: row.itemId, sectionKey: row.sectionKey, panelKey: row.panelKey })}
                                        >Edit</Button>
                                    </li>)}
                                </ul>
                            </div>)}
                        </section>)}
                        {leftBehind.length > 0 && <section aria-label="Saved entries for items no longer on the form" id="ems-review-retired">
                            <h3>Not sent: no longer on the form</h3>
                            <p className="ems-hint">These stay saved on this device. They are not part of this request.</p>
                            <ul className="ems-review-list">
                                {leftBehind.map((entry) => <li key={entry.key}><span className="ems-review-label">{entry.label}</span><strong>{entry.detail}</strong></li>)}
                            </ul>
                            <Button appearance="subtle" onClick={onDiscardRetired} disabled={sending}>Discard saved entries</Button>
                        </section>}
                        {note && <section aria-label="Your note"><h3>Your note</h3><p className="ems-review-note">{note}</p></section>}
                    </div>
                    {error && <MessageBar intent="error" role="alert"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
                </DialogContent>
                <DialogActions className="ems-sheet-actions">
                    <Button onClick={onBack} disabled={sending}>Back to edit</Button>
                    <Button appearance="primary" icon={sending ? <LoaderCircle className="spin" size={17} /> : <Send size={17} />} disabled={sending} onClick={onSend}>{sending ? 'Sending' : correcting ? 'Send corrected form' : 'Send request'}</Button>
                </DialogActions>
            </DialogBody>
        </DialogSurface>
    </Dialog>;
}
