import { ShieldCheck } from 'lucide-react';
import type { ChecklistFieldValue, Compartment, EquipmentExtra, EquipmentNumbers } from '../types';
import { numbersProgress } from '../utils/equipmentNumbers.ts';

interface Props {
  block: EquipmentNumbers;
  fields: Array<{ id: string }>;
  fieldValues: Array<{ id: string; value: ChecklistFieldValue }>;
  compartments: Compartment[];
  extras: EquipmentExtra[];
  onOpen: () => void;
}

/**
 * The SCBA and radio numbers on the first screen, so no member has to go looking for them: how many are in, and one tap
 * to the boxes. Members follow the next-step buttons, which skip any step with nothing required.
 */
export default function EquipmentNumbersPrompt({ block, fields, fieldValues, compartments, extras, onOpen }: Props) {
  const { entered, total } = numbersProgress(block, fields, compartments, fieldValues, extras);
  if (total === 0) return null;
  const complete = entered >= total;

  return (
    <section data-hub-ui="2" className={`hub-well mb-4 flex flex-wrap items-center justify-between gap-3 p-3 ${complete ? '' : 'border-l-4 border-hub-warning'}`} aria-labelledby="equipment-numbers-prompt-heading">
      <div className="flex min-w-0 items-center gap-3">
        <ShieldCheck size={22} aria-hidden="true" className={complete ? 'text-hub-success' : 'text-hub-warning'} />
        <div>
          <h2 id="equipment-numbers-prompt-heading" className="text-base font-semibold text-hub-ink">SCBA &amp; Radio Numbers</h2>
          <p className="text-sm tabular-nums text-hub-n-600">{entered} of {total} entered{complete ? '' : ' · Check each pack and radio on the rig'}</p>
        </div>
      </div>
      <button type="button" className={complete ? 'hub-btn hub-btn--secondary' : 'hub-btn hub-btn--primary'} onClick={onOpen}>Enter SCBA &amp; radio numbers</button>
    </section>
  );
}
