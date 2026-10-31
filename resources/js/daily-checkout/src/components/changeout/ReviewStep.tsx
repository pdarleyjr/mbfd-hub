import { Link } from 'react-router';
import type { SendState } from './useChangeoutWizard';
import { CHANGEOUT_NOTE_MAX, controlledLines, effectiveChoice, equipmentCounts, isAttentionLine, lineStateLabel, standardLines, truckNeedsFleet } from './changeoutLogic';
import type { ChangeoutContext, LineChoice, ResolvedTruck } from './types';

interface Props {
  context: ChangeoutContext;
  truck: ResolvedTruck | null;
  exceptions: Record<string, LineChoice>;
  note: string;
  sendState: SendState;
  sending: boolean;
  onNote: (note: string) => void;
  onChangeEquipment: () => void;
  onReviewCurrentTruck: () => void;
  onSendAsNew: () => void;
  onDiscard: () => void;
}

const Row = ({ label, value }: { label: string; value: string }) => (
  <div className="flex justify-between gap-4 px-4 py-3">
    <dt className="text-hub-n-600">{label}</dt>
    <dd className="text-right font-medium tabular-nums text-hub-ink">{value}</dd>
  </div>
);

function SendOutcome({ state, sending, designation, onReviewCurrentTruck, onSendAsNew, onDiscard }: Pick<Props, 'sending' | 'onReviewCurrentTruck' | 'onSendAsNew' | 'onDiscard'> & { state: SendState; designation: string }) {
  switch (state.kind) {
    case 'not_sent':
    case 'refused':
      return <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{state.message}</p>;
    case 'stale':
      return (
        <div role="alert" className="hub-callout hub-callout--warning space-y-3 p-3 text-sm">
          <p className="font-semibold">{designation} is on truck {state.currentVehicle ?? 'not recorded'} now.</p>
          <p>{state.message}</p>
          <button type="button" onClick={onReviewCurrentTruck} className="hub-btn hub-btn--secondary hub-btn--wrap !min-h-11 w-full sm:w-auto">Review with the current truck</button>
        </div>
      );
    case 'pending':
      return (
        <div role="alert" className="hub-callout hub-callout--warning space-y-3 p-3 text-sm">
          <p>{state.message}</p>
          <div className="flex flex-wrap gap-3">
            {state.changeoutId !== null && <Link to={`/changeouts/${state.changeoutId}`} className="hub-btn hub-btn--secondary hub-btn--wrap !min-h-11">See that record</Link>}
            <button type="button" onClick={onDiscard} className="hub-btn hub-btn--tertiary hub-btn--wrap !min-h-11">Discard my copy</button>
          </div>
        </div>
      );
    case 'replay':
      return (
        <div role="alert" className="hub-callout hub-callout--warning space-y-3 p-3 text-sm">
          <p className="font-semibold">An earlier send from this phone had different choices.</p>
          <p>{state.message}</p>
          <p>Check what was saved first. If it is not what you meant, send what is on this screen.</p>
          <div className="flex flex-wrap gap-3">
            {state.changeoutId !== null && <Link to={`/changeouts/${state.changeoutId}`} className="hub-btn hub-btn--secondary hub-btn--wrap !min-h-11">See what was saved</Link>}
            <button type="button" disabled={sending} onClick={onSendAsNew} className="hub-btn hub-btn--secondary hub-btn--wrap !min-h-11">Send what is on this screen</button>
          </div>
        </div>
      );
    default:
      return null;
  }
}

export default function ReviewStep({ context, truck, exceptions, note, sendState, sending, onNote, onChangeEquipment, onReviewCurrentTruck, onSendAsNew, onDiscard }: Props) {
  const { unit } = context;
  const usable = context.equipment.usable;
  const counts = equipmentCounts(context, exceptions);
  const attention = standardLines(context).filter((line) => isAttentionLine(line, exceptions));
  const controlled = controlledLines(context);
  const needsFleet = truck !== null && truckNeedsFleet(truck);

  return (
    <div className="space-y-5">
      <dl className="hub-well divide-y divide-hub-n-100">
        <Row label="From truck" value={(context.mode === 'handoff_only' ? context.alert?.vehicle_number : unit.vehicle_number) ?? 'Not recorded'} />
        <Row label="To truck" value={truck?.vehicleNumber ?? 'Not chosen'} />
        {usable && <Row label="Moving" value={`${counts.moving} of ${counts.total}`} />}
      </dl>

      {needsFleet && <p className="hub-callout p-3 text-sm">Fleet will confirm truck {truck?.vehicleNumber}. Nothing changes on either truck until then, and your equipment choices are saved with the request.</p>}

      {usable && (
        <section aria-labelledby="changeout-review-exceptions">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h3 id="changeout-review-exceptions" className="font-heading text-lg font-semibold text-hub-ink">Exceptions</h3>
            <button type="button" onClick={onChangeEquipment} className="hub-link hub-link--standalone min-h-11">Change equipment</button>
          </div>
          {attention.length === 0
            ? <p className="text-sm text-hub-n-600">None. All equipment moves.</p>
            : (
              <ul data-testid="changeout-review-exceptions" className="hub-well divide-y divide-hub-n-100">
                {attention.map((line) => {
                  const choice = effectiveChoice(line, exceptions);

                  return (
                    <li key={line.item_key} className="px-4 py-3 text-sm">
                      <p className="font-semibold text-hub-ink">{line.name}</p>
                      <p className="text-hub-n-800">{lineStateLabel(line, choice)}</p>
                      {choice.note.trim() !== '' && <p className="text-hub-n-600">{choice.note}</p>}
                    </li>
                  );
                })}
              </ul>
            )}
        </section>
      )}

      {controlled.length > 0 && (
        <section aria-labelledby="changeout-review-waiting" className="hub-well p-4">
          <h3 id="changeout-review-waiting" className="font-semibold text-hub-ink">Will wait for verification</h3>
          <ul className="mt-2 space-y-1 text-sm text-hub-n-800">{controlled.map((line) => <li key={line.item_key}>{line.name}</li>)}</ul>
        </section>
      )}

      {context.open_issues_on_truck > 0 && (
        <p className="text-sm text-hub-n-600">
          {context.open_issues_on_truck} open {context.open_issues_on_truck === 1 ? 'issue stays' : 'issues stay'} with truck {unit.vehicle_number ?? 'not recorded'}.
        </p>
      )}

      {usable && (
        <div>
          <label htmlFor="changeout-note" className="text-sm font-semibold text-hub-ink">Note for Fleet (optional)</label>
          <textarea
            id="changeout-note"
            value={note}
            rows={3}
            maxLength={CHANGEOUT_NOTE_MAX}
            onChange={(event) => onNote(event.target.value)}
            className="mt-1 block w-full rounded-hub-control border border-hub-control-border bg-hub-well p-3 text-hub-ink"
          />
        </div>
      )}
      {!usable && note.trim() !== '' && <p className="hub-well p-4 text-sm text-hub-n-800"><span className="font-semibold">Your note:</span> {note}</p>}

      <SendOutcome
        state={sendState}
        sending={sending}
        designation={unit.designation}
        onReviewCurrentTruck={onReviewCurrentTruck}
        onSendAsNew={onSendAsNew}
        onDiscard={onDiscard}
      />
    </div>
  );
}
