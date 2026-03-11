import { useState, useRef, useCallback } from 'react';
import { Link } from 'react-router-dom';
import SignatureCanvas from 'react-signature-canvas';
import { enqueueSubmission, processPendingSubmissions } from '../../lib/sync';

const STATIONS = [
  'Station 1 - Headquarters',
  'Station 2 - Riverside',
  'Station 3 - Palm Beach',
  'Station 4 - Northside',
  'Station 5 - Southgate',
];

const EQUIPMENT_TYPES = [
  'SCBA / Breathing Apparatus',
  'Hose / Nozzle',
  'PPE / Turnout Gear',
  'Tools / Hand Tools',
  'Ladders',
  'Communications / Radio',
  'Medical Equipment',
  'Other',
];

type Priority = 'low' | 'medium' | 'high' | 'critical';

interface FormData {
  station: string;
  equipmentType: string;
  description: string;
  priority: Priority;
  signature: string;
}

const PRIORITY_CONFIG: Record<Priority, { label: string; color: string; bg: string; ring: string; icon: string }> = {
  low: { label: 'Low', color: 'text-emerald-700', bg: 'bg-emerald-50', ring: 'ring-emerald-300', icon: '🟢' },
  medium: { label: 'Medium', color: 'text-amber-700', bg: 'bg-amber-50', ring: 'ring-amber-300', icon: '🟡' },
  high: { label: 'High', color: 'text-orange-700', bg: 'bg-orange-50', ring: 'ring-orange-300', icon: '🟠' },
  critical: { label: 'Critical', color: 'text-red-700', bg: 'bg-red-50', ring: 'ring-red-300', icon: '🔴' },
};

export default function EquipmentRequestWizard() {
  const [step, setStep] = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const sigRef = useRef<SignatureCanvas | null>(null);

  const [form, setForm] = useState<FormData>({
    station: '',
    equipmentType: '',
    description: '',
    priority: 'medium',
    signature: '',
  });

  const update = useCallback((patch: Partial<FormData>) => {
    setForm((prev) => ({ ...prev, ...patch }));
  }, []);

  const canNext = (): boolean => {
    if (step === 1) return !!form.station && !!form.equipmentType && form.description.length > 5;
    if (step === 2) return !!form.priority;
    if (step === 3) return !!form.signature;
    return true;
  };

  const handleClearSig = () => {
    sigRef.current?.clear();
    update({ signature: '' });
  };

  const handleSaveSig = () => {
    if (sigRef.current && !sigRef.current.isEmpty()) {
      update({ signature: sigRef.current.toDataURL('image/png') });
    }
  };

  const handleSubmit = async () => {
    setSubmitting(true);
    try {
      await enqueueSubmission('fire_equipment_request', {
        station: form.station,
        equipment_type: form.equipmentType,
        description: form.description,
        priority: form.priority,
        signature: form.signature,
        submitted_at: new Date().toISOString(),
      });
      // Try to sync immediately
      processPendingSubmissions('/api/admin').catch(() => {});
      setSubmitted(true);
    } catch {
      alert('Failed to save. Your request will be retried automatically.');
    } finally {
      setSubmitting(false);
    }
  };

  if (submitted) {
    return (
      <div className="text-center py-16 space-y-6">
        <div className="w-20 h-20 mx-auto bg-emerald-50 rounded-full flex items-center justify-center">
          <svg className="w-10 h-10 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 className="text-2xl font-bold text-neutral-800 font-heading">Request Submitted</h2>
        <p className="text-neutral-500 max-w-md mx-auto">Your fire equipment request has been queued and will sync when online.</p>
        <Link to="/forms-hub" className="inline-flex items-center min-h-[44px] px-6 py-3 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors">
          Back to Forms Hub
        </Link>
      </div>
    );
  }

  const stepLabels = ['Details', 'Priority', 'Signature', 'Review'];

  return (
    <div className="max-w-2xl mx-auto">
      {/* Header */}
      <div className="mb-8">
        <Link to="/forms-hub" className="inline-flex items-center text-neutral-500 hover:text-neutral-700 mb-4 min-h-[44px]">
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
          Back
        </Link>
        <h1 className="text-2xl font-bold text-neutral-800 font-heading">Fire Equipment Request</h1>
      </div>

      {/* Stepper */}
      <nav className="flex items-center gap-2 mb-8" aria-label="Progress">
        {stepLabels.map((label, i) => (
          <div key={label} className="flex items-center gap-2">
            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium transition-colors ${i + 1 <= step ? 'bg-red-600 text-white' : 'bg-neutral-200 text-neutral-500'}`}>
              {i + 1}
            </div>
            <span className="text-sm text-neutral-600 hidden sm:inline">{label}</span>
            {i < stepLabels.length - 1 && <div className="w-8 h-px bg-neutral-300" />}
          </div>
        ))}
      </nav>

      {/* Step 1: Details */}
      {step === 1 && (
        <div className="space-y-6">
          <div>
            <label className="block text-sm font-medium text-neutral-700 mb-2">Station</label>
            <select value={form.station} onChange={(e) => update({ station: e.target.value })} className="w-full min-h-[44px] px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
              <option value="">Select station...</option>
              {STATIONS.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-neutral-700 mb-2">Equipment Type</label>
            <select value={form.equipmentType} onChange={(e) => update({ equipmentType: e.target.value })} className="w-full min-h-[44px] px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
              <option value="">Select type...</option>
              {EQUIPMENT_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-neutral-700 mb-2">Description</label>
            <textarea value={form.description} onChange={(e) => update({ description: e.target.value })} rows={4} placeholder="Describe the equipment needed and reason..." className="w-full px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none" />
          </div>
        </div>
      )}

      {/* Step 2: Priority */}
      {step === 2 && (
        <div className="space-y-4">
          <p className="text-neutral-600 mb-4">Select request priority:</p>
          <div className="grid grid-cols-2 gap-4">
            {(Object.entries(PRIORITY_CONFIG) as [Priority, typeof PRIORITY_CONFIG[Priority]][]).map(([key, cfg]) => (
              <button key={key} onClick={() => update({ priority: key })} className={`min-h-[44px] p-4 rounded-xl ring-1 transition-all text-left ${form.priority === key ? `${cfg.bg} ${cfg.ring} ring-2 shadow-sm` : 'bg-neutral-100 ring-neutral-200 hover:ring-neutral-300'}`}>
                <span className="text-2xl">{cfg.icon}</span>
                <div className={`text-lg font-semibold mt-2 ${form.priority === key ? cfg.color : 'text-neutral-700'}`}>{cfg.label}</div>
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Step 3: Signature */}
      {step === 3 && (
        <div className="space-y-4">
          <p className="text-neutral-600">Sign below to authorize this request:</p>
          <div className="border-2 border-dashed border-neutral-300 rounded-xl bg-white overflow-hidden">
            <SignatureCanvas ref={sigRef} penColor="#1a1a1a" canvasProps={{ className: 'w-full', style: { height: 200, width: '100%' } }} onEnd={handleSaveSig} />
          </div>
          <button onClick={handleClearSig} className="min-h-[44px] px-4 py-2 text-sm text-neutral-500 hover:text-neutral-700 underline">Clear Signature</button>
        </div>
      )}

      {/* Step 4: Review */}
      {step === 4 && (
        <div className="space-y-6">
          <div className="bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6 space-y-4">
            <div><span className="text-sm text-neutral-500">Station</span><p className="font-medium text-neutral-800">{form.station}</p></div>
            <div><span className="text-sm text-neutral-500">Equipment Type</span><p className="font-medium text-neutral-800">{form.equipmentType}</p></div>
            <div><span className="text-sm text-neutral-500">Description</span><p className="font-medium text-neutral-800">{form.description}</p></div>
            <div><span className="text-sm text-neutral-500">Priority</span><p className={`font-medium ${PRIORITY_CONFIG[form.priority].color}`}>{PRIORITY_CONFIG[form.priority].icon} {PRIORITY_CONFIG[form.priority].label}</p></div>
            {form.signature && (
              <div><span className="text-sm text-neutral-500">Signature</span><img src={form.signature} alt="Signature" className="mt-2 h-16 border border-neutral-200 rounded bg-white" /></div>
            )}
          </div>
        </div>
      )}

      {/* Navigation */}
      <div className="flex justify-between mt-8">
        <button onClick={() => setStep((s) => s - 1)} disabled={step === 1} className="min-h-[44px] px-6 py-3 text-neutral-600 hover:text-neutral-800 disabled:opacity-30 disabled:cursor-not-allowed">
          Previous
        </button>
        {step < 4 ? (
          <button onClick={() => setStep((s) => s + 1)} disabled={!canNext()} className="min-h-[44px] px-6 py-3 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            Next
          </button>
        ) : (
          <button onClick={handleSubmit} disabled={submitting} className="min-h-[44px] px-8 py-3 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition-colors disabled:opacity-50">
            {submitting ? 'Submitting...' : 'Submit Request'}
          </button>
        )}
      </div>
    </div>
  );
}
