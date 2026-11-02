import { useEffect, useRef, useState, type FormEvent } from 'react';
import { BTN, BTN_PRIMARY, Field, FIELD, HINT, LiveNote } from '../shared';
import { wholeText } from './editLogic';
import { orderQuantity } from './orderLogic';

export interface OrderValues { quantity: string; expectedOn: string; note: string }
export interface OrderSubmit { quantity: number; expectedOn: string; note: string }

interface Props {
  title: string;
  hint?: string;
  initial: OrderValues;
  quantityLabel: string;
  /** The arrival step asks only how many came in and an optional note. */
  showDate: boolean;
  submitLabel: string;
  busy: boolean;
  error: string | null;
  retry: boolean;
  serverFields: Record<string, string>;
  onSubmit: (values: OrderSubmit) => void;
  onBack: () => void;
  testId: string;
}

export const MAX_NOTE = 500;

/** One small step: how many, when it is expected, and a note. Only the quantity is required. */
export default function OrderForm({ title, hint, initial, quantityLabel, showDate, submitLabel, busy, error, retry, serverFields, onSubmit, onBack, testId }: Props) {
  const [values, setValues] = useState<OrderValues>(initial);
  const [quantityError, setQuantityError] = useState<string | null>(null);
  const quantityRef = useRef<HTMLInputElement>(null);
  // The step opens on the one box that matters.
  useEffect(() => { quantityRef.current?.focus(); }, []);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const quantity = orderQuantity(values.quantity);
    if (quantity === null) { setQuantityError('Type a whole number, 1 or more.'); return; }
    setQuantityError(null);
    onSubmit({ quantity, expectedOn: values.expectedOn, note: values.note.trim() });
  };

  return (
    <form onSubmit={submit} noValidate className="space-y-3 rounded-hub-control border border-hub-border bg-hub-n-25 p-3 sm:p-4" data-testid={testId} aria-label={title}>
      <div>
        <p className="font-semibold text-hub-ink">{title}</p>
        {hint && <p className={HINT}>{hint}</p>}
      </div>
      <Field label={quantityLabel} error={quantityError ?? serverFields.quantity}>
        {(aria) => <input {...aria} ref={quantityRef} type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off" className={`${FIELD} !w-32 tabular-nums`} value={values.quantity}
          disabled={busy} data-testid="order-quantity" onChange={(event) => setValues({ ...values, quantity: wholeText(event.target.value) })} />}
      </Field>
      {showDate && (
        <Field label="Expected by (optional)" error={serverFields.expected_on}>
          {(aria) => <input {...aria} type="date" className={`${FIELD} sm:!w-56`} value={values.expectedOn} disabled={busy} data-testid="order-expected-on"
            onChange={(event) => setValues({ ...values, expectedOn: event.target.value })} />}
        </Field>
      )}
      <Field label="Note (optional)" error={serverFields.note}>
        {(aria) => <textarea {...aria} rows={2} maxLength={MAX_NOTE} className={FIELD} value={values.note} disabled={busy} data-testid="order-note"
          onChange={(event) => setValues({ ...values, note: event.target.value })} />}
      </Field>
      <LiveNote tone="warning">{error}</LiveNote>
      <div className="flex flex-wrap gap-2">
        <button type="submit" className={BTN_PRIMARY} disabled={busy} aria-busy={busy} data-testid="order-save">
          {busy ? 'Saving…' : retry ? `Try again: ${submitLabel}` : submitLabel}
        </button>
        <button type="button" className={`${BTN} hub-btn--tertiary`} disabled={busy} onClick={onBack}>Go back</button>
      </div>
    </form>
  );
}
