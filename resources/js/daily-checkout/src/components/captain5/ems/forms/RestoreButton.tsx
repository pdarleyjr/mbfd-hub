import { BTN_SECONDARY, LiveNote, useWrite } from '../shared';
import { restoreForm } from './api';
import { RESTORE_REASON } from './logic';
import type { FormDetail, FormSummary } from './types';

interface Props {
  form: Pick<FormSummary, 'id' | 'number' | 'target_label'>;
  /** Restored: the server's fresh answer (the form is back in Active). */
  onRestored: (detail: FormDetail) => void;
  onConflict: () => void;
  testId?: string;
}

/** Brings a cleared form back to Active, still acknowledged. One tap; the history records who and when. */
export default function RestoreButton({ form, onRestored, onConflict, testId = 'ems-form-restore' }: Props) {
  const write = useWrite();
  const label = write.busy ? 'Restoring…' : write.retry ? 'Retry: Restore' : 'Restore';
  const restore = () => {
    void write.run(`restore:${form.id}`, (key) => restoreForm(form.id, RESTORE_REASON, key), { onDone: onRestored, onConflict });
  };
  return (
    <div className="space-y-2">
      <button type="button" data-testid={testId} className={BTN_SECONDARY} disabled={write.busy} aria-busy={write.busy}
        aria-label={`${label} ${form.target_label} form ${form.number} to Active`} onClick={restore}>
        {label}
      </button>
      <LiveNote tone="warning">{write.error}</LiveNote>
    </div>
  );
}
