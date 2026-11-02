import { ChevronDown, ChevronUp } from 'lucide-react';
import { useEffect, useState } from 'react';

/** How far the on-screen keyboard covers the page, from the visual viewport. Zero when there is no keyboard. */
export function useKeyboardOffset(): number {
  const [offset, setOffset] = useState(0);
  useEffect(() => {
    const viewport = window.visualViewport;
    if (!viewport) return undefined;
    const update = () => setOffset(Math.max(0, Math.round(window.innerHeight - viewport.height - viewport.offsetTop)));
    viewport.addEventListener('resize', update);
    viewport.addEventListener('scroll', update);
    update();
    return () => { viewport.removeEventListener('resize', update); viewport.removeEventListener('scroll', update); };
  }, []);
  return offset;
}

/**
 * Phone number keypads often have no Return key, so while the keyboard is up a slim bar sits just above it with
 * "Previous item" and "Next item". It is hidden when there is no keyboard.
 */
export default function KeyboardBar({ onPrevious, onNext }: { onPrevious: () => void; onNext: () => void }) {
  const offset = useKeyboardOffset();
  if (offset < 120) return null;
  return (
    <div role="toolbar" aria-label="Move between items" data-testid="keyboard-bar" style={{ bottom: offset }}
      className="fixed inset-x-0 z-40 flex items-center justify-end gap-2 border-t border-hub-glass-border bg-hub-n-0 px-3 py-1.5 shadow-[var(--hub-elevation-3)]">
      <button type="button" className="hub-btn hub-btn--secondary !min-h-11 gap-1" onMouseDown={(event) => event.preventDefault()} onClick={onPrevious}>
        <ChevronUp className="h-5 w-5" aria-hidden="true" /><span>Previous item</span>
      </button>
      <button type="button" className="hub-btn hub-btn--primary !min-h-11 gap-1" onMouseDown={(event) => event.preventDefault()} onClick={onNext}>
        <ChevronDown className="h-5 w-5" aria-hidden="true" /><span>Next item</span>
      </button>
    </div>
  );
}
