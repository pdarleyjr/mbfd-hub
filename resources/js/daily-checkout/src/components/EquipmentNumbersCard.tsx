import { useState, type Dispatch, type SetStateAction } from 'react';
import { Plus } from 'lucide-react';
import type { ChecklistFieldValue, Compartment, EquipmentExtra, EquipmentKind, EquipmentNumbers, EquipmentSlot } from '../types';
import { MAX_EXTRA_NUMBERS, MAX_NUMBER_LENGTH, boxesFor, joinNumbers, slotsOnChecklist } from '../utils/equipmentNumbers.ts';
import { createClientSubmissionId } from '../utils/storage';

interface Props {
  block: EquipmentNumbers;
  fields: Array<{ id: string }>;
  fieldValues: Array<{ id: string; value: ChecklistFieldValue }>;
  compartments: Compartment[];
  onFieldChange: (fieldId: string, value: string) => void;
  onItemChange: (compartmentId: string, itemId: string, value: string) => void;
  extras: EquipmentExtra[];
  onExtrasChange: Dispatch<SetStateAction<EquipmentExtra[]>>;
}

const INPUT = 'mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 font-normal tabular-nums text-hub-ink';
const LABEL = 'block text-sm font-semibold text-hub-ink';

const GROUPS: Array<{ kind: EquipmentKind; heading: string; add: string; added: string }> = [
  { kind: 'scba', heading: 'SCBA', add: 'Add another SCBA', added: 'Additional SCBA' },
  { kind: 'radio', heading: 'Radios', add: 'Add another radio', added: 'Additional radio' },
];

/**
 * The SCBA and radio numbers on this apparatus, on every Daily Checkout. They come filled in from the last inspection, so
 * a member only touches what changed. Each edit goes straight to the checklist answer it belongs to.
 */
export default function EquipmentNumbersCard({ block, fields, fieldValues, compartments, onFieldChange, onItemChange, extras, onExtrasChange }: Props) {
  const slots = slotsOnChecklist(block, fields, compartments);
  // A box holding several numbers is edited one number at a time. The parts live here so typing cannot reshuffle them.
  const [parts, setParts] = useState<Record<string, string[]>>({});
  const [focusId, setFocusId] = useState<string | null>(null);

  const valueOf = (slot: EquipmentSlot): string => {
    if (slot.field_id !== null) {
      const value = fieldValues.find(field => field.id === slot.field_id)?.value;

      return typeof value === 'string' ? value : typeof value === 'number' ? String(value) : '';
    }
    const value = compartments.find(compartment => compartment.id === slot.compartment_id)?.items.find(item => item.id === slot.item_id)?.value;

    return typeof value === 'string' ? value : typeof value === 'number' ? String(value) : '';
  };

  const write = (slot: EquipmentSlot, value: string) => {
    if (slot.field_id !== null) onFieldChange(slot.field_id, value);
    else if (slot.compartment_id !== null && slot.item_id !== null) onItemChange(slot.compartment_id, slot.item_id, value);
  };

  const add = (kind: EquipmentKind) => {
    const client_id = createClientSubmissionId();
    setFocusId(client_id);
    onExtrasChange(previous => previous.length >= MAX_EXTRA_NUMBERS ? previous : [...previous, { client_id, kind, label: null, number: '' }]);
  };

  if (slots.length === 0 && extras.length === 0) return null;

  return (
    <section data-hub-ui="2" data-equipment-numbers className="inspection-review-details" aria-labelledby="equipment-numbers-heading">
      <h3 id="equipment-numbers-heading" className="mb-1 font-semibold">SCBA &amp; Radio Numbers</h3>
      <p className="inspection-muted mb-3">Filled in from the last inspection of this apparatus. Change only what is different today.</p>
      {GROUPS.map((group) => {
        const groupSlots = slots.filter(slot => slot.kind === group.kind);
        const groupExtras = extras.filter(extra => extra.kind === group.kind);

        return (
          <div key={group.kind} className="mb-4" role="group" aria-label={group.heading} data-equipment-group={group.kind}>
            <h4 className="mb-2 text-sm font-bold uppercase tracking-wide text-hub-n-600">{group.heading}</h4>
            <div className="grid gap-3 sm:grid-cols-2">
              {groupSlots.flatMap((slot) => {
                const value = valueOf(slot);
                if (slot.boxes <= 1) {
                  return [<label key={slot.slot} className={LABEL}>{slot.label}
                    <input type="text" autoComplete="off" autoCorrect="off" spellCheck={false} maxLength={MAX_NUMBER_LENGTH} className={INPUT} value={value} onChange={event => write(slot, event.target.value)} />
                  </label>];
                }
                const shown = parts[slot.slot] ?? boxesFor(slot, value);

                return shown.map((part, index) => (
                  <label key={`${slot.slot}-${index}`} className={LABEL}>{slot.label} {index + 1}
                    <input
                      type="text"
                      autoComplete="off"
                      autoCorrect="off"
                      spellCheck={false}
                      maxLength={MAX_NUMBER_LENGTH}
                      className={INPUT}
                      value={part}
                      onChange={(event) => {
                        const next = shown.map((entry, position) => (position === index ? event.target.value : entry));
                        setParts(previous => ({ ...previous, [slot.slot]: next }));
                        write(slot, joinNumbers(next));
                      }}
                    />
                  </label>
                ));
              })}
              {groupExtras.map((extra, index) => (
                <div key={extra.client_id}>
                  <label className={LABEL}>{extra.label ?? `${group.added}${groupExtras.length > 1 ? ` ${index + 1}` : ''}`}
                    <input
                      type="text"
                      autoComplete="off"
                      autoCorrect="off"
                      spellCheck={false}
                      autoFocus={focusId === extra.client_id}
                      maxLength={MAX_NUMBER_LENGTH}
                      className={INPUT}
                      value={extra.number}
                      onChange={event => onExtrasChange(previous => previous.map(entry => entry.client_id === extra.client_id ? { ...entry, number: event.target.value } : entry))}
                    />
                  </label>
                  <button type="button" className="hub-btn hub-btn--tertiary mt-1" onClick={() => onExtrasChange(previous => previous.filter(entry => entry.client_id !== extra.client_id))}>
                    Remove {extra.label ?? group.added.toLowerCase()}
                  </button>
                </div>
              ))}
            </div>
            <button type="button" className="hub-btn hub-btn--secondary mt-3" disabled={extras.length >= MAX_EXTRA_NUMBERS} onClick={() => add(group.kind)}>
              <Plus size={18} aria-hidden="true" />{group.add}
            </button>
          </div>
        );
      })}
    </section>
  );
}
