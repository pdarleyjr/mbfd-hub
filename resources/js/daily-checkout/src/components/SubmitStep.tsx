import { useEffect, useRef, useState } from 'react';
import SignatureCanvas from 'react-signature-canvas';
import type { OfficerInfo, Compartment, ChecklistData, ChecklistFieldValue, MeterData } from '../types';

interface SubmitStepProps {
  officerInfo: OfficerInfo;
  compartments: Compartment[];
  checklist: ChecklistData;
  fieldValues: Array<{ id: string; value: ChecklistFieldValue }>;
  meters: MeterData;
  signature: string | null;
  onSignatureChange: (value: string | null) => void;
  onShiftChange: (value: OfficerInfo['shift']) => void;
  onSubmit: (signature: string | null) => void;
  onBack: () => void;
  submitting: boolean;
}

export default function SubmitStep({
  officerInfo,
  compartments,
  checklist,
  fieldValues,
  meters,
  signature,
  onSignatureChange,
  onShiftChange,
  onSubmit,
  onBack,
  submitting
}: SubmitStepProps) {
  const sigRef = useRef<SignatureCanvas | null>(null);
  const [sigError, setSigError] = useState(false);
  const [shiftError, setShiftError] = useState(false);
  useEffect(() => {
    const pad = sigRef.current;
    if (!pad) return;
    const canvas = pad.getCanvas();
    const restore = (value: string) => pad.fromDataURL(value, { width: canvas.clientWidth, height: canvas.clientHeight });
    if (signature) restore(signature);
    const observer = new ResizeObserver(() => {
      const ratio = Math.max(window.devicePixelRatio || 1, 1);
      const width = Math.round(canvas.clientWidth * ratio);
      const height = Math.round(canvas.clientHeight * ratio);
      if (!width || !height || (canvas.width === width && canvas.height === height)) return;
      const saved = pad.isEmpty() ? null : pad.toDataURL('image/png');
      canvas.width = width;
      canvas.height = height;
      canvas.getContext('2d')?.scale(ratio, ratio);
      pad.clear();
      if (saved) restore(saved);
    });
    observer.observe(canvas);
    return () => observer.disconnect();
  }, []);

  const totalItems = compartments.reduce((sum, comp) => sum + comp.items.length, 0);
  const issuesCount = compartments.reduce((sum, comp) =>
    sum + comp.items.filter(item => item.status !== 'Present').length, 0
  );

  const handleSubmit = () => {
    if (!officerInfo.shift) { setShiftError(true); return; }
    if (!signature) {
      setSigError(true);
      return;
    }
    setSigError(false);
    // react-signature-canvas delegates getTrimmedCanvas() to trim-canvas,
    // whose CommonJS default export is incompatible with the current Vite
    // runtime. The native SignaturePad data URL is sufficient for the signed
    // inspection record and preserves the real, drawn canvas content.
    onSubmit(signature);
  };

  const clearSignature = () => {
    sigRef.current?.clear();
    onSignatureChange(null);
    setSigError(false);
  };

  return (
    <div className="max-w-2xl mx-auto">
      <h2 className="text-xl font-semibold text-gray-900 mb-6 text-center">
        Review & Submit Inspection
      </h2>

      <div className="bg-gray-50 rounded-lg p-6 mb-6">
        <h3 className="text-lg font-medium text-gray-900 mb-4">Inspection Summary</h3>

        <div className="grid grid-cols-2 gap-4 mb-4">
          <div>
            <p className="text-sm text-gray-600">Member</p>
            <p className="font-medium">{officerInfo.name}</p>
            <p className="text-sm text-gray-600">{officerInfo.rank}</p>
            <label className="mt-2 block text-sm">Shift<select value={officerInfo.shift} onChange={event => { onShiftChange(event.target.value as OfficerInfo['shift']); setShiftError(false); }} className="mt-1 block min-h-11 w-full rounded border border-slate-300 p-2"><option value="">Choose shift</option>{(['A', 'B', 'C'] as const).map(shift => <option key={shift} value={shift}>Shift {shift}</option>)}</select></label>
            {shiftError && <p role="alert" className="text-sm text-red-700">Select the shift for this inspection.</p>}
          </div>
          <div>
            <p className="text-sm text-gray-600">Physical vehicle</p>
            <p className="font-medium">{officerInfo.unitNumber}</p>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <p className="text-sm text-gray-600">Total Items Checked</p>
            <p className="font-medium">{totalItems}</p>
          </div>
          <div>
            <p className="text-sm text-gray-600">Items with Issues</p>
            <p className={`font-medium ${issuesCount > 0 ? 'text-red-600' : 'text-green-600'}`}>
              {issuesCount}
            </p>
          </div>
        </div>
      </div>

      <section aria-label="Recorded readings and paper fields" className="mb-6 rounded-lg border border-slate-200 bg-white p-4">
        <h3 className="mb-3 font-semibold">Readings and checkout details</h3>
        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div><dt className="text-sm text-slate-500">Engine hours</dt><dd>{meters.engine_hours ?? 'Not entered'}</dd></div>
          <div><dt className="text-sm text-slate-500">Mileage</dt><dd>{meters.miles ?? 'Not entered'}</dd></div>
          {fieldValues.map(answer => <div key={answer.id}><dt className="text-sm text-slate-500">{checklist.fields.find(field => field.id === answer.id)?.name}</dt><dd className="break-words whitespace-pre-wrap">{answer.value === true ? 'Yes' : answer.value === false ? 'No' : answer.value === null || answer.value === '' ? 'Not entered' : String(answer.value)}</dd></div>)}
        </dl>
      </section>

      {issuesCount > 0 && (
        <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
          <p className="text-red-800 font-medium text-sm">
            ⚠️ This vehicle has {issuesCount} defect{issuesCount !== 1 ? 's' : ''}. 
            Your inspection will be recorded. Findings needing a decision go to an authorized reviewer.
          </p>
        </div>
      )}

      <div className="space-y-4 mb-8">
        <h3 className="text-lg font-medium text-gray-900">Compartments Summary</h3>

        {compartments.map((compartment, index) => {
          const issuesInCompartment = compartment.items.filter(item => item.status !== 'Present').length;

          return (
            <div key={compartment.id} className="border border-gray-200 rounded-lg p-4">
              <div className="flex justify-between items-center mb-2">
                <h4 className="font-medium text-gray-900">
                  Compartment {index + 1}: {compartment.name}
                </h4>
                <span className={`text-sm px-2 py-1 rounded ${
                  issuesInCompartment > 0
                    ? 'bg-red-100 text-red-800'
                    : 'bg-green-100 text-green-800'
                }`}>
                  {issuesInCompartment} issue{issuesInCompartment !== 1 ? 's' : ''}
                </span>
              </div>

              {issuesInCompartment > 0 && (
                <div className="space-y-1">
                  {compartment.items
                    .filter(item => item.status !== 'Present')
                    .map(item => (
                      <div key={item.id} className="text-sm text-gray-600">
                        • {item.name}: <span className="font-medium text-red-600">{item.status}</span>
                        {item.notes && <span> - {item.notes}</span>}
                      </div>
                    ))}
                </div>
              )}
              {compartment.items.filter(item => item.value !== null && item.value !== undefined && item.value !== '').map(item => <p key={item.id} className="mt-2 break-words text-sm"><strong>{item.name}:</strong> {String(item.value)}</p>)}
            </div>
          );
        })}
      </div>

      {/* Officer Signature */}
      <div className="mb-8">
        <h3 className="text-lg font-medium text-gray-900 mb-2">Officer Signature</h3>
        <p className="text-sm text-gray-600 mb-3">Sign below to certify this inspection is accurate.</p>
        <div className={`border-2 rounded-lg bg-white ${sigError ? 'border-red-500' : 'border-gray-300'}`}>
          <SignatureCanvas
            clearOnResize={false}
            ref={sigRef}
            penColor="black"
            onEnd={() => onSignatureChange(sigRef.current?.toDataURL('image/png') ?? null)}
            canvasProps={{
              className: 'w-full',
              style: { width: '100%', height: '150px' }
            }}
          />
        </div>
        {sigError && (
          <p className="text-red-600 text-sm mt-1">Signature is required before submitting.</p>
        )}
        <button
          type="button"
          onClick={clearSignature}
          className="mt-2 text-sm text-blue-600 hover:text-blue-800 underline"
        >
          Clear Signature
        </button>
      </div>

      <div className="flex justify-between">
        <button
          onClick={onBack}
          disabled={submitting}
          className="px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-50"
        >
          Back to Compartments
        </button>

        <button
          onClick={handleSubmit}
          disabled={submitting}
          className="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 font-medium"
        >
          {submitting ? 'Submitting...' : 'Submit Inspection'}
        </button>
      </div>
    </div>
  );
}
