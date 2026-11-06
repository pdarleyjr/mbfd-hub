import { useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { X } from 'lucide-react';
import { BTN_PRIMARY, BTN_SECONDARY } from '../shared';

/**
 * Fixed to the bottom of the screen. (`position: sticky` cannot work here: the tab panel clips its overflow.) On phones the
 * member navigation is fixed to the bottom too (`--hub-phone-nav-height`, set under 640px), so the dock rests on top of it;
 * the navigation already absorbs the bottom safe area. From 640px up the dock handles the safe area itself.
 */
const DOCK = 'fixed inset-x-3 bottom-[var(--hub-phone-nav-height,0px)] z-30 rounded-hub-control border border-hub-glass-border bg-hub-n-0 p-3 font-hub shadow-[var(--hub-elevation-3)] sm:inset-x-auto sm:bottom-0 sm:left-1/2 sm:w-full sm:max-w-3xl sm:-translate-x-1/2 sm:rounded-b-none sm:pb-[max(0.75rem,env(safe-area-inset-bottom))]';

/** The dock plus an empty block of its own height in the page flow, so the last card can always be scrolled clear of it. */
function Dock({ children, testId, label }: { children: ReactNode; testId: string; label?: string }) {
  const ref = useRef<HTMLDivElement>(null);
  const [height, setHeight] = useState(144);

  useLayoutEffect(() => {
    const element = ref.current;
    if (!element) return undefined;
    const measure = () => setHeight(Math.ceil(element.getBoundingClientRect().height));
    measure();
    if (typeof ResizeObserver === 'undefined') return undefined;
    const observer = new ResizeObserver(measure);
    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  return (
    <>
      <div aria-hidden="true" data-testid={`${testId}-spacer`} style={{ height: height + 16 }} />
      <div ref={ref} className={DOCK} role={label ? 'region' : undefined} aria-label={label} data-testid={testId}>{children}</div>
    </>
  );
}

interface SelectionBarProps {
  count: number;
  onReconcile: () => void;
  /** Unselect everything. */
  onClear: () => void;
  /** Clear the selected forms (supplies handed over): archived, stock unchanged. Absent where Clear is not offered. */
  onClearForms?: () => void;
  clearing?: boolean;
}

/** Shown while anything is selected: how many, Reconcile (takes items off the shelf), Clear (archives only) and Unselect all. */
export function SelectionBar({ count, onReconcile, onClear, onClearForms, clearing = false }: SelectionBarProps) {
  const noun = count === 1 ? 'form' : 'forms';
  return (
    <Dock testId="ems-selection-bar" label="Selected forms">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p className="font-heading text-base font-bold text-hub-ink" aria-live="polite" data-testid="ems-selection-count">
          {count} {noun} selected
        </p>
        <button type="button" className={BTN_SECONDARY} disabled={clearing} onClick={onClear}>Unselect all</button>
      </div>
      <button type="button" data-testid="ems-reconcile-open" className={`${BTN_PRIMARY} mt-2 w-full`} disabled={clearing} onClick={onReconcile}>
        Reconcile with Supply Room Inventory
      </button>
      {onClearForms && (
        <>
          <button type="button" data-testid="ems-clear-selected" className={`${BTN_SECONDARY} mt-2 w-full`} disabled={clearing} aria-busy={clearing} onClick={onClearForms}>
            {clearing ? 'Clearing…' : `Clear ${count} ${noun} (supplies provided)`}
          </button>
          <p className="mt-1.5 text-[13px] leading-5 text-hub-n-600">Reconcile takes the items off the supply room shelf. Clear moves the forms to Archived without changing stock.</p>
        </>
      )}
    </Dock>
  );
}

/**
 * The result of a reconcile or a Clear, shown until dismissed. After a Clear, Undo restores the forms to Active.
 * The tab's own live region announces the same words to screen readers.
 */
export function DoneNotice({ text, onDismiss, onUndo, undoing = false, tone = 'success' }: {
  text: string; onDismiss: () => void; onUndo?: () => void; undoing?: boolean; tone?: 'success' | 'warning';
}) {
  return (
    <Dock testId="ems-done-notice">
      <div className="flex items-start justify-between gap-3">
        <p className={`min-w-0 flex-1 break-words font-semibold ${tone === 'success' ? 'text-hub-success' : 'text-hub-warning'}`}>{text}</p>
        {onUndo && (
          <button type="button" data-testid="ems-done-undo" className={`${BTN_SECONDARY} flex-none`} disabled={undoing} aria-busy={undoing} onClick={onUndo}>
            {undoing ? 'Restoring…' : 'Undo'}
          </button>
        )}
        <button type="button" className={`${BTN_SECONDARY} !min-w-11 flex-none`} aria-label="Dismiss message" onClick={onDismiss}>
          <X className="h-4 w-4" aria-hidden="true" />
        </button>
      </div>
    </Dock>
  );
}
