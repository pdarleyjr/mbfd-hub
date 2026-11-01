import { useId, type MouseEvent } from 'react';
import { Check, CornerDownLeft } from 'lucide-react';
import Tag from '../ui/Tag';
import { LINE_NOTE_MAX } from './changeoutLogic';
import type { EquipmentLine } from './types';

interface RowProps {
  line: EquipmentLine;
  /** The note when this item was left, or null when it was taken. */
  leftNote: string | null;
  previousTruck: string | null;
  onToggle: (event: MouseEvent<HTMLButtonElement>) => void;
  onNote: (note: string) => void;
}

const previousText = (line: EquipmentLine, truck: string | null): string | null => {
  if (line.previous?.handling !== 'leave') return null;
  const base = `Left on truck ${truck ?? 'not recorded'} last time`;

  return line.previous.note ? `${base}: ${line.previous.note}` : base;
};

/** One item. The whole row is the switch between Took and Left, and a note field opens under a Left item. */
export default function EquipmentLineRow({ line, leftNote, previousTruck, onToggle, onNote }: RowProps) {
  const noteId = useId();
  const isLeft = leftNote !== null;
  const previous = previousText(line, previousTruck);

  return (
    <li className={isLeft ? 'bg-hub-n-50' : undefined}>
      <button
        type="button"
        onClick={onToggle}
        className="flex min-h-11 w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-hub-n-50 active:bg-hub-n-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-hub-focus"
      >
        <span className="min-w-0">
          <span className="block font-medium text-hub-ink">{line.name}</span>
          {previous && <span className="block text-[13px] leading-5 text-hub-n-600">{previous}</span>}
        </span>
        <span className="flex-none">
          {isLeft
            ? <Tag tone="warning"><CornerDownLeft className="mr-1 inline h-4 w-4" aria-hidden="true" />Left</Tag>
            : <Tag><Check className="mr-1 inline h-4 w-4" aria-hidden="true" />Took</Tag>}
        </span>
      </button>
      {isLeft && (
        <div className="px-3 pb-3">
          <label htmlFor={noteId} className="text-sm font-semibold text-hub-ink">Note for {line.name} (optional)</label>
          <input
            id={noteId}
            type="text"
            value={leftNote}
            maxLength={LINE_NOTE_MAX}
            autoComplete="off"
            onChange={(event) => onNote(event.target.value)}
            className="mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 text-hub-ink"
          />
        </div>
      )}
    </li>
  );
}
