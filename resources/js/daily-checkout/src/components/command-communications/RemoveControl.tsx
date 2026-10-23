import { useId, useState, type FormEvent } from 'react';
import { Trash2 } from 'lucide-react';
import type { CommandPost } from './types';

const SMALL_ACTION = 'hub-btn hub-btn--tertiary hub-btn--compact !min-h-11 gap-1.5';
const FIELD = 'w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 text-base text-hub-ink placeholder:text-hub-n-500';
const MIN_REASON = 3;

interface RemoveControlProps {
  post: CommandPost;
  /** Resolves null once the message is removed, else the reason it could not be. */
  onRemove: (post: CommandPost, reason: string) => Promise<string | null>;
}

/** "Remove" for the author's own message (or any message for an administrator), with a required reason. */
export default function RemoveControl({ post, onRemove }: RemoveControlProps) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const fieldId = useId();
  const name = post.author.name;

  if (!open) {
    return (
      <button type="button" className={SMALL_ACTION} onClick={() => setOpen(true)} aria-label={`Remove message from ${name}`}>
        <Trash2 className="h-4 w-4" aria-hidden="true" />Remove
      </button>
    );
  }

  const cancel = () => { setOpen(false); setReason(''); setError(null); };
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (reason.trim().length < MIN_REASON) { setError('Say why this message is being removed.'); return; }
    setBusy(true);
    setError(null);
    const failure = await onRemove(post, reason.trim());
    if (failure !== null) { setBusy(false); setError(failure); }
  };

  return (
    <form className="w-full space-y-2" onSubmit={(event) => void submit(event)} aria-label={`Remove message from ${name}`} data-testid="command-remove-form">
      <p className="text-sm text-hub-ink">
        Everyone will see that this message was removed, but not what it said. The reason is kept in the record.
      </p>
      <label htmlFor={fieldId} className="block text-[13px] font-semibold text-hub-n-600">Why is it being removed?</label>
      <textarea id={fieldId} rows={2} maxLength={500} className={`${FIELD} block min-h-[3.25rem] resize-none`} value={reason} onChange={(event) => setReason(event.target.value)} />
      {error && <p role="alert" className="text-sm text-hub-danger">{error}</p>}
      <div className="flex flex-wrap gap-2">
        <button type="submit" className="hub-btn hub-btn--destructive !min-h-11 gap-2" disabled={busy} aria-busy={busy}>
          <Trash2 className="h-4 w-4" aria-hidden="true" />{busy ? 'Removing' : 'Remove message'}
        </button>
        <button type="button" className="hub-btn hub-btn--secondary !min-h-11" onClick={cancel} disabled={busy}>Keep it</button>
      </div>
    </form>
  );
}
