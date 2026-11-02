import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { groupBySection, nextItemPosition, previousItemPosition, sectionParam } from './logic';
import type { MasterItem, MasterSection } from './types';

interface Options {
  sections: readonly MasterSection[];
  items: readonly MasterItem[];
  shelfIndex: number;
  /** Move to a shelf without adding a history entry. */
  setShelf: (index: number) => void;
  /** Changes when the count form switches between counting and review, so a waiting focus request can run. */
  view: string;
}

/**
 * Keeps the cursor moving through the count: Enter goes to the next item, across shelves; a search result or a missed
 * item from the review jumps to its shelf and lands in its number box; the focused box is kept above the keyboard.
 */
export function useCountNavigation({ sections, items, shelfIndex, setShelf, view }: Options) {
  const inputs = useRef(new Map<number, HTMLInputElement>());
  const pending = useRef<number | null>(null);
  const reviewButton = useRef<HTMLButtonElement>(null);
  const [focusedId, setFocusedId] = useState<number | null>(null);

  const shelves = useMemo(() => {
    const groups = groupBySection(items, sections);
    return sections.map((section) => groups.get(sectionParam(section)) ?? []);
  }, [items, sections]);

  const locate = useCallback((itemId: number): { shelf: number; row: number } | null => {
    for (let shelf = 0; shelf < shelves.length; shelf += 1) {
      const row = shelves[shelf].findIndex((item) => item.id === itemId);
      if (row >= 0) return { shelf, row };
    }
    return null;
  }, [shelves]);

  const register = useCallback((itemId: number, input: HTMLInputElement | null) => {
    if (input) inputs.current.set(itemId, input); else inputs.current.delete(itemId);
  }, []);

  const focusInput = useCallback((itemId: number): boolean => {
    const input = inputs.current.get(itemId);
    if (!input) return false;
    input.focus();
    return true;
  }, []);

  const goToItem = useCallback((itemId: number) => {
    const place = locate(itemId);
    if (!place) return;
    if (place.shelf === shelfIndex && focusInput(itemId)) return;
    pending.current = itemId;
    if (place.shelf !== shelfIndex) setShelf(place.shelf);
  }, [focusInput, locate, setShelf, shelfIndex]);

  // A focus request waits here until the shelf (or the counting view) has been drawn.
  useEffect(() => {
    if (pending.current !== null && focusInput(pending.current)) pending.current = null;
  }, [shelfIndex, view, items, focusInput]);

  const step = useCallback((itemId: number, direction: 1 | -1) => {
    const place = locate(itemId);
    if (!place) return;
    const target = direction === 1 ? nextItemPosition(shelves, place.shelf, place.row) : previousItemPosition(shelves, place.shelf, place.row);
    if (target) goToItem(shelves[target.shelf][target.row].id);
    else if (direction === 1) reviewButton.current?.focus();
  }, [goToItem, locate, shelves]);

  const onEnter = useCallback((itemId: number) => step(itemId, 1), [step]);
  const stepFocused = useCallback((direction: 1 | -1) => { if (focusedId !== null) step(focusedId, direction); }, [focusedId, step]);

  const onFocusItem = useCallback((itemId: number, input: HTMLInputElement) => {
    setFocusedId(itemId);
    window.setTimeout(() => input.scrollIntoView({ block: 'center' }), 60);
  }, []);

  // When the keyboard opens the visible area shrinks; bring the box back to the middle of what is left.
  useEffect(() => {
    const viewport = window.visualViewport;
    if (!viewport) return undefined;
    const keepVisible = () => {
      const active = document.activeElement;
      if (active instanceof HTMLInputElement && Array.from(inputs.current.values()).includes(active)) active.scrollIntoView({ block: 'center' });
    };
    viewport.addEventListener('resize', keepVisible);
    return () => viewport.removeEventListener('resize', keepVisible);
  }, []);

  return { shelves, register, goToItem, onEnter, onFocusItem, stepFocused, reviewButton };
}
