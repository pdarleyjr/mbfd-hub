import { Search, X } from 'lucide-react';
import { FIELD } from '../shared';

interface Props {
  value: string;
  onChange: (value: string) => void;
  /** Spoken to screen readers when the results change; empty while not searching. */
  resultNote: string;
  id?: string;
  label?: string;
}

/** One box that searches every shelf at once. The page filters as you type; nothing is sent to the server. */
export default function InventorySearch({ value, onChange, resultNote, id = 'master-search', label = 'Search all shelves' }: Props) {
  return (
    <div role="search" className="min-w-0">
      <label htmlFor={id} className="sr-only">{label}</label>
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-hub-n-500" aria-hidden="true" />
        <input id={id} type="search" inputMode="search" autoComplete="off" autoCorrect="off" spellCheck={false} maxLength={80} value={value}
          placeholder={label} data-testid="master-search"
          className={`${FIELD} pl-9 ${value !== '' ? 'pr-12' : ''} [&::-webkit-search-cancel-button]:hidden`}
          onChange={(event) => onChange(event.target.value)} />
        {value !== '' && (
          <button type="button" aria-label="Clear search" data-testid="master-search-clear"
            className="absolute right-0 top-1/2 inline-flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-hub-control text-hub-n-600"
            onClick={() => onChange('')}>
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        )}
      </div>
      <p className="sr-only" role="status" aria-live="polite">{resultNote}</p>
    </div>
  );
}
