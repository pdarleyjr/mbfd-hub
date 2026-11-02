import { BTN_PRIMARY, BTN_SECONDARY, LiveNote, useWrite } from '../shared';
import { acknowledgeForm } from './api';
import type { FormDetail, FormSummary } from './types';

type Target = Pick<FormSummary, 'id' | 'number' | 'target_label' | 'can_acknowledge' | 'can_reject'>;

interface Props {
  form: Target;
  canAct: boolean;
  /** Acknowledged: the server's fresh answer for this form. */
  onAcknowledged: (detail: FormDetail) => void;
  /** Open the Send back sheet for this form. */
  onReject: () => void;
  /** Someone else changed the form first: refresh what is on screen. */
  onConflict: () => void;
  testIdPrefix?: string;
}

/**
 * The two actions on a form that is still being worked: Acknowledge and Reject. Reconcile is a separate step on the
 * selection bar. The idempotency key is kept until the server confirms, so a retry after a dropped connection is safe.
 */
export default function FormActionButtons({ form, canAct, onAcknowledged, onReject, onConflict, testIdPrefix = 'ems-form' }: Props) {
  const write = useWrite();
  if (!canAct || (!form.can_acknowledge && !form.can_reject)) return null;

  const acknowledgeLabel = write.busy ? 'Acknowledging…' : write.retry ? 'Retry: Acknowledge' : 'Acknowledge';
  const acknowledge = () => {
    void write.run(`acknowledge:${form.id}`, (key) => acknowledgeForm(form.id, key), { onDone: onAcknowledged, onConflict });
  };

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap gap-2">
        {form.can_acknowledge && (
          <button type="button" data-testid={`${testIdPrefix}-acknowledge`} className={BTN_PRIMARY} disabled={write.busy} aria-busy={write.busy}
            aria-label={`${acknowledgeLabel} ${form.target_label} form ${form.number}`} onClick={acknowledge}>
            {acknowledgeLabel}
          </button>
        )}
        {form.can_reject && (
          <button type="button" data-testid={`${testIdPrefix}-reject`} className={BTN_SECONDARY} disabled={write.busy}
            aria-label={`Reject ${form.target_label} form ${form.number}`} onClick={onReject}>
            Reject
          </button>
        )}
      </div>
      <LiveNote tone="warning">{write.error}</LiveNote>
    </div>
  );
}
