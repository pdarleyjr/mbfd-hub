import { Link } from 'react-router';
import type { SendState } from './useChangeoutWizard';
import { CHANGEOUT_NOTE_MAX, equipmentCounts, leftItems } from './changeoutLogic';
import type { ChangeoutContext, ResolvedTruck } from './types';

interface Props {
  context: ChangeoutContext;
  truck: ResolvedTruck | null;
  fromVehicle: string | null;
  left: Record<string, string>;
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
          <div className="flex flex-wrap gap-3">
            <button type="button" onClick={onReviewCurrentTruck} className="hub-btn hub-btn--secondary hub-btn--wrap !min-h-11">Review with the current truck</button>
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

export default function ReviewStep({ context, truck, fromVehicle, left, note, sendState, sending, onNote, onChangeEquipment, onReviewCurrentTruck, onSendAsNew, onDiscard }: Props) {
  const { unit } = context;
  const usable = context.equipment.usable;
  const counts = equipmentCounts(context, left);
  const lefts = leftItems(context, left);

  return (
    <div className="space-y-5">
      <dl className="hub-well divide-y divide-hub-n-100">
        <Row label="From truck" value={fromVehicle ?? 'Not recorded'} />
        <Row label="To truck" value={truck?.vehicleNumber ?? 'Not chosen'} />
        {usable && <Row label="Took" value={`${counts.took} of ${counts.total}`} />}
        {usable && <Row label="Left" value={String(counts.left)} />}
      </dl>

      {usable && (
        <section aria-labelledby="changeout-review-left">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h3 id="changeout-review-left" className="font-heading text-lg font-semibold tabular-nums text-hub-ink">Left on truck {fromVehicle ?? 'not recorded'}</h3>
            <button type="button" onClick={onChangeEquipment} className="hub-link hub-link--standalone min-h-11">Change</button>
          </div>
          {lefts.length === 0
            ? <p className="text-sm text-hub-n-600">Nothing. You took everything.</p>
            : (
              <ul data-testid="changeout-review-left" className="hub-well divide-y divide-hub-n-100">
                {lefts.map((line, index) => (
                  <li key={`${line.name}-${index}`} className="px-4 py-3 text-sm">
                    <p className="font-semibold text-hub-ink">{line.name}</p>
                    {line.note && <p className="text-hub-n-600">{line.note}</p>}
                  </li>
                ))}
              </ul>
            )}
        </section>
      )}

      {context.open_issues_on_truck > 0 && (
        <p className="text-sm text-hub-n-600">
          {context.open_issues_on_truck} open {context.open_issues_on_truck === 1 ? 'issue stays' : 'issues stay'} with truck {fromVehicle ?? 'not recorded'}.
        </p>
      )}

      {usable && (
        <div>
          <label htmlFor="changeout-note" className="text-sm font-semibold text-hub-ink">Note (optional)</label>
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
