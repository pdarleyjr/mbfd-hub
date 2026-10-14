import type { OfficerInfo, Shift } from '../types';

interface Props {
  initialData: OfficerInfo;
  onSubmit: (data: OfficerInfo) => void;
  onChange: (data: OfficerInfo) => void;
  continueLabel: string;
}

const READ_ONLY = 'mt-1 block min-h-11 w-full rounded-hub-control border border-hub-glass-border bg-hub-n-50 px-3 py-2.5 font-normal text-hub-ink';

export default function OfficerStep({ initialData, onSubmit, onChange, continueLabel }: Props) {
  return <section data-hub-ui="2" className="inspection-panel mx-auto max-w-lg p-4 sm:p-5">
    <h2 className="mb-5 text-xl font-semibold">Member / Vehicle Info</h2>
    <form onSubmit={event => { event.preventDefault(); const data = new FormData(event.currentTarget); onSubmit({ ...initialData, shift: data.get('shift') as Shift }); }}>
      <div className="grid gap-4">
        <label className="text-sm font-semibold">Member<input className={READ_ONLY} value={initialData.name} readOnly /></label>
        <label className="text-sm font-semibold">Rank<input className={READ_ONLY} value={initialData.rank} readOnly /></label>
        <label className="text-sm font-semibold">Physical vehicle<input className={`${READ_ONLY} tabular-nums`} value={initialData.unitNumber || 'Not recorded'} readOnly /></label>
        <label className="text-sm font-semibold">Shift<select name="shift" required value={initialData.shift} onChange={event => onChange({ ...initialData, shift: event.target.value as Shift })} className="mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 font-normal text-hub-ink"><option value="">Choose shift</option>{(['A', 'B', 'C'] as const).map(shift => <option key={shift} value={shift}>Shift {shift}</option>)}</select></label>
      </div>
      <p className="my-4 text-sm text-hub-n-600">Recorded under your signed-in member account.</p>
      <button type="submit" className="hub-btn hub-btn--primary hub-btn--wrap inspection-review-button w-full">{continueLabel}</button>
    </form>
  </section>;
}
