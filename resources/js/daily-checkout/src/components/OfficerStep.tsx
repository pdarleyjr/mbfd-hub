import type { OfficerInfo, Shift } from '../types';

interface Props {
  initialData: OfficerInfo;
  onSubmit: (data: OfficerInfo) => void;
  onChange: (data: OfficerInfo) => void;
}

export default function OfficerStep({ initialData, onSubmit, onChange }: Props) {
  return <section className="mx-auto max-w-lg rounded-lg border border-slate-200 bg-white p-5">
    <h2 className="mb-5 text-xl font-semibold">Member / Vehicle Info</h2>
    <form onSubmit={event => { event.preventDefault(); const data = new FormData(event.currentTarget); onSubmit({ ...initialData, shift: data.get('shift') as Shift }); }}>
      <div className="grid gap-4">
        <label className="text-sm font-semibold">Member<input className="mt-1 block w-full rounded border border-slate-200 bg-slate-50 p-3 font-normal" value={initialData.name} readOnly /></label>
        <label className="text-sm font-semibold">Rank<input className="mt-1 block w-full rounded border border-slate-200 bg-slate-50 p-3 font-normal" value={initialData.rank} readOnly /></label>
        <label className="text-sm font-semibold">Physical vehicle<input className="mt-1 block w-full rounded border border-slate-200 bg-slate-50 p-3 font-normal" value={initialData.unitNumber || 'Not recorded'} readOnly /></label>
        <label className="text-sm font-semibold">Shift<select name="shift" required value={initialData.shift} onChange={event => onChange({ ...initialData, shift: event.target.value as Shift })} className="mt-1 block w-full rounded border border-slate-300 p-3 font-normal"><option value="">Choose shift</option>{(['A', 'B', 'C'] as const).map(shift => <option key={shift} value={shift}>Shift {shift}</option>)}</select></label>
      </div>
      <p className="my-4 text-sm text-slate-600">Recorded under your signed-in member account.</p>
      <button type="submit" className="w-full rounded bg-slate-800 px-4 py-3 font-semibold text-white">Continue to Inspection</button>
    </form>
  </section>;
}
