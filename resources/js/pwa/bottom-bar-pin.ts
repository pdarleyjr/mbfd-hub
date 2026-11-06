/**
 * Keeps the phone bottom navigation on the visible bottom edge.
 *
 * iOS 26 Safari can leave `visualViewport.offsetTop` stale after the keyboard closes or while its
 * toolbar resizes. `position: fixed; bottom: 0` is then anchored to a layout viewport that no longer
 * matches what the member sees, and the bar floats up the screen (about a keyboard's height) while
 * the page scrolls behind it. This measures how far the visible bottom edge sits below the layout
 * viewport's bottom and shifts the bars down by exactly that much through `--hub-bottom-bar-shift`
 * (hub-shell.css applies it to `bottom`, never a transform: a transform would make the bar the
 * containing block of its own "More" sheet).
 *
 * The shift is only ever downward and never applied while a text field has focus: with the keyboard
 * open the bar stays where every browser puts it today. Browsers without the bug measure 0.
 */
const BAR_SELECTOR = '.hub-member-nav, .hub-member-navigation';
const PROPERTY = '--hub-bottom-bar-shift';
const EDITABLE = 'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]), textarea, select, [contenteditable=""], [contenteditable="true"]';

let started = false;

export function bottomBarShift(viewport: { offsetTop: number; height: number }, layoutHeight: number, editing: boolean): number {
    if (editing) return 0;
    const shift = Math.round(viewport.offsetTop + viewport.height - layoutHeight);
    // Sub-pixel noise and rounding are not worth a repaint; a real drift is tens of pixels or more.
    return shift > 2 ? shift : 0;
}

export function startBottomBarPin(): void {
    const viewport = window.visualViewport;
    if (started || !viewport) return;
    started = true;

    const root = document.documentElement;
    let frame = 0;
    let applied = 0;

    const update = (): void => {
        frame = 0;
        if (!document.querySelector(BAR_SELECTOR)) return;
        const editing = document.activeElement instanceof Element && document.activeElement.matches(EDITABLE);
        const shift = bottomBarShift(viewport, window.innerHeight, editing);
        if (shift === applied) return;
        applied = shift;
        if (shift === 0) root.style.removeProperty(PROPERTY);
        else root.style.setProperty(PROPERTY, `${shift}px`);
    };
    const schedule = (): void => {
        if (frame === 0) frame = window.requestAnimationFrame(update);
    };

    viewport.addEventListener('resize', schedule);
    viewport.addEventListener('scroll', schedule);
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('orientationchange', schedule);
    window.addEventListener('pageshow', schedule);
    document.addEventListener('focusin', schedule);
    // The keyboard closing is when Safari leaves offsetTop stale: re-measure as it settles.
    document.addEventListener('focusout', () => {
        schedule();
        window.setTimeout(schedule, 350);
    });
    schedule();
}
