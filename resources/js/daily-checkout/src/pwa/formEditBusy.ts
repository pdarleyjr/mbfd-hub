import { setUnsavedWork } from './draftBusy.ts';

/**
 * Safety net for Daily forms that keep their state in memory only (station
 * requests, station inspection, TRT inventory, ...). The shared registrar's own
 * check compares a field with its default value, but React keeps the value
 * attribute in step with what was typed, so it cannot see these edits. Without
 * this, an update could reload a hidden page and lose a half-filled request.
 *
 * It is self-healing, so it can never pin the page busy:
 *  - a control counts only while it is still in the document, enabled and holding
 *    something (text, a ticked box, a chosen option);
 *  - it is re-evaluated when the control changes, when the DOM changes (a
 *    submitted form unmounts or resets), and on a slow timer while anything is held.
 *
 * Not handled here, on purpose:
 *  - the inspection wizard (autosave reports exactly when its edits are on disk),
 *  - anything inside [data-mbfd-ignore-edits] (for example the command composer,
 *    which reports its own draft), search boxes, filters and [role="search"].
 */
const TOKEN = 'daily-form-edits';
const RECHECK_MS = 3_000;
const WORKSPACE_SELECTOR = '#main-content';
const IGNORE_SELECTOR = '[role="search"], [data-mbfd-ignore-edits]';
const NON_DOCUMENT_INPUT_TYPES = new Set(['hidden', 'button', 'submit', 'reset', 'image', 'search', 'range', 'color']);
const INSPECTION_ROUTE = /^\/daily\/(?:vehicle-inspections|apparatus)\/[^/]+\/?$/;

type EditableControl = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;

function isEditable(target: EventTarget | null): target is EditableControl {
    return target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement;
}

const FILTER_HINT = /(?:search|filter)/i;

/** Filtering a list is not unsaved work, even in a plain text box. */
function looksLikeFilter(control: EditableControl): boolean {
    return control.getAttribute('inputmode') === 'search'
        || control.getAttribute('enterkeyhint') === 'search'
        || FILTER_HINT.test(`${control.getAttribute('aria-label') ?? ''} ${control.getAttribute('placeholder') ?? ''} ${control.name} ${control.id}`);
}

function isInspectionRoute(pathname: string): boolean {
    return INSPECTION_ROUTE.test(pathname) && !pathname.endsWith('/success');
}

/** True when the control holds something a person entered and nothing has saved yet. */
function holdsEdit(control: EditableControl): boolean {
    if (!control.isConnected || control.disabled) return false;

    if (control instanceof HTMLSelectElement) return control.selectedIndex > 0 && control.value !== '';
    if (control instanceof HTMLInputElement) {
        if (NON_DOCUMENT_INPUT_TYPES.has(control.type)) return false;
        if (control.type === 'checkbox' || control.type === 'radio') return control.checked;
        if (control.type === 'file') return (control.files?.length ?? 0) > 0;
    }

    return control.value.trim() !== '';
}

export function startFormEditWatcher(root: Document = document): () => void {
    const tracked = new Set<EditableControl>();
    let timer: ReturnType<typeof setTimeout> | undefined;
    let observer: MutationObserver | undefined;

    const evaluate = () => {
        if (timer !== undefined) clearTimeout(timer);
        timer = undefined;
        for (const control of Array.from(tracked)) {
            if (!holdsEdit(control)) tracked.delete(control);
        }
        setUnsavedWork(TOKEN, tracked.size > 0);
        if (tracked.size > 0) timer = setTimeout(evaluate, RECHECK_MS);
    };

    const onEdit = (event: Event) => {
        const target = event.target;
        if (!isEditable(target) || !event.isTrusted) return;
        if (!target.closest(WORKSPACE_SELECTOR) || target.closest(IGNORE_SELECTOR) || looksLikeFilter(target)) return;
        if (isInspectionRoute(root.defaultView?.location.pathname ?? '')) return;

        tracked.add(target);
        evaluate();
    };

    root.addEventListener('input', onEdit, true);
    root.addEventListener('change', onEdit, true);
    // Re-check right before the registrar decides about a hidden page.
    root.addEventListener('visibilitychange', evaluate);
    if (typeof MutationObserver !== 'undefined' && root.body) {
        observer = new MutationObserver(() => { if (tracked.size > 0) evaluate(); });
        observer.observe(root.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['value', 'checked', 'disabled'] });
    }

    return () => {
        root.removeEventListener('input', onEdit, true);
        root.removeEventListener('change', onEdit, true);
        root.removeEventListener('visibilitychange', evaluate);
        observer?.disconnect();
        if (timer !== undefined) clearTimeout(timer);
        tracked.clear();
        setUnsavedWork(TOKEN, false);
    };
}
