import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { FIELD } from '../shared';
import { sectionOptionLabel, shelfPosition, stepIndex } from './logic';
import type { MasterSection } from './types';

interface Props {
  sections: readonly MasterSection[];
  index: number;
  onIndex: (index: number) => void;
  placement: 'top' | 'bottom';
  /** The compact native picker between the arrows. */
  picker?: boolean;
  /** Extra line under the shelf name (the count form puts its progress here). */
  children?: ReactNode;
  className?: string;
}

const ARROW = 'hub-btn hub-btn--secondary !min-h-11 !min-w-11 flex-none gap-1 !px-2.5 sm:!px-3.5';

/**
 * Previous and next shelf with the shelf name and "Shelf 3 of 49". The same control sits above and below a shelf,
 * so a long shelf never needs a scroll back to the top to move on.
 */
export default function ShelfNav({ sections, index, onIndex, placement, picker = false, children, className = '' }: Props) {
  const total = sections.length;
  const current = sections[index];
  const previous = sections[index - 1];
  const next = sections[index + 1];
  if (!current) return null;

  return (
    <nav aria-label={`Shelves, ${placement}`} data-testid={`shelf-nav-${placement}`} className={`flex items-center gap-2 ${className}`}>
      <button type="button" className={ARROW} disabled={!previous} aria-label={previous ? `Previous shelf, ${previous.label}` : 'Previous shelf'}
        data-testid={`shelf-prev-${placement}`} onClick={() => onIndex(stepIndex(index, -1, total))}>
        <ChevronLeft className="h-5 w-5" aria-hidden="true" />
        <span className="hidden sm:inline">Previous</span>
      </button>

      <div className="min-w-0 flex-1 text-center">
        <p className="break-words font-heading text-base font-bold leading-tight text-hub-ink" data-testid={`shelf-name-${placement}`}>{current.label}</p>
        <p className="text-[13px] leading-5 text-hub-n-600" data-testid={`shelf-position-${placement}`}>{shelfPosition(index, total)}</p>
        {children}
        {picker && (
          <select aria-label="Go to shelf" data-testid="shelf-picker" className={`${FIELD} mt-1`} value={index}
            onChange={(event) => onIndex(Number(event.target.value))}>
            {sections.map((section, position) => <option key={section.code ?? 'none'} value={position}>{sectionOptionLabel(section)}</option>)}
          </select>
        )}
      </div>

      <button type="button" className={ARROW} disabled={!next} aria-label={next ? `Next shelf, ${next.label}` : 'Next shelf'}
        data-testid={`shelf-next-${placement}`} onClick={() => onIndex(stepIndex(index, 1, total))}>
        <span className="hidden sm:inline">Next</span>
        <ChevronRight className="h-5 w-5" aria-hidden="true" />
      </button>
    </nav>
  );
}
