import { memo, type KeyboardEvent } from 'react';
import { digitsOnly, orderFor, orderText, parseEntry, unitWord } from './logic';
import type { MasterItem } from './types';

interface Props {
  item: MasterItem;
  /** The typed number, or null while the item is blank (not entered). */
  value: number | null;
  onChange: (itemId: number, value: number | null) => void;
  onEnter: (itemId: number) => void;
  onFocusItem: (itemId: number, input: HTMLInputElement) => void;
  register: (itemId: number, input: HTMLInputElement | null) => void;
}

/** One item: the name, the order it needs as soon as a number is typed, and one large numeric box. */
function CountRow({ item, value, onChange, onEnter, onFocusItem, register }: Props) {
  const entered = value !== null;
  const result = entered ? orderFor(value, item) : null;
  const text = result ? orderText(result, item) : '';
  const unit = unitWord(item, value ?? 2);
  const nameId = `count-name-${item.id}`;
  const resultId = `count-result-${item.id}`;

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter') { event.preventDefault(); onEnter(item.id); }
  };

  return (
    <li data-testid="count-row" data-item-id={item.id} data-entered={entered ? 'true' : 'false'}
      className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 border-b border-hub-border-soft px-3 py-2.5 last:border-b-0">
      <div className="min-w-0">
        <p id={nameId} className="font-semibold leading-snug text-hub-ink [overflow-wrap:anywhere]" data-testid="count-name">{item.name}</p>
        <p id={resultId} aria-live="polite" className="min-h-5 text-[13px] leading-5" data-testid="order-result" data-kind={result?.kind ?? 'blank'}>
          {result === null
            ? <span className="sr-only">Not entered yet</span>
            : <span className={result.kind === 'order' ? 'font-bold text-hub-danger' : 'text-hub-n-600'}>{text}</span>}
        </p>
      </div>
      <div className="flex flex-col items-center gap-0.5 sm:flex-row sm:gap-2">
        <input
          ref={(element) => register(item.id, element)}
          type="text"
          inputMode="numeric"
          pattern="[0-9]*"
          enterKeyHint="next"
          autoComplete="off"
          autoCorrect="off"
          spellCheck={false}
          aria-label={`How many do you have on hand? ${item.name}${item.count_unit === 'each' ? '' : `, counted in ${item.count_unit_label_plural}`}`}
          aria-describedby={resultId}
          data-testid="count-input"
          value={value === null ? '' : String(value)}
          onChange={(event) => onChange(item.id, parseEntry(digitsOnly(event.target.value)))}
          onKeyDown={onKeyDown}
          onFocus={(event) => { event.currentTarget.select(); onFocusItem(item.id, event.currentTarget); }}
          className={`h-12 w-[5.5rem] rounded-hub-control border-2 bg-hub-well px-2 text-center font-heading text-xl font-bold tabular-nums text-hub-ink ${
            entered ? 'border-hub-blue bg-hub-blue-tint' : 'border-dashed border-hub-n-400'}`}
        />
        <span className="min-h-4 text-[13px] leading-4 text-hub-n-600 sm:w-12" aria-hidden="true">{unit}</span>
      </div>
    </li>
  );
}

export default memo(CountRow);
