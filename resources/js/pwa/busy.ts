/**
 * "Busy" registry: a page-wide, framework-free way for any feature to say
 * "do not reload or navigate this window right now".
 *
 * It lives on a single window global so independent Vite bundles (admin
 * console, Hub shell, Operational Forms, video conferencing, ...) share one
 * registry without importing each other. The update registrar consults it
 * before every automatic reload or notification navigation.
 *
 *   markBusy('unsaved-draft')       // a member is typing something that is not saved
 *   clearBusy('unsaved-draft')      // saved, discarded or submitted
 *   markBusy('queued-submission')   // an offline submission is waiting to sync
 *   markBusy('active-call')         // a video call is in progress
 *
 * Reasons are plain strings and idempotent: marking the same reason twice and
 * clearing it once leaves the page not busy for that reason.
 */

export const BUSY_CHANGE_EVENT = 'mbfd:busy-change';

type BusyWindow = Window & { __mbfdBusy?: Set<string> };

function registry(): Set<string> {
    const target = window as BusyWindow;
    if (!(target.__mbfdBusy instanceof Set)) {
        target.__mbfdBusy = new Set<string>();
    }

    return target.__mbfdBusy;
}

function announce(): void {
    window.dispatchEvent(new CustomEvent(BUSY_CHANGE_EVENT, { detail: { reasons: busyReasons() } }));
}

export function markBusy(reason: string): void {
    const reasons = registry();
    if (reasons.has(reason)) return;
    reasons.add(reason);
    announce();
}

export function clearBusy(reason: string): void {
    if (!registry().delete(reason)) return;
    announce();
}

/**
 * Pages whose work lives in script state the field tracking cannot see (photos
 * held in memory, signature canvases, IndexedDB-backed editors) opt in with
 * `data-mbfd-unsaved-work-guard` on their root: while it is on the page the
 * window never reloads on its own; the update banner asks instead.
 */
export const UNSAVED_WORK_GUARD_SELECTOR = '[data-mbfd-unsaved-work-guard]';

function hasUnsavedWorkGuard(): boolean {
    return typeof document !== 'undefined'
        && typeof document.querySelector === 'function'
        && document.querySelector(UNSAVED_WORK_GUARD_SELECTOR) !== null;
}

export function isBusy(): boolean {
    return registry().size > 0 || hasUnsavedWorkGuard();
}

export function busyReasons(): string[] {
    const reasons = Array.from(registry());

    return hasUnsavedWorkGuard() ? [...reasons, 'unsaved-work-guard'] : reasons;
}

/** Subscribe to busy-state changes. Returns an unsubscribe function. */
export function onBusyChange(listener: () => void): () => void {
    window.addEventListener(BUSY_CHANGE_EVENT, listener);

    return () => window.removeEventListener(BUSY_CHANGE_EVENT, listener);
}

// ---------------------------------------------------------------------------
// Edits the DOM cannot reveal: rich-text editors, file pickers
// ---------------------------------------------------------------------------

/**
 * Comparing a control with its server-rendered default (see hasUnsavedFieldEdits) cannot see
 * text typed into a contenteditable surface (Trix, Filament's rich editor, any
 * ProseMirror-style editor: the text lives in the surface and is only mirrored into a hidden
 * input), and file inputs are skipped because their value is never "default". For those, a
 * capturing, trusted `input`/`change` listener on the document remembers WHICH surface the
 * member edited. The record is a per-page set of elements, so it heals itself: an element that
 * leaves the page, or a file input that was emptied, stops counting. It is cleared when a
 * Livewire action succeeds (a save), when a non-prevented form submit hands the page over to
 * the browser, and when Livewire navigates. Explicit markBusy reasons are unaffected.
 *
 * Only user input counts: `isTrusted` is false for the events scripts dispatch (Trix mirroring
 * its text into the hidden input, a framework re-rendering), so the page cannot mark itself dirty.
 */
type FieldTrackingWindow = Window & { __mbfdDirtyFields?: Set<Element>; __mbfdFieldTracking?: boolean };

const IGNORED_EDIT_AREAS = '[role="search"], [data-mbfd-ignore-edits]';
const EDITABLE_SURFACES = '[contenteditable]:not([contenteditable="false"]), [role="textbox"]';

function dirtyFields(): Set<Element> {
    const target = window as FieldTrackingWindow;
    if (!(target.__mbfdDirtyFields instanceof Set)) {
        target.__mbfdDirtyFields = new Set<Element>();
    }

    return target.__mbfdDirtyFields;
}

function isFileInput(element: Element): element is HTMLInputElement {
    return element.tagName === 'INPUT' && (element as HTMLInputElement).type === 'file';
}

/** The element whose contents the member changed, when that change is invisible to the DOM comparison. */
function editedSurface(target: EventTarget | null): Element | null {
    const element = target as Element | null;
    if (element === null || typeof element.closest !== 'function' || typeof element.tagName !== 'string') return null;
    if (element.closest(IGNORED_EDIT_AREAS)) return null;

    if (isFileInput(element)) return element;
    // An editor surface (or something typed inside one): the surface is what holds the text.
    const surface = element.closest(EDITABLE_SURFACES) ?? ((element as HTMLElement).isContentEditable === true ? element : null);
    if (surface !== null) return surface;
    // A trusted event on the hidden input an editor mirrors into.
    if (element.tagName === 'INPUT' && (element as HTMLInputElement).type === 'hidden') return element;

    // Ordinary inputs, textareas and selects are compared with their defaults at decision time.
    return null;
}

/** Record a user edit that the DOM comparison cannot see. Exported for tests; ignores untrusted events. */
export function recordFieldEdit(event: { isTrusted: boolean; target: EventTarget | null }): void {
    if (event.isTrusted !== true) return;
    recordTypedEdit(event);
    const surface = editedSurface(event.target);
    if (surface === null) return;
    if (isFileInput(surface) && surface.files?.length === 0) {
        dirtyFields().delete(surface);

        return;
    }
    dirtyFields().add(surface);
}

// ---------------------------------------------------------------------------
// Text the member typed: what a manual refresh must never discard
// ---------------------------------------------------------------------------

/**
 * Filament and Livewire fill form controls from script after the HTML arrives, so "value differs
 * from the server-rendered default" (hasUnsavedFieldEdits) is true on most admin pages before the
 * member touches anything. That comparison stays as the conservative rule for automatic reloads;
 * a refresh the member asks for refuses only when they actually typed: a trusted `input` event on
 * a text-entry control whose value now differs from what it held when they focused it. Selects,
 * checkboxes and radios are not "typed work" (a filter or a toggle is cheap to redo). The record
 * is cleared with the other field edits (a successful Livewire save, a submitted form, a Livewire
 * navigation), and a field typed back to its starting value stops counting.
 */
type TypedTrackingWindow = Window & { __mbfdTypedFields?: Map<Element, string> };

const NON_TEXT_INPUT_TYPES = new Set(['hidden', 'button', 'submit', 'reset', 'image', 'search', 'file', 'checkbox', 'radio', 'range', 'color']);

/** Value a text-entry control held when the member focused it (before any typing). */
const typingBaselines = new WeakMap<Element, string>();

/** Typed-into control -> the value it held when the member started editing it. */
function typedFields(): Map<Element, string> {
    const target = window as TypedTrackingWindow;
    if (!(target.__mbfdTypedFields instanceof Map)) {
        target.__mbfdTypedFields = new Map<Element, string>();
    }

    return target.__mbfdTypedFields;
}

function textEntryControl(target: EventTarget | null): HTMLInputElement | HTMLTextAreaElement | null {
    const element = target as Element | null;
    if (element === null || typeof element.closest !== 'function' || typeof element.tagName !== 'string') return null;
    if (element.closest(IGNORED_EDIT_AREAS)) return null;
    if (element.tagName === 'TEXTAREA') return element as HTMLTextAreaElement;
    if (element.tagName === 'INPUT' && !NON_TEXT_INPUT_TYPES.has((element as HTMLInputElement).type)) return element as HTMLInputElement;

    return null;
}

/** Focus: remember the starting value, so typing it back counts as unchanged. Exported for tests. */
export function rememberTypingBaseline(event: { target: EventTarget | null }): void {
    const control = textEntryControl(event.target);
    if (control !== null && !typedFields().has(control)) typingBaselines.set(control, control.value);
}

/** A trusted keystroke in a text-entry control. Exported for tests; ignores untrusted events. */
export function recordTypedEdit(event: { isTrusted: boolean; target: EventTarget | null }): void {
    if (event.isTrusted !== true) return;
    const control = textEntryControl(event.target);
    if (control === null) return;
    const fields = typedFields();
    if (!fields.has(control)) fields.set(control, typingBaselines.get(control) ?? control.defaultValue);
}

/** True while a control the member typed into holds text that differs from where they started. */
export function hasTypedFieldEdits(root: ParentNode = document): boolean {
    const fields = typedFields();
    for (const [control, start] of Array.from(fields)) {
        const field = control as HTMLInputElement | HTMLTextAreaElement;
        if (!field.isConnected) {
            fields.delete(control);
            continue;
        }
        if (field.disabled || field.value === start) continue;
        if (root === document || (root as Node).contains(field)) return true;
    }

    return false;
}

/** Forget recorded edits: everything, or only those inside `within` (a form that was submitted). */
export function clearFieldEdits(within?: Element): void {
    const fields = dirtyFields();
    for (const element of Array.from(fields)) {
        if (within === undefined || within.contains(element)) fields.delete(element);
    }
    const typed = typedFields();
    for (const element of Array.from(typed.keys())) {
        if (within === undefined || within.contains(element)) typed.delete(element);
    }
}

/** Recorded edits that still exist: gone elements and emptied file inputs no longer count. */
export function hasRecordedFieldEdits(root: ParentNode = document): boolean {
    const fields = dirtyFields();
    let found = false;
    for (const element of Array.from(fields)) {
        if (!element.isConnected || (isFileInput(element) && element.files?.length === 0)) {
            fields.delete(element);
            continue;
        }
        if (root === document || (root as Node).contains(element)) found = true;
    }

    return found;
}

interface LivewireCommit {
    calls?: Array<{ method?: unknown }>;
}

type LivewireHook = (name: 'commit', callback: (context: { commit?: LivewireCommit; succeed?: (callback: () => void) => void }) => void) => void;

/** A successful Livewire action (a save, a create) means what the member typed reached the server. */
function watchLivewireSaves(): void {
    const register = (): void => {
        const hook = (window as unknown as { Livewire?: { hook?: LivewireHook } }).Livewire?.hook;
        if (typeof hook !== 'function') return;
        try {
            hook('commit', ({ commit, succeed }) => {
                // Polls and refreshes ($refresh, $commit ...) are not saves.
                const saves = (commit?.calls ?? []).some((call) => typeof call.method === 'string' && !call.method.startsWith('$'));
                if (saves && typeof succeed === 'function') succeed(() => clearFieldEdits());
            });
        } catch {
            // An unfamiliar Livewire version: edits stay recorded, which only ever errs towards "busy".
        }
    };

    if ((window as unknown as { Livewire?: unknown }).Livewire) register();
    else document.addEventListener('livewire:init', register, { once: true });
}

/**
 * Start recording edits the DOM comparison cannot see. Idempotent per page, shared by every
 * bundle through a window flag.
 */
export function startFieldEditTracking(): void {
    const target = window as FieldTrackingWindow;
    if (target.__mbfdFieldTracking === true) return;
    target.__mbfdFieldTracking = true;

    document.addEventListener('input', recordFieldEdit, true);
    document.addEventListener('change', recordFieldEdit, true);
    document.addEventListener('focusin', rememberTypingBaseline, true);
    document.addEventListener('submit', (event) => {
        // Not handled by a script, so the browser is about to navigate with the form's contents.
        const form = event.target as Element | null;
        if (!event.defaultPrevented && form !== null && typeof form.contains === 'function') clearFieldEdits(form);
    });
    document.addEventListener('livewire:navigated', () => clearFieldEdits());
    watchLivewireSaves();
}

const NON_DOCUMENT_INPUT_TYPES = new Set(['hidden', 'button', 'submit', 'reset', 'image', 'search', 'file']);

/**
 * Safety net for pages that never call markBusy: true when a member has typed
 * into, ticked or picked something in an ordinary form control that still
 * differs from what the server rendered, or has edited a rich-text editor or
 * chosen a file (see startFieldEditTracking). Search boxes and anything inside
 * [role="search"] or [data-mbfd-ignore-edits] are ignored, so filtering a
 * table never blocks an update.
 *
 * Form controls are compared at decision time (never stored), so they cannot get stuck;
 * recorded editor/file edits are pruned the same way (see hasRecordedFieldEdits).
 */
export function hasUnsavedFieldEdits(root: ParentNode = document): boolean {
    if (hasRecordedFieldEdits(root)) return true;

    const controls = root.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('input, textarea, select');

    for (const control of Array.from(controls)) {
        if (control.disabled || control.closest('[role="search"], [data-mbfd-ignore-edits]')) continue;

        if (control instanceof HTMLSelectElement) {
            if (Array.from(control.options).some((option) => option.selected !== option.defaultSelected)) return true;
            continue;
        }

        if (control instanceof HTMLInputElement) {
            if (NON_DOCUMENT_INPUT_TYPES.has(control.type)) continue;
            if (control.type === 'checkbox' || control.type === 'radio') {
                if (control.checked !== control.defaultChecked) return true;
                continue;
            }
        }

        if (control.value !== control.defaultValue) return true;
    }

    return false;
}

/**
 * THE safety predicate for every refresh a member can see or ask for (banner state, "Refresh
 * now", and the final recheck before that reload): an explicit busy reason, the page guard, an
 * edited rich-text editor or chosen file, or text the member typed and has not saved.
 */
export function isUnsafeToRefresh(): boolean {
    return isBusy() || hasRecordedFieldEdits() || hasTypedFieldEdits();
}

/**
 * Reloads and navigations nobody asked for (the hidden-tab refresh, automatic chunk recovery, a
 * notification click while work is open) are stricter still: any control that differs from the
 * server-rendered page also holds them back.
 */
export function isUnsafeForAutomaticReload(): boolean {
    return isUnsafeToRefresh() || hasUnsavedFieldEdits();
}
