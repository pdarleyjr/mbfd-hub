import { useState, useRef, useCallback } from 'react';
import { Link } from 'react-router-dom';
import SignatureCanvas from 'react-signature-canvas';
import { enqueueSubmission, processPendingSubmissions } from '../../lib/sync';

const STATIONS = [
  'Station 1',
  'Station 2',
  'Station 3',
  'Station 4',
  'Station 6',
];

const REASON_CODES = ['Damaged/Broken', 'Lost', 'Stolen', 'Needed'] as const;
type ReasonCode = typeof REASON_CODES[number];

interface RequestItem {
  id: string;
  description: string;
  quantity: number;
  reason: ReasonCode | '';
  pdCaseNumber: string;
  photoFile: File | null;
  photoPreview: string;
}

interface FormData {
  station: string;
  date: string;
  requestedBy: string;
  items: RequestItem[];
  explanation: string;
  memberSignature: string;
  officerSignature: string;
}

function createItem(): RequestItem {
  return {
    id: crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
    description: '',
    quantity: 1,
    reason: '',
    pdCaseNumber: '',
    photoFile: null,
    photoPreview: '',
  };
}

export default function EquipmentRequestWizard() {
  const [step, setStep] = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const memberSigRef = useRef<SignatureCanvas | null>(null);
  const officerSigRef = useRef<SignatureCanvas | null>(null);

  const [form, setForm] = useState<FormData>({
    station: '',
    date: new Date().toISOString().split('T')[0],
    requestedBy: '',
    items: [createItem()],
    explanation: '',
    memberSignature: '',
    officerSignature: '',
  });

  const update = useCallback((patch: Partial<FormData>) => {
    setForm((prev) => ({ ...prev, ...patch }));
  }, []);

  const updateItem = (id: string, patch: Partial<RequestItem>) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.map((item) => item.id === id ? { ...item, ...patch } : item),
    }));
  };

  const addItem = () => {
    setForm((prev) => ({ ...prev, items: [...prev.items, createItem()] }));
  };

  const removeItem = (id: string) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.length > 1 ? prev.items.filter((i) => i.id !== id) : prev.items,
    }));
  };

  const handlePhotoChange = (itemId: string, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      updateItem(itemId, { photoFile: file, photoPreview: reader.result as string });
    };
    reader.readAsDataURL(file);
  };

  const canNext = (): boolean => {
    if (step === 1) return !!form.station && !!form.date && !!form.requestedBy;
    if (step === 2) {
      return form.items.every((item) =>
        item.description.length > 0 &&
        item.quantity > 0 &&
        !!item.reason &&
        (item.reason !== 'Stolen' || item.pdCaseNumber.length > 0)
      );
    }
    if (step === 3) return form.explanation.length > 0;
    if (step === 4) return !!form.memberSignature && !!form.officerSignature;
    return true;
  };

  const handleSubmit = async () => {
    setSubmitting(true);
    try {
      // Build items payload — photos as base64 for offline queue
      const itemsPayload = form.items.map(({ description, quantity, reason, pdCaseNumber, photoPreview }) => ({
        description,
        quantity,
        reason,
        pd_case_number: pdCaseNumber || null,
        photo: photoPreview || null,
      }));

      await enqueueSubmission('fire_equipment_request', {
        station: form.station,
        date: form.date,
        requested_by: form.requestedBy,
        items: itemsPayload,
        explanation: form.explanation,
        member_signature: form.memberSignature,
        officer_signature: form.officerSignature,
        submitted_at: new Date().toISOString(),
      });
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

  const stepLabels = ['Info', 'Items', 'Explanation', 'Signatures', 'Review'];

  return (
    <div className="max-w-2xl mx-auto">
      {/* Header */}
      <div className="mb-8">
        <Link to="/forms-hub" className="inline-flex items-center text-neutral-500 hover:text-neutral-700 mb-4 min-h-[44px]">
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
          Back
        </Link>
        <h1 className="text-2xl font-bold text-neutral-800 font-heading">Fire Equipment Request</h1>
        <p className="text-sm text-neutral-500 mt-1">Miami Beach Fire Department — Equipment Replacement Form</p>
      </div>

      {/* Stepper */}
      <nav className="flex items-center gap-2 mb-8 overflow-x-auto" aria-label="Progress">
        {stepLabels.map((label, i) => (
          <div key={label} className="flex items-center gap-2 flex-shrink-0">
            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium transition-colors ${i + 1 <= step ? 'bg-red-600 text-white' : 'bg-neutral-200 text-neutral-500'}`}>
              {i + 1}
            </div>
            <span className="text-sm text-neutral-600 hidden sm:inline">{label}</span>
            {i < stepLabels.length - 1 && <div className="w-6 h-px bg-neutral-300" />}
          </div>
        ))}
      </nav>

      {/* Step 1: Info */}
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
            <label className="block text-sm font-medium text-neutral-700 mb-2">Date</label>
            <input type="date" value={form.date} onChange={(e) => update({ date: e.target.value })} className="w-full min-h-[44px] px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent" />
          </div>
          <div>
            <label className="block text-sm font-medium text-neutral-700 mb-2">Requested By (Name)</label>
            <input type="text" value={form.requestedBy} onChange={(e) => update({ requestedBy: e.target.value })} placeholder="Enter your name" className="w-full min-h-[44px] px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent" />
          </div>
        </div>
      )}

      {/* Step 2: Items */}
      {step === 2 && (
        <div className="space-y-6">
          <p className="text-sm text-neutral-600">Add each item that needs to be replaced.</p>
          {form.items.map((item, idx) => (
            <div key={item.id} className="bg-neutral-50 rounded-xl ring-1 ring-neutral-200/60 p-4 space-y-4">
              <div className="flex items-center justify-between">
                <span className="text-sm font-semibold text-neutral-700">Item #{idx + 1}</span>
                {form.items.length > 1 && (
                  <button onClick={() => removeItem(item.id)} className="min-h-[36px] px-3 py-1 text-xs text-red-600 hover:text-red-700 font-medium">
                    Remove
                  </button>
                )}
              </div>
              <div>
                <label className="block text-xs font-medium text-neutral-600 mb-1">Item Description</label>
                <input type="text" value={item.description} onChange={(e) => updateItem(item.id, { description: e.target.value })} placeholder="e.g., Nomex Hood, Size L" className="w-full min-h-[44px] px-3 py-2 bg-white border border-neutral-300 rounded-lg text-sm text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent" />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-neutral-600 mb-1">Qty</label>
                  <input type="number" min={1} value={item.quantity} onChange={(e) => updateItem(item.id, { quantity: parseInt(e.target.value) || 1 })} className="w-full min-h-[44px] px-3 py-2 bg-white border border-neutral-300 rounded-lg text-sm text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent" />
                </div>
                <div>
                  <label className="block text-xs font-medium text-neutral-600 mb-1">Reason</label>
                  <select value={item.reason} onChange={(e) => updateItem(item.id, { reason: e.target.value as ReasonCode, pdCaseNumber: '', photoFile: null, photoPreview: '' })} className="w-full min-h-[44px] px-3 py-2 bg-white border border-neutral-300 rounded-lg text-sm text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    <option value="">Select...</option>
                    {REASON_CODES.map((r) => <option key={r} value={r}>{r}</option>)}
                  </select>
                </div>
              </div>

              {/* Conditional: Stolen → PD Case No */}
              {item.reason === 'Stolen' && (
                <div>
                  <label className="block text-xs font-medium text-red-700 mb-1">PD Case No. (Required)</label>
                  <input type="text" value={item.pdCaseNumber} onChange={(e) => updateItem(item.id, { pdCaseNumber: e.target.value })} placeholder="Police report case number" className="w-full min-h-[44px] px-3 py-2 bg-white border border-red-300 rounded-lg text-sm text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent" />
                </div>
              )}

              {/* Conditional: Damaged/Broken → Photo Upload */}
              {item.reason === 'Damaged/Broken' && (
                <div>
                  <label className="block text-xs font-medium text-amber-700 mb-1">Photo of Damage (recommended)</label>
                  <input type="file" accept="image/*" capture="environment" onChange={(e) => handlePhotoChange(item.id, e)} className="w-full text-sm text-neutral-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-red-50 file:text-red-700 hover:file:bg-red-100" />
                  {item.photoPreview && (
                    <img src={item.photoPreview} alt="Damage preview" className="mt-2 h-24 rounded-lg border border-neutral-200 object-cover" />
                  )}
                </div>
              )}
            </div>
          ))}
          <button onClick={addItem} className="w-full min-h-[44px] px-4 py-3 border-2 border-dashed border-neutral-300 rounded-xl text-sm font-medium text-neutral-600 hover:border-neutral-400 hover:text-neutral-700 transition-colors">
            + Add Another Item
          </button>
        </div>
      )}

      {/* Step 3: Explanation */}
      {step === 3 && (
        <div className="space-y-6">
          <div>
            <label className="block text-sm font-medium text-neutral-700 mb-2">Explanation</label>
            <p className="text-xs text-neutral-500 mb-2">Describe the circumstances for the replacement request.</p>
            <textarea value={form.explanation} onChange={(e) => update({ explanation: e.target.value })} rows={5} placeholder="Provide details about what happened..." className="w-full px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none" />
          </div>
        </div>
      )}

      {/* Step 4: Signatures */}
      {step === 4 && (
        <div className="space-y-8">
          {/* Member Signature */}
          <div className="space-y-3">
            <p className="text-sm font-medium text-neutral-700">Member Signature</p>
            <div className="border-2 border-dashed border-neutral-300 rounded-xl bg-white overflow-hidden">
              <SignatureCanvas ref={memberSigRef} penColor="#1a1a1a" canvasProps={{ className: 'w-full', style: { height: 160, width: '100%' } }} onEnd={() => {
                if (memberSigRef.current && !memberSigRef.current.isEmpty()) {
                  update({ memberSignature: memberSigRef.current.toDataURL('image/png') });
                }
              }} />
            </div>
            <button onClick={() => { memberSigRef.current?.clear(); update({ memberSignature: '' }); }} className="min-h-[36px] px-3 py-1 text-xs text-neutral-500 hover:text-neutral-700 underline">Clear</button>
          </div>

          {/* Company Officer Signature */}
          <div className="space-y-3">
            <p className="text-sm font-medium text-neutral-700">Company Officer Signature</p>
            <div className="border-2 border-dashed border-neutral-300 rounded-xl bg-white overflow-hidden">
              <SignatureCanvas ref={officerSigRef} penColor="#1a1a1a" canvasProps={{ className: 'w-full', style: { height: 160, width: '100%' } }} onEnd={() => {
                if (officerSigRef.current && !officerSigRef.current.isEmpty()) {
                  update({ officerSignature: officerSigRef.current.toDataURL('image/png') });
                }
              }} />
            </div>
            <button onClick={() => { officerSigRef.current?.clear(); update({ officerSignature: '' }); }} className="min-h-[36px] px-3 py-1 text-xs text-neutral-500 hover:text-neutral-700 underline">Clear</button>
          </div>
        </div>
      )}

      {/* Step 5: Review */}
      {step === 5 && (
        <div className="space-y-6">
          <div className="bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6 space-y-4">
            <div><span className="text-sm text-neutral-500">Station</span><p className="font-medium text-neutral-800">{form.station}</p></div>
            <div><span className="text-sm text-neutral-500">Date</span><p className="font-medium text-neutral-800">{form.date}</p></div>
            <div><span className="text-sm text-neutral-500">Requested By</span><p className="font-medium text-neutral-800">{form.requestedBy}</p></div>
            <div>
              <span className="text-sm text-neutral-500">Items ({form.items.length})</span>
              <div className="mt-2 space-y-2">
                {form.items.map((item, idx) => (
                  <div key={item.id} className="bg-white rounded-lg p-3 ring-1 ring-neutral-200/60 text-sm">
                    <p className="font-medium text-neutral-800">{idx + 1}. {item.description} × {item.quantity}</p>
                    <p className="text-neutral-500">Reason: {item.reason}</p>
                    {item.pdCaseNumber && <p className="text-red-700">PD Case No: {item.pdCaseNumber}</p>}
                    {item.photoPreview && <img src={item.photoPreview} alt="Damage" className="mt-1 h-16 rounded border border-neutral-200 object-cover" />}
                  </div>
                ))}
              </div>
            </div>
            <div><span className="text-sm text-neutral-500">Explanation</span><p className="text-neutral-800">{form.explanation}</p></div>
            {form.memberSignature && <div><span className="text-sm text-neutral-500">Member Signature</span><img src={form.memberSignature} alt="Member Signature" className="mt-2 h-14 border border-neutral-200 rounded bg-white" /></div>}
            {form.officerSignature && <div><span className="text-sm text-neutral-500">Company Officer Signature</span><img src={form.officerSignature} alt="Officer Signature" className="mt-2 h-14 border border-neutral-200 rounded bg-white" /></div>}
          </div>
        </div>
      )}

      {/* Navigation */}
      <div className="flex justify-between mt-8">
        <button onClick={() => setStep((s) => s - 1)} disabled={step === 1} className="min-h-[44px] px-6 py-3 text-neutral-600 hover:text-neutral-800 disabled:opacity-30 disabled:cursor-not-allowed">
          Previous
        </button>
        {step < 5 ? (
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
