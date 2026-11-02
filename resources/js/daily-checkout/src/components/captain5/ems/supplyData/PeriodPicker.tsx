import { useId, useState } from 'react';
import { BTN_PRIMARY, ChoiceChips, FIELD, LABEL } from '../shared';
import { customRangeProblem, earliestStart, PERIOD_OPTIONS } from './params.ts';
import type { PeriodKey, PeriodSelection } from './types.ts';

interface Props {
  selection: PeriodSelection;
  /** Today in Eastern time (Y-m-d): the latest date a custom range may end on. */
  today: string;
  /** The dates the screen is showing now, offered as the starting point when the person picks Custom. */
  seed: { from: string; to: string } | null;
  onSelect: (selection: PeriodSelection) => void;
}

function CustomRange({ selection, today, onApply }: { selection: PeriodSelection; today: string; onApply: (selection: PeriodSelection) => void }) {
  const id = useId();
  const [from, setFrom] = useState(selection.from ?? '');
  const [to, setTo] = useState(selection.to ?? '');
  const [tried, setTried] = useState(false);
  const problem = customRangeProblem(from || null, to || null, today);
  const apply = () => {
    setTried(true);
    if (problem === null) onApply({ period: 'custom', from, to });
  };

  return (
    <form
      className="space-y-3 rounded-hub-control border border-hub-border bg-hub-well p-3"
      aria-label="Custom date range"
      noValidate
      onSubmit={(event) => { event.preventDefault(); apply(); }}
      data-testid="custom-range"
    >
      <div className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2">
        <div className="min-w-0">
          <label className={LABEL} htmlFor={`${id}-from`}>From</label>
          <input id={`${id}-from`} type="date" className={FIELD} value={from} min={earliestStart(to || today)} max={to || today} onChange={(event) => setFrom(event.target.value)} aria-describedby={tried && problem ? `${id}-problem` : undefined} aria-invalid={tried && problem ? true : undefined} />
        </div>
        <div className="min-w-0">
          <label className={LABEL} htmlFor={`${id}-to`}>To</label>
          <input id={`${id}-to`} type="date" className={FIELD} value={to} min={from || undefined} max={today} onChange={(event) => setTo(event.target.value)} aria-describedby={tried && problem ? `${id}-problem` : undefined} aria-invalid={tried && problem ? true : undefined} />
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <button type="submit" className={BTN_PRIMARY}>Show these dates</button>
        <p className="text-[13px] leading-5 text-hub-n-600">Up to 400 days, ending today at the latest.</p>
      </div>
      <p id={`${id}-problem`} role="alert" className="text-[13px] font-semibold text-hub-danger">{tried && problem ? problem : ''}</p>
    </form>
  );
}

/** Last 30 days / 90 days / 12 months / Custom. Custom shows two bounded date boxes and asks for them to be applied. */
export default function PeriodPicker({ selection, today, seed, onSelect }: Props) {
  const choose = (period: PeriodKey) => {
    if (period === selection.period) return;
    if (period !== 'custom') { onSelect({ period, from: null, to: null }); return; }
    onSelect({ period: 'custom', from: seed?.from ?? null, to: seed?.to ?? null });
  };

  return (
    <div className="space-y-3">
      <ChoiceChips<PeriodKey> label="Time period" testId="supply-data-period" options={PERIOD_OPTIONS} value={selection.period} onChange={choose} />
      {selection.period === 'custom' && <CustomRange key={`${selection.from}|${selection.to}`} selection={selection} today={today} onApply={onSelect} />}
    </div>
  );
}
