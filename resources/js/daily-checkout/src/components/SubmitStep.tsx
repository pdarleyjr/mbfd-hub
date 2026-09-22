import { useEffect, useRef, useState } from 'react';
import SignatureCanvas from 'react-signature-canvas';
import type { OfficerInfo, Compartment, ChecklistData, ChecklistFieldValue, MeterData, ScheduledChecklistTaskResult } from '../types';
import { Send, Undo2 } from 'lucide-react';
import { inspectionProgress } from '../utils/inspectionProgress';

interface SubmitStepProps {
  officerInfo: OfficerInfo;
  compartments: Compartment[];
  checklist: ChecklistData;
  fieldValues: Array<{ id: string; value: ChecklistFieldValue }>;
  meters: MeterData;
  signature: string | null;
  onSignatureChange: (value: string | null) => void;
  scheduledTasks: ScheduledChecklistTaskResult[];
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
  scheduledTasks,
  onSubmit,
  onBack,
  submitting
}: SubmitStepProps) {
  const sigRef = useRef<SignatureCanvas | null>(null);
  const [sigError, setSigError] = useState(false);
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

  const { total: totalItems, issues: issuesCount } = inspectionProgress(compartments);

  const handleSubmit = () => {
    if (!officerInfo.shift) return;
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
    <div className="inspection-review">
      <h2 className="text-xl font-semibold">
        Review & Sign Inspection
      </h2>

      <div className="inspection-review-summary">

        <div className="grid grid-cols-2 gap-4 mb-4">
          <div>
            <p className="text-sm text-gray-600">Member</p>
            <p className="font-medium">{officerInfo.name}</p>
            <p className="text-sm text-gray-600">{officerInfo.rank}</p>
            <p className="text-sm">Shift {officerInfo.shift}</p>
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

      <div className="inspection-review-columns"><div>
      <section aria-label="Recorded readings and paper fields" className="inspection-review-details">
        <h3 className="mb-3 font-semibold">Readings and checkout details</h3>
        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {meters.engine_hours !== null && <div><dt className="text-sm text-slate-500">Engine hours</dt><dd>{meters.engine_hours}</dd></div>}
          {meters.miles !== null && <div><dt className="text-sm text-slate-500">Mileage</dt><dd>{meters.miles}</dd></div>}
          {fieldValues.filter(answer => answer.value !== null && answer.value !== '').map(answer => <div key={answer.id}><dt className="text-sm text-slate-500">{checklist.fields.find(field => field.id === answer.id)?.name}</dt><dd className="break-words whitespace-pre-wrap">{answer.value === true ? 'Yes' : answer.value === false ? 'No' : String(answer.value)}</dd></div>)}
        </dl>
      </section>

      {issuesCount > 0 && (
        <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
          <p className="text-red-800 font-medium text-sm">
            {issuesCount} issue{issuesCount !== 1 ? 's' : ''} reported.
            Your inspection will be recorded. Findings needing a decision go to an authorized reviewer.
          </p>
        </div>
      )}

      <div className="inspection-review-areas">
        <h3 className="text-lg font-medium">Inspected areas</h3>

        {compartments.map((compartment) => {
          const issuesInCompartment = compartment.items.filter(item => item.status !== 'Present').length;

          return (
            <div key={compartment.id} className={`inspection-review-area ${issuesInCompartment > 0 ? 'has-issues' : ''}`}>
              <div>
                <h4>
                  {compartment.name}
                </h4>
                <span>
                  {issuesInCompartment > 0 ? `${issuesInCompartment} reported` : `${compartment.items.length} inspected`}
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

      {scheduledTasks.length > 0 && <section className="inspection-review-details" aria-label="Recorded scheduled duties"><h3 className="mb-3 font-semibold">Scheduled duties</h3><dl>{scheduledTasks.map(task => <div key={task.id} className="inspection-review-area"><dt>{checklist.due_tasks.find(entry => entry.id === task.id)?.name}</dt><dd>{task.status}{task.notes ? ` · ${task.notes}` : ''}</dd></div>)}</dl></section>}
      </div>

      {/* Officer Signature */}
      <div className="inspection-signature">
        <h3 className="text-lg font-medium text-gray-900 mb-2">Member Signature</h3>
        <p className="text-sm text-gray-600 mb-3">Sign below to certify this inspection is accurate.</p>
        <div className={`border-2 rounded-lg bg-white ${sigError ? 'border-red-500' : 'border-gray-300'}`}>
          <SignatureCanvas
            clearOnResize={false}
            ref={sigRef}
            penColor="black"
            onEnd={() => {
              onSignatureChange(sigRef.current?.toDataURL('image/png') ?? null);
              setSigError(false);
            }}
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
          <Undo2 size={16} className="inline mr-2" aria-hidden="true" />Clear Signature
        </button>

      <div className="inspection-review-actions">
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
          className="inspection-review-button disabled:opacity-50"
        >
          <Send size={16} aria-hidden="true" />
          {submitting ? 'Submitting...' : 'Submit Inspection'}
        </button>
      </div>
      </div></div>
    </div>
  );
}
