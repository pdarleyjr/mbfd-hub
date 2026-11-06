import { FIELD, LABEL } from '../shared';
import { ALL_FAMILIES } from './families';
import type { FamilyOption } from './types';

interface Props {
  options: readonly FamilyOption[];
  value: string;
  onChange: (family: string) => void;
}

/** Which of the four forms to list. A drop-down keeps the row small on a phone; it combines with Active/Archived and the search. */
export default function FamilyFilter({ options, value, onChange }: Props) {
  return (
    <div className="min-w-[12rem]">
      <label className={LABEL} htmlFor="ems-forms-family">Form</label>
      <select id="ems-forms-family" data-testid="ems-forms-family" className={FIELD} value={value} onChange={(event) => onChange(event.target.value)}>
        <option value={ALL_FAMILIES}>All forms</option>
        {options.map((option) => <option key={option.key} value={option.key}>{option.short_title}</option>)}
      </select>
    </div>
  );
}
