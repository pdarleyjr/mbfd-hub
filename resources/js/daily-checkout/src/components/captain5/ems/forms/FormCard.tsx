import { useId } from 'react';
import { Check, ChevronRight, Clock, CornerUpLeft } from 'lucide-react';
import Tag from '../../../ui/Tag';
import { etDateTime, memberText, targetText } from '../logic';
import { HINT } from '../shared';
import FormActionButtons from './FormActionButtons';
import { ackText, archiveText, FORM_STATUS_TONE, isReturned, itemSummary, returnedText, revisionTag, selectionControl } from './logic';
import type { FormDetail, FormSummary, FormView } from './types';

interface Props {
  form: FormSummary;
  view: FormView;
  canAct: boolean;
  selected: boolean;
  onToggle: (id: string) => void;
  onOpen: (id: string) => void;
  onAcknowledged: (detail: FormDetail) => void;
  onReject: (form: FormSummary) => void;
  onConflict: () => void;
}

/** The tick box is a full 44px target; the visible square sits inside it. */
function SelectionControl({ form, enabled, selected, onToggle, hintId }: { form: FormSummary; enabled: boolean; selected: boolean; onToggle: (id: string) => void; hintId: string | undefined }) {
  return (
    <div className="flex w-[4.5rem] flex-none flex-col items-center gap-0.5 pt-1.5">
      <label className={`relative block h-11 w-11 ${enabled ? 'cursor-pointer' : 'cursor-not-allowed'}`}>
        <input
          type="checkbox"
          data-testid="ems-form-select"
          className="peer absolute inset-0 m-0 h-full w-full cursor-[inherit] opacity-0"
          checked={selected}
          disabled={!enabled}
          aria-describedby={hintId}
          aria-label={`Select ${form.target_label} form ${form.number} to reconcile`}
          onChange={() => onToggle(form.id)}
        />
        <span
          aria-hidden="true"
          className="pointer-events-none absolute left-1/2 top-1/2 flex h-6 w-6 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded border-2 border-hub-border-strong bg-hub-n-0 text-hub-n-0 peer-checked:border-hub-blue peer-checked:bg-hub-blue peer-disabled:border-hub-border peer-disabled:bg-hub-n-50 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-hub-focus"
        >
          <Check className={`h-4 w-4 ${selected ? 'opacity-100' : 'opacity-0'}`} strokeWidth={3} />
        </span>
      </label>
      {hintId && <span id={hintId} className="text-center text-[11px] font-semibold leading-4 text-hub-n-600">Acknowledge first</span>}
    </div>
  );
}

function StateLine({ form, view }: { form: FormSummary; view: FormView }) {
  if (view === 'archived') return <span className="text-[13px] font-semibold text-hub-n-600">{archiveText(form)}</span>;
  if (isReturned(form)) {
    return <span className="flex items-start gap-1.5 text-[13px] font-semibold text-hub-danger"><CornerUpLeft className="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" /><span className="min-w-0 break-words">{returnedText(form)}</span></span>;
  }
  return form.acknowledged
    ? <span className="flex items-start gap-1.5 text-[13px] font-semibold text-hub-success"><Check className="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" /><span className="min-w-0 break-words">{ackText(form)}</span></span>
    : <span className="flex items-start gap-1.5 text-[13px] font-semibold text-hub-warning"><Clock className="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" /><span>{ackText(form)}</span></span>;
}

/** One submitted form: who sent it, what is on it, whether it has been acknowledged, and the actions that are allowed. */
export default function FormCard({ form, view, canAct, selected, onToggle, onOpen, onAcknowledged, onReject, onConflict }: Props) {
  const id = useId();
  const control = view === 'active' ? selectionControl(form, canAct) : 'none';
  const summary = itemSummary(form);
  const revision = revisionTag(form);
  const working = view === 'active' && !isReturned(form);

  return (
    <li>
      <article
        data-testid="ems-form-card"
        data-form-id={form.id}
        data-status={form.status}
        aria-labelledby={`${id}-title`}
        className={`rounded-hub-control border bg-hub-well ${selected ? 'border-hub-blue ring-1 ring-hub-blue' : 'border-hub-border'}`}
      >
        <div className="flex items-start">
          {control !== 'none' && (
            <SelectionControl form={form} enabled={control === 'enabled'} selected={selected} onToggle={onToggle} hintId={control === 'needs_acknowledgment' ? `${id}-hint` : undefined} />
          )}
          <button
            type="button"
            data-testid="ems-form-open"
            onClick={() => onOpen(form.id)}
            className={`flex min-h-14 min-w-0 flex-1 items-center gap-2 rounded-hub-control py-3 pr-3.5 text-left transition-colors hover:bg-hub-blue-tint ${control === 'none' ? 'pl-3.5' : 'pl-1'}`}
          >
            <span className="flex min-w-0 flex-1 flex-col gap-1.5">
              <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <strong id={`${id}-title`} className="break-words font-heading text-lg font-bold leading-6 text-hub-ink">{targetText(form)}</strong>
                {revision && <Tag tone="info">{revision}</Tag>}
                {(view === 'archived' || isReturned(form)) && <Tag tone={FORM_STATUS_TONE[form.status] ?? 'neutral'}>{form.status_label}</Tag>}
              </span>
              <span className={`${HINT} break-words`}>{memberText(form)} · Submitted {etDateTime(form.submitted_at)}</span>
              <span className="break-words text-sm text-hub-ink">{summary.list}</span>
              <span className={HINT}>{[form.number, summary.total].filter(Boolean).join(' · ')}</span>
              <StateLine form={form} view={view} />
            </span>
            <ChevronRight className="h-5 w-5 flex-none text-hub-n-500" aria-hidden="true" />
          </button>
        </div>
        {working && canAct && (form.can_acknowledge || form.can_reject) && (
          <div className="border-t border-hub-border px-3.5 py-2.5">
            <FormActionButtons form={form} canAct={canAct} onAcknowledged={onAcknowledged} onReject={() => onReject(form)} onConflict={onConflict} testIdPrefix="ems-form" />
          </div>
        )}
      </article>
    </li>
  );
}
