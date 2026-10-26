import { useEffect, useRef, useState, type FormEvent } from 'react';
import { ApiRequestError, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { requestFailureMessage } from '../../utils/captain5Api';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, HINT, LABEL, Sheet } from '../captain5/ui';
import { dateTimeText, newKey } from './format';
import type { ActingDuration, ActingResult, ActingRole, ActingState, OnDuty } from './types';
import { hubDateInput } from '../../../../shared/timezone.ts';
import { ServerClock } from '../../../../shared/server-clock.ts';

interface Props {
  /** Wording, test ids and the endpoint: Captain 5 and 300 share everything else. */
  role: ActingRole;
  onDuty: OnDuty;
  acting: ActingState;
  /** The server's answer to any acting call: the page shows the new name and state. */
  onChange: (result: ActingResult) => void;
}

/** Server limit for a custom end time: 15 minutes to 72 hours from now. */
const CUSTOM_MIN_MS = 15 * 60_000;
const CUSTOM_MAX_MS = 72 * 3_600_000;

export const ACTING_EXPLANATION = 'An administrator approves this. It only changes the name shown at the top; your work on this page is always recorded under your own name, whether or not you use this.';

interface Attempt { signature: string; key: string }

function DurationChoice({ name, value, checked, title, hint, onSelect, disabled }: {
  name: string; value: ActingDuration; checked: boolean; title: string; hint?: string | null; onSelect: (value: ActingDuration) => void; disabled: boolean;
}) {
  return (
    <label className={`flex min-h-11 cursor-pointer items-start gap-3 rounded-hub-control border px-3 py-2.5 focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-hub-blue ${checked ? 'border-hub-blue bg-hub-blue-tint' : 'border-hub-border bg-hub-well hover:border-hub-blue/40'}`}>
      <input type="radio" name={name} value={value} checked={checked} disabled={disabled} onChange={() => onSelect(value)} className="mt-1 h-4 w-4 flex-none" />
      <span className="min-w-0">
        <span className="block text-sm font-semibold text-hub-ink">{title}</span>
        {hint && <span className={`block ${HINT}`}>{hint}</span>}
      </span>
    </label>
  );
}

function ActingSheet({ open, onClose, role, onDuty, acting, onChange }: Props & { open: boolean; onClose: () => void }) {
  const { api, label, testPrefix, idPrefix } = role;
  const [duration, setDuration] = useState<ActingDuration>('12h');
  const [customEnd, setCustomEnd] = useState('');
  const [busy, setBusy] = useState<'act' | 'end' | null>(null);
  const [error, setError] = useState<{ kind: 'act' | 'end'; text: string } | null>(null);
  const actAttempt = useRef<Attempt | null>(null);
  const endAttempt = useRef<Attempt | null>(null);
  const serverClock = useRef(new ServerClock(acting.server_time));
  useEffect(() => { serverClock.current.sync(acting.server_time); }, [acting.server_time]);
  // One idempotency key per opened form; it is reused while a retry sends the same thing.
  const actKey = useRef(newKey());
  const endKey = useRef(newKey());

  useEffect(() => {
    if (!open) return;
    setDuration('12h');
    setCustomEnd('');
    setBusy(null);
    setError(null);
    actAttempt.current = null;
    endAttempt.current = null;
    actKey.current = newKey();
    endKey.current = newKey();
  }, [open]);

  const fail = (kind: 'act' | 'end', failure: unknown, fallback: string, attempt: { current: Attempt | null }, key: { current: string }) => {
    if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
    // A refusal means nothing was stored, so the next try is a fresh request; an unknown outcome keeps the key.
    if (failure instanceof ApiRequestError && failure.status !== 0 && failure.status < 500 && failure.status !== 429) {
      key.current = newKey();
      attempt.current = null;
    }
    setError({ kind, text: requestFailureMessage(failure, fallback) });
  };

  const submitAct = async (event: FormEvent) => {
    event.preventDefault();
    if (busy) return;
    if (duration === 'custom' && customEnd === '') {
      setError({ kind: 'act', text: `Choose when you stop acting as ${label}.` });
      return;
    }
    const signature = duration === 'custom' ? `custom|${customEnd}` : duration;
    if (actAttempt.current && actAttempt.current.signature !== signature) actKey.current = newKey();
    actAttempt.current = { signature, key: actKey.current };
    setBusy('act');
    setError(null);
    try {
      const result = await api.requestActing({
        kind: 'act',
        duration,
        ...(duration === 'custom' ? { ends_at: customEnd } : {}),
        client_request_id: actKey.current,
      });
      actKey.current = newKey();
      actAttempt.current = null;
      onChange(result);
      onClose();
    } catch (failure) {
      fail('act', failure, 'Your request could not be sent. Try again.', actAttempt, actKey);
    } finally {
      setBusy(null);
    }
  };

  const submitEnd = async () => {
    if (busy) return;
    endAttempt.current = { signature: 'end', key: endKey.current };
    setBusy('end');
    setError(null);
    try {
      const result = await api.requestActing({ kind: 'end', client_request_id: endKey.current });
      endKey.current = newKey();
      endAttempt.current = null;
      onChange(result);
      onClose();
    } catch (failure) {
      fail('end', failure, 'Your request could not be sent. Try again.', endAttempt, endKey);
    } finally {
      setBusy(null);
    }
  };

  const now = serverClock.current.now();
  const showing = onDuty.source === 'acting' && onDuty.name;
  const shiftName = onDuty.shift ? `${onDuty.shift} Shift ${label}` : `usual ${label}`;
  const radioGroup = `${idPrefix}-acting-duration`;

  return (
    <Sheet open={open} onClose={onClose} title={`Acting as ${label} today?`} description={ACTING_EXPLANATION} testId={`${testPrefix}-acting-sheet`}>
      <div className="space-y-6">
        <form onSubmit={submitAct} noValidate className="space-y-4" aria-label={`Request to act as ${label}`}>
          {showing && (
            <p className="hub-callout p-3 text-sm" data-testid={`${testPrefix}-acting-takeover`}>
              {onDuty.name} is shown as acting {label}{onDuty.acting_until ? ` until ${dateTimeText(onDuty.acting_until)}` : ''}. If an administrator approves your request, your name replaces theirs.
            </p>
          )}
          <fieldset className="space-y-2" disabled={busy !== null}>
            <legend className={LABEL}>How long will you act as {label}?</legend>
            <DurationChoice name={radioGroup} value="12h" checked={duration === '12h'} title="12 hours" hint={acting.choices['12h'] ? `ends ${dateTimeText(acting.choices['12h'])}` : null} onSelect={setDuration} disabled={busy !== null} />
            <DurationChoice name={radioGroup} value="24h" checked={duration === '24h'} title="24 hours" hint={acting.choices['24h'] ? `ends ${dateTimeText(acting.choices['24h'])}` : null} onSelect={setDuration} disabled={busy !== null} />
            <DurationChoice name={radioGroup} value="custom" checked={duration === 'custom'} title="Custom end time" hint="Pick the date and time you stop." onSelect={setDuration} disabled={busy !== null} />
            {duration === 'custom' && (
              <div className="pl-1">
                <label className={LABEL} htmlFor={`${idPrefix}-acting-end`}>Stop acting at (Eastern)</label>
                <input
                  id={`${idPrefix}-acting-end`}
                  type="datetime-local"
                  className={`${FIELD} sm:max-w-xs`}
                  value={customEnd}
                  min={now === null ? undefined : hubDateInput(new Date(now + CUSTOM_MIN_MS), true)}
                  max={now === null ? undefined : hubDateInput(new Date(now + CUSTOM_MAX_MS), true)}
                  disabled={busy !== null}
                  onChange={(event) => { setCustomEnd(event.target.value); setError(null); }}
                />
                <p className={`${HINT} mt-1`}>Between 15 minutes and 72 hours from now.</p>
              </div>
            )}
          </fieldset>
          {error?.kind === 'act' && <p role="alert" data-testid={`${testPrefix}-acting-error`} className="hub-callout hub-callout--warning p-3 text-sm">{error.text}</p>}
          <button type="submit" className={BTN_PRIMARY} disabled={busy !== null} aria-busy={busy === 'act'}>
            {busy === 'act' ? 'Sending request…' : error?.kind === 'act' ? 'Try again' : `Request to act as ${label}`}
          </button>
        </form>

        {showing && (
          <section aria-labelledby={`${idPrefix}-acting-end-heading`} className="space-y-2 border-t border-hub-glass-border pt-4">
            <h3 id={`${idPrefix}-acting-end-heading`} className="font-heading text-base font-bold text-hub-ink">Not acting any more?</h3>
            <p className={HINT}>Once an administrator approves, the top of the page shows the {shiftName} again.</p>
            {error?.kind === 'end' && <p role="alert" data-testid={`${testPrefix}-acting-error`} className="hub-callout hub-callout--warning p-3 text-sm">{error.text}</p>}
            <button type="button" className={BTN_SECONDARY} disabled={busy !== null} aria-busy={busy === 'end'} onClick={() => void submitEnd()}>
              {busy === 'end' ? 'Sending request…' : `End the current acting ${label}`}
            </button>
          </section>
        )}
      </div>
    </Sheet>
  );
}

/** The viewer's own pending request, with a way to take it back. */
function MyRequest({ role, acting, onChange }: Pick<Props, 'role' | 'acting' | 'onChange'>) {
  const mine = acting.mine;
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!mine) return null;

  const withdraw = async () => {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      onChange(await role.api.withdrawActing(mine.id));
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setError(requestFailureMessage(failure, 'The request could not be withdrawn. Try again.'));
    } finally {
      setBusy(false);
    }
  };

  const text = mine.kind === 'end'
    ? `Your request to end the current acting ${role.label} is waiting for approval.`
    : `Your request to act as ${role.label}${mine.ends_at ? ` until ${dateTimeText(mine.ends_at)}` : ''} is waiting for approval.`;

  return (
    <div className="hub-callout space-y-2 p-3 text-sm" data-testid={`${role.testPrefix}-acting-mine`}>
      <p role="status">{text}</p>
      {error && <p role="alert" className="font-semibold text-hub-danger">{error}</p>}
      <button type="button" className={BTN_SECONDARY} disabled={busy} aria-busy={busy} onClick={() => void withdraw()}>{busy ? 'Withdrawing…' : 'Withdraw'}</button>
    </div>
  );
}

/**
 * "Acting as <role> today? Click here." under the shift line. Acting only changes the name shown;
 * every action on the page is recorded under the member's own name either way. A viewer the server
 * says cannot ask (`can_request` false) gets no control, but still sees and can withdraw their own request.
 */
export default function ActingControl({ role, onDuty, acting, onChange }: Props) {
  const [open, setOpen] = useState(false);
  const openButton = useRef<HTMLButtonElement>(null);
  const wasOpen = useRef(false);
  // The sheet has no Radix trigger, so give focus back to the button that opened it when it closes.
  useEffect(() => {
    if (wasOpen.current && !open) openButton.current?.focus();
    wasOpen.current = open;
  }, [open]);
  const canRequest = acting.can_request !== false;
  if (!canRequest && !acting.mine) return null;
  return (
    <div className="space-y-2" data-testid={`${role.testPrefix}-acting`}>
      <MyRequest role={role} acting={acting} onChange={onChange} />
      {canRequest && (
        <>
          <button ref={openButton} type="button" data-testid={`${role.testPrefix}-acting-open`} className={`${BTN_SECONDARY} max-w-full !whitespace-normal py-2 text-center`} onClick={() => setOpen(true)}>
            Acting as {role.label} today? Click here.
          </button>
          <ActingSheet open={open} onClose={() => setOpen(false)} role={role} onDuty={onDuty} acting={acting} onChange={onChange} />
        </>
      )}
    </div>
  );
}
