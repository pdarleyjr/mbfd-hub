import { useEffect, useRef, useState } from 'react';
import SignatureCanvas from 'react-signature-canvas';
import type { OfficerInfo, Compartment, ChecklistData, ChecklistFieldValue, MeterData, ScheduledChecklistTaskResult } from '../types';
import { ArrowLeft, Send, TriangleAlert, Undo2 } from 'lucide-react';
import { inspectionProgress } from '../utils/inspectionProgress';
import './inspection-workspace.css';

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

const LABEL = 'text-[13px] text-hub-n-600';

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
  const signatureSectionRef = useRef<HTMLDivElement>(null);
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
      // The action bar stays on screen, so bring the signature pad to the member when it is missing.
      signatureSectionRef.current?.scrollIntoView({ block: 'center' });
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
    <div data-hub-ui="2" className="inspection-review">
      <h2 className="text-xl font-semibold">
        Review & Sign Inspection
      </h2>

      <div className="inspection-review-summary">
        <div className="mb-4 grid grid-cols-2 gap-4">
          <div>
            <p className={LABEL}>Member</p>
            <p className="font-medium">{officerInfo.name}</p>
            <p className="text-sm text-hub-n-600">{officerInfo.rank}</p>
            <p className="text-sm">Shift {officerInfo.shift}</p>
          </div>
          <div>
            <p className={LABEL}>Physical vehicle</p>
            <p className="font-medium tabular-nums">{officerInfo.unitNumber}</p>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <p className={LABEL}>Total Items Checked</p>
            <p className="font-medium tabular-nums">{totalItems}</p>
          </div>
          <div>
            <p className={LABEL}>Items with Issues</p>
            <p className="flex items-center gap-2 font-medium tabular-nums">
              {issuesCount}
              <span className={`hub-tag ${issuesCount > 0 ? 'hub-tag--danger' : 'hub-tag--success'}`}>{issuesCount > 0 ? 'Reported' : 'None'}</span>
            </p>
          </div>
        </div>
      </div>

      <div className="inspection-review-columns"><div>
      <section aria-label="Recorded readings and paper fields" className="inspection-review-details">
        <h3 className="mb-3 font-semibold">Readings and checkout details</h3>
        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {meters.engine_hours !== null && <div><dt className={LABEL}>Engine hours</dt><dd className="tabular-nums">{meters.engine_hours}</dd></div>}
          {meters.miles !== null && <div><dt className={LABEL}>Mileage</dt><dd className="tabular-nums">{meters.miles}</dd></div>}
          {fieldValues.filter(answer => answer.value !== null && answer.value !== '').map(answer => <div key={answer.id}><dt className={LABEL}>{checklist.fields.find(field => field.id === answer.id)?.name}</dt><dd className="break-words whitespace-pre-wrap">{answer.value === true ? 'Yes' : answer.value === false ? 'No' : String(answer.value)}</dd></div>)}
        </dl>
      </section>

      {issuesCount > 0 && (
        <div className="mb-6 flex items-start gap-3 rounded-hub-control border border-hub-danger-border bg-hub-danger-bg p-4">
          <TriangleAlert className="mt-0.5 h-5 w-5 flex-none text-hub-danger" aria-hidden="true" />
          <p className="text-sm font-medium text-hub-n-800">
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
                <span className="tabular-nums">
                  {issuesInCompartment > 0 ? `${issuesInCompartment} reported` : `${compartment.items.length} inspected`}
                </span>
              </div>

              {issuesInCompartment > 0 && (
                <ul className="mt-1 space-y-1">
                  {compartment.items
                    .filter(item => item.status !== 'Present')
                    .map(item => (
                      <li key={item.id} className="text-sm text-hub-n-600">
                        {item.name}: <span className="font-medium text-hub-danger">{item.status}</span>
                        {item.notes && <span> - {item.notes}</span>}
                      </li>
                    ))}
                </ul>
              )}
              {compartment.items.filter(item => item.value !== null && item.value !== undefined && item.value !== '').map(item => <p key={item.id} className="mt-2 break-words text-sm"><strong>{item.name}:</strong> <span className="tabular-nums">{String(item.value)}</span></p>)}
            </div>
          );
        })}
      </div>

      {scheduledTasks.length > 0 && <section className="inspection-review-details" aria-label="Recorded scheduled duties"><h3 className="mb-3 font-semibold">Scheduled duties</h3><dl>{scheduledTasks.map(task => <div key={task.id} className="inspection-review-area"><dt>{checklist.due_tasks.find(entry => entry.id === task.id)?.name}</dt><dd>{task.status}{task.notes ? ` · ${task.notes}` : ''}</dd></div>)}</dl></section>}
      </div>

      {/* Member signature */}
      <div className="inspection-signature" ref={signatureSectionRef}>
        <h3 id="inspection-signature-label" className="mb-2 text-lg font-medium text-hub-ink">Member Signature</h3>
        <p id="inspection-signature-help" className="mb-3 text-sm text-hub-n-600">Sign below to certify this inspection is accurate.</p>
        <div className={`inspection-signature-pad ${sigError ? 'is-invalid' : ''}`}>
          <SignatureCanvas
            clearOnResize={false}
            ref={sigRef}
            penColor="black"
            onEnd={() => {
              onSignatureChange(sigRef.current?.toDataURL('image/png') ?? null);
              setSigError(false);
            }}
            canvasProps={{
              className: 'block h-[150px] w-full',
              role: 'img',
              'aria-labelledby': 'inspection-signature-label',
              'aria-description': 'Draw your signature using a mouse, finger, or stylus.'
            }}
          />
        </div>
        {sigError && (
          <p role="alert" className="mt-1 text-sm font-semibold text-hub-danger">Signature is required before submitting.</p>
        )}
        <button type="button" onClick={clearSignature} className="hub-btn hub-btn--tertiary mt-2 -ml-2">
          <Undo2 size={16} aria-hidden="true" />Clear Signature
        </button>
      </div>
      </div>

      {/* Same fixed action bar as the compartment step: the primary action never needs a full scroll. */}
      <div className="inspection-action-bar inspection-review-actions">
        <button type="button" onClick={onBack} disabled={submitting} aria-label="Back to Compartments" className="hub-btn hub-btn--secondary">
          <ArrowLeft size={16} aria-hidden="true" />
          <span className="sm:hidden">Back</span><span className="hidden sm:inline">Back to Compartments</span>
        </button>

        <button type="button" onClick={handleSubmit} disabled={submitting} className="hub-btn hub-btn--primary hub-btn--wrap inspection-review-button">
          <Send size={16} aria-hidden="true" />
          {submitting ? 'Submitting...' : 'Submit Inspection'}
        </button>
      </div>
    </div>
  );
}
