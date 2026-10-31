import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useParams, useSearchParams } from 'react-router';
import DailyPageNav from '../DailyPageNav';
import { LoadError, SkeletonRows } from '../ui/StateViews';
import ChangeoutDone from './ChangeoutDone';
import EquipmentStep from './EquipmentStep';
import { applyTruckDefaults, resolveTruck, stationReturnPath, truckNeedsFleet } from './changeoutLogic';
import ReviewStep from './ReviewStep';
import TruckStep from './TruckStep';
import type { ChangeoutDoneState, ChangeoutStep } from './types';
import { useChangeoutWizard } from './useChangeoutWizard';

const STEP_LABELS: Array<[ChangeoutStep, string]> = [['truck', 'Truck'], ['equipment', 'Equipment'], ['review', 'Review']];
const STEP_HEADINGS: Record<ChangeoutStep, string> = {
  truck: 'Select replacement truck',
  equipment: 'Transfer equipment',
  review: 'Review',
};

function StartOver({ onStartOver }: { onStartOver: () => void }) {
  const [confirming, setConfirming] = useState(false);

  return confirming ? (
    <span className="flex flex-wrap gap-2">
      <button type="button" onClick={onStartOver} className="hub-btn hub-btn--secondary !min-h-11">Clear my choices</button>
      <button type="button" onClick={() => setConfirming(false)} className="hub-btn hub-btn--tertiary !min-h-11">Keep them</button>
    </span>
  ) : <button type="button" onClick={() => setConfirming(true)} className="hub-link hub-link--standalone min-h-11">Start over</button>;
}

function Flow({ apparatusId, alertParam }: { apparatusId: number; alertParam: number | null }) {
  const wizard = useChangeoutWizard(apparatusId, alertParam);
  const { load, context, draft, step, problem, sendState, stationPath } = wizard;
  const headingRef = useRef<HTMLHeadingElement>(null);
  const previousStep = useRef<ChangeoutStep | null>(null);
  const [showProblem, setShowProblem] = useState(false);
  const [noteMissing, setNoteMissing] = useState(false);

  const ready = load.status === 'ready' && context !== null && draft !== null;

  useEffect(() => {
    if (!ready) return;
    if (previousStep.current !== null && previousStep.current !== step) {
      window.scrollTo(0, 0);
      headingRef.current?.focus();
    }
    previousStep.current = step;
  }, [ready, step]);

  if (load.status === 'failed') {
    return (
      <div data-hub-ui="2" className="mx-auto max-w-2xl space-y-6 font-hub">
        <DailyPageNav fallback={stationPath} className="!mb-0" />
        <LoadError title="This changeout could not be loaded" message={load.message} onRetry={wizard.retryLoad} />
      </div>
    );
  }

  if (!ready) {
    return (
      <div data-hub-ui="2" className="mx-auto max-w-2xl space-y-6 font-hub">
        <DailyPageNav fallback={stationPath} className="!mb-0" />
        <div className="hub-panel p-4 sm:p-6"><SkeletonRows /></div>
      </div>
    );
  }

  const { unit } = context;
  const truck = resolveTruck(draft, context);
  const view = applyTruckDefaults(context, truck);
  const needsFleet = truck !== null && truckNeedsFleet(truck);
  const sending = sendState.kind === 'sending';
  const headerParts = [unit.designation, unit.vehicle_number ? `truck ${unit.vehicle_number}` : null, unit.station_label].filter(Boolean);
  const reason = context.alert?.reason;

  if (context.pending_changeout && !draft.attempted && sendState.kind === 'idle') {
    return (
      <div data-hub-ui="2" className="mx-auto max-w-2xl space-y-6 font-hub">
        <DailyPageNav fallback={stationPath} className="!mb-0" />
        <section className="hub-panel space-y-4 p-4 sm:p-6">
          <h1 className="font-heading text-xl font-bold text-hub-ink sm:text-2xl">{unit.designation} already has a changeout waiting on Fleet</h1>
          <p className="text-sm text-hub-n-800">
            {context.pending_changeout.submitted_by_name ?? 'Another member'} sent it
            {context.pending_changeout.to_vehicle_number ? ` for truck ${context.pending_changeout.to_vehicle_number}` : ''}. Nothing else needs to be sent until Fleet decides.
          </p>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Link to={`/changeouts/${context.pending_changeout.id}?return_to=${encodeURIComponent(stationPath)}`} className="hub-btn hub-btn--primary hub-btn--wrap !min-h-11">See the one waiting on Fleet</Link>
            <Link to={stationPath} className="hub-btn hub-btn--secondary hub-btn--wrap !min-h-11">Back to station</Link>
            {wizard.working && <button type="button" onClick={wizard.discard} className="hub-btn hub-btn--tertiary hub-btn--wrap !min-h-11">Discard my copy</button>}
          </div>
        </section>
      </div>
    );
  }

  const advance = () => {
    if (step === 'truck') {
      if (problem !== null) {
        setShowProblem(true);
        return;
      }
      setShowProblem(false);
      wizard.goTo('equipment');
      return;
    }
    if (step === 'equipment') {
      if (!context.equipment.usable && draft.note.trim() === '') {
        setNoteMissing(true);
        return;
      }
      setNoteMissing(false);
      wizard.goTo('review');
      return;
    }
    void wizard.send();
  };

  const back = () => wizard.goTo(step === 'review' ? 'equipment' : 'truck');
  const finalLabel = context.mode === 'handoff_only' ? 'Record Handoff' : needsFleet ? 'Send to Fleet' : 'Complete Changeout';
  const primaryLabel = step !== 'review'
    ? 'Continue'
    : sending ? 'Sending…' : sendState.kind === 'not_sent' ? 'Try again' : finalLabel;
  // These outcomes each offer their own next step, so a plain resend is not the way forward.
  const primaryBlocked = step === 'review' && (sendState.kind === 'stale' || sendState.kind === 'pending' || sendState.kind === 'replay');

  return (
    <div data-hub-ui="2" className="mx-auto max-w-2xl space-y-4 font-hub">
      <DailyPageNav fallback={stationPath} className="!mb-0" />

      <header className="hub-panel p-4 sm:p-6">
        <h1 className="font-heading text-xl font-bold tabular-nums text-hub-ink sm:text-2xl">{headerParts.join(', ')}</h1>
        {reason && <p className="mt-1 text-hub-n-600">{reason}</p>}
        <ol className="mt-4 grid grid-cols-3 gap-2" aria-label="Changeout steps">
          {STEP_LABELS.map(([value, label], index) => (
            <li
              key={value}
              aria-current={value === step ? 'step' : undefined}
              className={`rounded-hub-control px-1 py-2 text-center text-xs font-semibold ${value === step ? 'bg-hub-blue text-white' : 'bg-hub-n-100 text-hub-n-600'}`}
            >
              {index + 1}. {label}
            </li>
          ))}
        </ol>
      </header>

      {wizard.restored && (
        <div role="status" className="hub-callout flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
          <span>Your saved work is back.</span>
          <StartOver onStartOver={wizard.startOver} />
        </div>
      )}
      {wizard.usingSavedList && <p role="status" className="hub-callout hub-callout--warning p-3 text-sm">No signal, so this is the list saved on this phone. You can keep working.</p>}
      {wizard.storageFailed && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">This phone could not save your work. Keep this page open until it is sent.</p>}
      {wizard.notices.map((notice) => <p key={notice} role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{notice}</p>)}

      <section aria-labelledby="changeout-step-heading" className="hub-panel p-4 sm:p-6">
        <h2 id="changeout-step-heading" ref={headingRef} tabIndex={-1} className="mb-4 font-heading text-xl font-bold text-hub-ink focus:outline-none">{STEP_HEADINGS[step]}</h2>
        {step === 'truck' && (
          <TruckStep
            context={context}
            draft={draft}
            problem={showProblem ? problem : null}
            onChoose={wizard.setChoice}
            onTyped={wizard.setTyped}
          />
        )}
        {step === 'equipment' && (
          <EquipmentStep
            context={view}
            truck={truck}
            exceptions={draft.exceptions}
            note={draft.note}
            noteMissing={noteMissing && draft.note.trim() === ''}
            onLine={wizard.setLine}
            onNote={wizard.setNote}
          />
        )}
        {step === 'review' && (
          <ReviewStep
            context={view}
            truck={truck}
            exceptions={draft.exceptions}
            note={draft.note}
            sendState={sendState}
            sending={sending}
            onNote={wizard.setNote}
            onChangeEquipment={() => wizard.goTo('equipment')}
            onReviewCurrentTruck={wizard.reviewWithCurrentTruck}
            onSendAsNew={wizard.sendAsNew}
            onDiscard={wizard.discard}
          />
        )}
      </section>

      <div className="sticky bottom-[calc(var(--hub-phone-nav-height,0px)+8px)] z-10 rounded-hub-card border border-hub-glass-border bg-hub-well p-3 shadow-md">
        <div className="flex gap-3">
          {step !== 'truck' && (
            <button type="button" onClick={back} disabled={sending} className="hub-btn hub-btn--secondary !min-h-11 flex-1">Back</button>
          )}
          <button
            type="button"
            onClick={advance}
            disabled={sending || primaryBlocked}
            aria-busy={sending}
            className="hub-btn hub-btn--primary hub-btn--wrap !min-h-11 flex-[2]"
          >
            {primaryLabel}
          </button>
        </div>
        {wizard.working && !wizard.storageFailed && <p className="mt-2 text-center text-[13px] leading-5 text-hub-n-600">Saved on this phone until it is sent.</p>}
      </div>
    </div>
  );
}

/** Route for /changeouts/new/:apparatusId. Done is read from history state, so the flow is not remounted to show it. */
export default function ChangeoutWizard() {
  const params = useParams<{ apparatusId: string }>();
  const [search] = useSearchParams();
  const location = useLocation();
  const done = (location.state as { changeoutDone?: ChangeoutDoneState } | null)?.changeoutDone;
  const apparatusId = /^\d+$/.test(params.apparatusId ?? '') ? Number(params.apparatusId) : null;
  const alertValue = search.get('alert_id');
  const alertParam = alertValue !== null && /^\d+$/.test(alertValue) ? Number(alertValue) : null;

  if (done) return <ChangeoutDone done={done} />;

  if (apparatusId === null) {
    return (
      <div data-hub-ui="2" className="mx-auto max-w-2xl space-y-6 font-hub">
        <DailyPageNav fallback={stationReturnPath(search.get('return_to'), null)} className="!mb-0" />
        <div className="hub-panel p-4 sm:p-6"><p className="text-hub-n-800">This link does not point to a truck.</p></div>
      </div>
    );
  }

  // Not keyed by truck: a stale changeout follows the unit to another truck row without losing the work in memory.
  return <Flow apparatusId={apparatusId} alertParam={alertParam} />;
}
