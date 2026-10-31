import { useId } from 'react';
import { ChevronDown, Minus, Plus } from 'lucide-react';
import Tag from '../ui/Tag';
import type { Tone } from '../ui/status';
import { LINE_NOTE_MAX, isAttentionLine, lineStateLabel } from './changeoutLogic';
import type { ChangeoutCondition, ChangeoutHandling, EquipmentLine, LineChoice } from './types';

const CHOICE = 'hub-well flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-sm font-medium text-hub-ink';
const CHOICE_INPUT = 'h-5 w-5 flex-none border-hub-control-border text-hub-blue focus:ring-hub-focus';
const STEPPER = 'hub-btn hub-btn--secondary !min-h-11 !w-11 !min-w-11 !p-0';

const HANDLING: Array<[ChangeoutHandling, string]> = [['transfer', 'Transfer'], ['leave', 'Leave on truck'], ['review', 'Not sure']];
const CONDITIONS: Array<[ChangeoutCondition, string]> = [['ok', 'OK'], ['missing', 'Missing'], ['damaged', 'Damaged']];

const stateTone = (choice: LineChoice): Tone => {
  if (choice.condition !== 'ok') return 'warning';
  return choice.handling === 'review' ? 'warning' : 'info';
};

interface EditorProps {
  line: EquipmentLine;
  choice: LineChoice;
  fromVehicle: string | null;
  onChange: (choice: LineChoice) => void;
  onClose: () => void;
}

function LineEditor({ line, choice, fromVehicle, onChange, onClose }: EditorProps) {
  const id = useId();
  const expected = line.expected_quantity;
  const showStepper = expected !== null && choice.handling === 'transfer' && choice.condition === 'ok';
  const moving = choice.quantity ?? expected ?? 0;

  return (
    <div data-testid="changeout-line-editor" className="space-y-4 border-t border-hub-n-100 p-3">
      <div role="radiogroup" aria-labelledby={`${id}-handling`} className="grid gap-2">
        <p id={`${id}-handling`} className="text-sm font-semibold text-hub-ink">What happens to it?</p>
        {HANDLING.map(([value, label]) => (
          <label key={value} className={CHOICE}>
            <input type="radio" name={`${id}-handling-choice`} checked={choice.handling === value} onChange={() => onChange({ ...choice, handling: value })} className={CHOICE_INPUT} />
            {label}
          </label>
        ))}
      </div>

      {showStepper && (
        <div role="group" aria-labelledby={`${id}-quantity`}>
          <p id={`${id}-quantity`} className="text-sm font-semibold text-hub-ink">How many are moving?</p>
          <div className="mt-2 flex items-center gap-3">
            <button type="button" aria-label="One less" disabled={moving <= 1} onClick={() => onChange({ ...choice, quantity: moving - 1 })} className={STEPPER}>
              <Minus className="h-5 w-5" aria-hidden="true" />
            </button>
            <output aria-live="polite" className="min-w-16 text-center font-semibold tabular-nums text-hub-ink">{moving} of {expected}</output>
            <button type="button" aria-label="One more" disabled={moving >= expected} onClick={() => onChange({ ...choice, quantity: moving + 1 })} className={STEPPER}>
              <Plus className="h-5 w-5" aria-hidden="true" />
            </button>
          </div>
        </div>
      )}

      <div role="radiogroup" aria-labelledby={`${id}-condition`} className="grid gap-2">
        <p id={`${id}-condition`} className="text-sm font-semibold text-hub-ink">Condition</p>
        <div className="grid grid-cols-1 gap-2 min-[360px]:grid-cols-3">
          {CONDITIONS.map(([value, label]) => (
            <label key={value} className={CHOICE}>
              <input type="radio" name={`${id}-condition-choice`} checked={choice.condition === value} onChange={() => onChange({ ...choice, condition: value })} className={CHOICE_INPUT} />
              {label}
            </label>
          ))}
        </div>
        {choice.condition !== 'ok' && (
          <p className="text-sm text-hub-n-600">It stays on truck {fromVehicle ?? 'not recorded'}, and this opens an equipment issue there.</p>
        )}
      </div>

      <div>
        <label htmlFor={`${id}-note`} className="text-sm font-semibold text-hub-ink">Short note (optional)</label>
        <input
          id={`${id}-note`}
          type="text"
          value={choice.note}
          maxLength={LINE_NOTE_MAX}
          autoComplete="off"
          onChange={(event) => onChange({ ...choice, note: event.target.value })}
          className="mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 text-hub-ink"
        />
      </div>

      <button type="button" onClick={onClose} className="hub-btn hub-btn--secondary !min-h-11 w-full sm:w-auto">Done with this item</button>
    </div>
  );
}

interface RowProps {
  rowId: string;
  line: EquipmentLine;
  choice: LineChoice;
  exceptions: Record<string, LineChoice>;
  open: boolean;
  fromVehicle: string | null;
  onToggle: () => void;
  onChange: (choice: LineChoice) => void;
  onClose: () => void;
}

/** One equipment line. Controls open in place under it, so nothing moves while the member is editing. */
export default function EquipmentLineRow({ rowId, line, choice, exceptions, open, fromVehicle, onToggle, onChange, onClose }: RowProps) {
  const changed = isAttentionLine(line, exceptions);

  return (
    <li>
      <button
        id={rowId}
        type="button"
        aria-expanded={open}
        onClick={onToggle}
        className="flex min-h-11 w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-hub-n-50"
      >
        <span className="min-w-0">
          <span className="block font-medium text-hub-ink">{line.name}</span>
          {line.expected_quantity !== null && <span className="block text-[13px] leading-5 tabular-nums text-hub-n-600">Expected {line.expected_quantity}</span>}
          {line.previous?.note && <span className="block text-[13px] leading-5 text-hub-n-600">Last time: {line.previous.note}</span>}
          {choice.note.trim() !== '' && choice.note !== line.previous?.note && <span className="block text-[13px] leading-5 text-hub-n-800">Note: {choice.note}</span>}
        </span>
        <span className="flex flex-none items-center gap-2">
          {changed && <Tag tone={stateTone(choice)}>{lineStateLabel(line, choice)}</Tag>}
          <ChevronDown className={`h-5 w-5 text-hub-n-500 ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
        </span>
      </button>
      {open && <LineEditor line={line} choice={choice} fromVehicle={fromVehicle} onChange={onChange} onClose={onClose} />}
    </li>
  );
}
