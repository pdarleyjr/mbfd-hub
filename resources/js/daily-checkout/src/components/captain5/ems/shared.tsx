import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../../utils/api';
import { EmsApiError, failureMessage, isForbidden, isRetryable } from '../../../utils/emsApi';
import { newKey } from '../format';
import { BTN, BTN_PRIMARY, BTN_SECONDARY, FIELD, HINT, LABEL } from '../ui';
import { createKeyKeeper } from './logic';

export { BTN, BTN_PRIMARY, BTN_SECONDARY, FIELD, HINT, LABEL };

/**
 * Loads one thing and keeps it. A failed refresh never blanks what is already on screen: the data stays and `error`
 * says it may be out of date. `tick` (a counter owned by the page) triggers a quiet refresh; only the newest answer is used.
 */
export function useEmsLoad<T>(load: () => Promise<T>, key: string, options: { tick?: number; onForbidden?: () => void } = {}) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const loadRef = useRef(load);
  loadRef.current = load;
  const forbiddenRef = useRef(options.onForbidden);
  forbiddenRef.current = options.onForbidden;
  const latest = useRef(0);

  const reload = useCallback(async (silent = false) => {
    const mine = ++latest.current;
    if (!silent) setLoading(true);
    try {
      const next = await loadRef.current();
      if (mine !== latest.current) return;
      setData(next);
      setError(null);
    } catch (failure) {
      if (mine !== latest.current) return;
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      if (isForbidden(failure)) { forbiddenRef.current?.(); return; }
      setError(failureMessage(failure, 'This could not be loaded.'));
    } finally {
      if (mine === latest.current) setLoading(false);
    }
  }, []);

  useEffect(() => { void reload(false); }, [key, reload]);

  const seenTick = useRef(options.tick);
  useEffect(() => {
    if (options.tick === seenTick.current) return;
    seenTick.current = options.tick;
    void reload(true);
  }, [options.tick, reload]);

  return { data, error, loading, reload, setData };
}

interface WriteState {
  busy: boolean;
  error: string | null;
  fields: Record<string, string>;
  /** The last try ended without an answer or on a busy server: the same button sends the same request again. */
  retry: boolean;
}

/**
 * One write at a time with the idempotency key kept per action. `run` sends `work(key)`; the key is reused while the
 * content (`signature`) is unchanged, and replaced once the server has confirmed the action.
 */
export function useWrite() {
  const [state, setState] = useState<WriteState>({ busy: false, error: null, fields: {}, retry: false });
  const keeper = useRef(createKeyKeeper(newKey));
  const inFlight = useRef(false);

  const run = useCallback(async <T,>(
    signature: string,
    work: (key: string) => Promise<T>,
    handlers: { onDone: (result: T) => void; onConflict?: (error: EmsApiError) => void },
  ): Promise<boolean> => {
    if (inFlight.current) return false;
    inFlight.current = true;
    setState({ busy: true, error: null, fields: {}, retry: false });
    try {
      const result = await work(keeper.current.keyFor(signature));
      keeper.current.reset();
      setState({ busy: false, error: null, fields: {}, retry: false });
      handlers.onDone(result);
      return true;
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return false; }
      if (failure instanceof EmsApiError && failure.isConflict) handlers.onConflict?.(failure);
      setState({
        busy: false,
        error: failureMessage(failure, 'That could not be saved. Try again.'),
        fields: failure instanceof EmsApiError ? failure.fields : {},
        retry: isRetryable(failure),
      });
      return false;
    } finally {
      inFlight.current = false;
    }
  }, []);

  const clear = useCallback(() => setState({ busy: false, error: null, fields: {}, retry: false }), []);
  return { ...state, run, clear };
}

/** A message that screen readers announce when it appears; the container stays mounted so the change is heard. */
export function LiveNote({ children, tone = 'info', testId }: { children?: ReactNode; tone?: 'info' | 'warning'; testId?: string }) {
  return (
    <div role={tone === 'warning' ? 'alert' : 'status'} aria-live={tone === 'warning' ? 'assertive' : 'polite'} data-testid={testId}>
      {children ? <p className={`hub-callout p-3 text-sm ${tone === 'warning' ? 'hub-callout--warning' : ''}`}>{children}</p> : null}
    </div>
  );
}

export function ErrorText({ id, children }: { id?: string; children?: ReactNode }) {
  if (!children) return null;
  return <p id={id} role="alert" className="mt-1 text-[13px] font-semibold text-hub-danger">{children}</p>;
}

interface FieldProps {
  label: string;
  hint?: string;
  error?: string | null;
  required?: boolean;
  children: (props: { id: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean }) => ReactNode;
}

/** A label, the control, an optional hint and an error wired together for assistive technology. */
export function Field({ label, hint, error, required, children }: FieldProps) {
  const id = useId();
  const describedBy = [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;
  return (
    <div className="min-w-0">
      <label className={LABEL} htmlFor={id}>{label}{required && <span className="text-hub-danger"> (required)</span>}</label>
      {children({ id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined })}
      {hint && <p id={`${id}-hint`} className={`${HINT} mt-1`}>{hint}</p>}
      <ErrorText id={`${id}-error`}>{error}</ErrorText>
    </div>
  );
}

/** Whole-number input: a text box with a numeric keypad, so the mouse wheel and arrows never change a count. */
export function NumberInput({ value, onChange, disabled, label, id, maxLength = 4, caption, ...aria }: {
  value: string; onChange: (value: string) => void; disabled?: boolean; label?: string; id?: string; maxLength?: number;
  /** Visible words above the box; `label` is what assistive technology reads. */
  caption?: string;
  'aria-describedby'?: string; 'aria-invalid'?: boolean;
}) {
  const input = (
    <input
      id={id}
      type="text"
      inputMode="numeric"
      pattern="[0-9]*"
      autoComplete="off"
      aria-label={label}
      className={`${FIELD} !w-28 text-center tabular-nums`}
      value={value}
      disabled={disabled}
      onChange={(event) => onChange(event.target.value.replace(/[^\d]/g, '').slice(0, maxLength))}
      {...aria}
    />
  );
  if (!caption) return input;
  return (
    <span className="block">
      <span className="mb-1 block text-[13px] font-semibold text-hub-n-600" aria-hidden="true">{caption}</span>
      {input}
    </span>
  );
}

/** A risky step asks once, in place, with the consequence in plain words. Focus lands on the confirming button. */
export function ConfirmStep({ title, body, confirmLabel, busy, error, retry, onConfirm, onCancel, testId }: {
  title: string; body: ReactNode; confirmLabel: string; busy: boolean; error?: string | null; retry?: boolean;
  onConfirm: () => void; onCancel: () => void; testId?: string;
}) {
  const confirmRef = useRef<HTMLButtonElement>(null);
  useEffect(() => { confirmRef.current?.focus(); }, []);
  return (
    <div role="group" aria-label={title} data-testid={testId} className="space-y-3 rounded-hub-control border border-hub-warning-border bg-hub-warning-bg p-3 sm:p-4">
      <p className="font-semibold text-hub-ink">{title}</p>
      <div className="text-sm text-hub-ink">{body}</div>
      {error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{error}</p>}
      <div className="flex flex-wrap gap-2">
        <button ref={confirmRef} type="button" className={BTN_PRIMARY} disabled={busy} aria-busy={busy} onClick={onConfirm}>
          {busy ? 'Saving…' : retry ? `Retry: ${confirmLabel}` : confirmLabel}
        </button>
        <button type="button" className={`${BTN} hub-btn--tertiary`} disabled={busy} onClick={onCancel}>Go back</button>
      </div>
    </div>
  );
}

/** A row of toggle buttons that act as one control (filters, sub-views). Each button is 44px tall and says its state. */
export function ChoiceChips<T extends string>({ label, options, value, onChange, testId }: {
  label: string; options: Array<{ id: T; label: string }>; value: T; onChange: (id: T) => void; testId?: string;
}) {
  return (
    <div role="group" aria-label={label} data-testid={testId} className="flex flex-wrap gap-2">
      {options.map((option) => (
        <button
          key={option.id}
          type="button"
          aria-pressed={option.id === value}
          onClick={() => onChange(option.id)}
          className={`${option.id === value ? BTN_PRIMARY : BTN_SECONDARY} !px-4`}
        >
          {option.label}
        </button>
      ))}
    </div>
  );
}

export function Pager({ page, lastPage, total, noun, onPage, busy }: { page: number; lastPage: number; total: number; noun: string; onPage: (page: number) => void; busy?: boolean }) {
  if (lastPage <= 1) return <p className={HINT} aria-live="polite">{total} {noun}</p>;
  return (
    <nav aria-label={`${noun} pages`} className="flex flex-wrap items-center gap-3">
      <button type="button" className={BTN_SECONDARY} disabled={page <= 1 || busy} onClick={() => onPage(page - 1)}>Previous</button>
      <span className={HINT} aria-live="polite">Page {page} of {lastPage} ({total} {noun})</span>
      <button type="button" className={BTN_SECONDARY} disabled={page >= lastPage || busy} onClick={() => onPage(page + 1)}>Next</button>
    </nav>
  );
}
