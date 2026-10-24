import { useRef, useState } from 'react';
import { useOffline } from '../../hooks/useOffline';
import type { VehicleIdentification, VehicleIdentificationAnswers } from '../../types';
import { ApiClient, ApiRequestError, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { createClientSubmissionId } from '../../utils/storage';
import { firstIncompleteField, toVehicleIdentificationSubmission } from '../../utils/vehicleIdentification';
import VehicleIdentificationCard, { focusIdentificationField } from '../VehicleIdentificationCard';

interface Props {
  apparatusId: number;
  block: VehicleIdentification;
  /** The refreshed block after a save; undefined when nothing is open any more. */
  onSaved: (next: VehicleIdentification | undefined) => void;
}

const UNREACHABLE = 'The server could not be reached, so nothing is confirmed. Try again.';

/**
 * Captain 5 has no Daily Checkout, so the one-time vehicle questions are answered here and saved on their
 * own. A save that fails keeps the answers; sending the same answers again reuses the same
 * client_submission_id, so the server counts a duplicate once.
 */
export default function VehicleIdentificationPanel({ apparatusId, block, onSaved }: Props) {
  const isOffline = useOffline();
  const rootRef = useRef<HTMLDivElement>(null);
  const attempt = useRef<{ id: string; key: string } | null>(null);
  const [answers, setAnswers] = useState<VehicleIdentificationAnswers>({});
  const [showErrors, setShowErrors] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const save = async () => {
    const missing = firstIncompleteField(block, answers);
    const submission = toVehicleIdentificationSubmission(block, answers);
    if (missing || !submission) {
      setShowErrors(true);
      if (missing) focusIdentificationField(rootRef.current, missing);
      return;
    }
    // The id stays the same until a save succeeds, unless the answers changed since the last try.
    const key = JSON.stringify(submission);
    if (attempt.current?.key !== key) attempt.current = { id: createClientSubmissionId(), key };
    setSaving(true);
    setError(null);
    try {
      const next = await ApiClient.submitVehicleIdentification(apparatusId, { client_submission_id: attempt.current.id, ...submission });
      attempt.current = null;
      setAnswers({});
      setShowErrors(false);
      onSaved(next);
    } catch (failure) {
      if (isApiAuthenticationError(failure)) {
        redirectToLoginAfterSessionExpiry();
        return;
      }
      setError(failure instanceof ApiRequestError && failure.message !== '' ? failure.message : UNREACHABLE);
    } finally {
      setSaving(false);
    }
  };

  return <div ref={rootRef} data-testid="captain5-vehicle-identification" className="space-y-3">
    <VehicleIdentificationCard block={block} answers={answers} onChange={setAnswers} showErrors={showErrors} disabled={saving} />
    {isOffline && <p className="text-sm text-hub-n-600">You are offline. Connect to save this.</p>}
    {error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{error}</p>}
    <button
      type="button"
      className="hub-btn hub-btn--primary hub-btn--wrap !min-h-11 w-full sm:w-auto"
      disabled={saving || isOffline}
      aria-busy={saving}
      onClick={() => void save()}
    >
      {saving ? 'Saving…' : 'Save vehicle information'}
    </button>
  </div>;
}
