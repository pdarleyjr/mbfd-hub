import { useEffect, useId, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { Link } from 'react-router';
import type { Apparatus } from '../../types';
import { useOffline } from '../../hooks/useOffline';
import { setUnsavedWork } from '../../pwa/draftBusy.ts';
import {
  MemberIssueActionError, STRETCHER_TEXT_MAX, newClientId, submitStretcherIssue,
  type StretcherIssueResult, type StretcherProblem,
} from '../../utils/memberIssueActionsApi';
import {
  STRETCHER_PROBLEMS, stretcherPhotoProblem, validateStretcher, type StretcherFieldErrors,
} from './stretcherForm';
import './findings.css';
import './memberActions.css';

const OFFLINE_NOTICE = "You're offline. This can't be sent until you are back online. What you entered is kept.";
const SEND_LABEL = 'Send to Captain 5';

interface Props {
  apparatus: Apparatus;
  /** Opens the unit list. The form stays mounted underneath, so nothing typed is lost. */
  onChangeUnit: () => void;
}

/**
 * Report a stretcher problem on one unit. One client_submission_id per form: a retry after a dropped answer sends
 * the same id, so the server returns the first result instead of recording the report twice.
 */
/** A preview URL from URL.createObjectURL, accepted only when it really is a blob: URL. */
function safeBlobUrl(url: string): string | null {
  try {
    return new URL(url).protocol === 'blob:' ? url : null;
  } catch {
    return null;
  }
}

export default function StretcherIssueForm({ apparatus, onChangeUnit }: Props) {
  const id = useId();
  const offline = useOffline();
  const busyToken = useRef(Symbol('stretcher-report'));
  const submission = useRef({ apparatusId: apparatus.id, id: newClientId() });
  const sentRegion = useRef<HTMLElement>(null);
  const [problem, setProblem] = useState<StretcherProblem | null>(null);
  const [description, setDescription] = useState('');
  const [unusable, setUnusable] = useState(false);
  const [photo, setPhoto] = useState<File | null>(null);
  const [photoKey, setPhotoKey] = useState(0);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [photoProblem, setPhotoProblem] = useState<string | null>(null);
  const [errors, setErrors] = useState<StretcherFieldErrors>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState<StretcherIssueResult | null>(null);

  const dirty = problem !== null || description.trim() !== '' || unusable || photo !== null;

  // An update must not reload the page over a half-written report or one that is being sent.
  useEffect(() => {
    setUnsavedWork(busyToken.current, sent === null && (dirty || sending));
  }, [dirty, sending, sent]);
  useEffect(() => {
    const token = busyToken.current;
    return () => setUnsavedWork(token, false);
  }, []);

  useEffect(() => {
    if (photo === null || typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') {
      setPreviewUrl(null);
      return undefined;
    }
    const url = URL.createObjectURL(photo);
    // Only a same-document blob: URL may become an <img src>; anything else is dropped.
    setPreviewUrl(safeBlobUrl(url));
    return () => URL.revokeObjectURL(url);
  }, [photo]);

  useEffect(() => {
    if (sent !== null) sentRegion.current?.focus();
  }, [sent]);

  const submissionId = (): string => {
    // A different unit is a different report, so it never reuses the id of an attempt on another unit.
    if (submission.current.apparatusId !== apparatus.id) submission.current = { apparatusId: apparatus.id, id: newClientId() };

    return submission.current.id;
  };

  const choosePhoto = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0] ?? null;
    if (file === null) return;
    const found = stretcherPhotoProblem(file);
    setPhotoProblem(found);
    if (found !== null) {
      // Not attached: the input is cleared so the same file can be chosen again after it is fixed.
      setPhoto(null);
      setPhotoKey(key => key + 1);
      return;
    }
    setPhoto(file);
  };

  const removePhoto = () => {
    setPhoto(null);
    setPhotoProblem(null);
    setPhotoKey(key => key + 1);
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (sending || sent !== null) return;
    const found = validateStretcher({ problem, description });
    setErrors(found);
    if (found.problem) {
      document.getElementById(`${id}-problem-${STRETCHER_PROBLEMS[0].value}`)?.focus();
      return;
    }
    if (found.description) {
      document.getElementById(`${id}-description`)?.focus();
      return;
    }
    if (problem === null) return;
    setFailure(null);
    setSending(true);
    submitStretcherIssue(apparatus.id, { clientSubmissionId: submissionId(), problem, description, unusable, photo }).then((result) => {
      setSent(result);
    }).catch((error: unknown) => {
      const known = error instanceof MemberIssueActionError ? error : null;
      const fields = known?.fieldErrors ?? {};
      const mapped: StretcherFieldErrors = {
        ...(fields.problem ? { problem: fields.problem } : {}),
        ...(fields.description ? { description: fields.description } : {}),
      };
      setErrors(mapped);
      if (fields.photo) setPhotoProblem(fields.photo);
      const fieldOnly = Object.keys(mapped).length > 0 || Boolean(fields.photo);
      setFailure(fieldOnly ? null : known ? known.message : 'This could not be sent. What you entered is kept; try again.');
    }).finally(() => setSending(false));
  };

  const startAnother = () => {
    submission.current = { apparatusId: apparatus.id, id: newClientId() };
    setProblem(null);
    setDescription('');
    setUnusable(false);
    setPhoto(null);
    setPhotoKey(key => key + 1);
    setPhotoProblem(null);
    setErrors({});
    setFailure(null);
    setSent(null);
  };

  if (sent !== null) {
    return (
      <section ref={sentRegion} tabIndex={-1} className="stretcher-success" role="status" aria-live="polite" data-testid="stretcher-sent">
        <h2>Report sent</h2>
        <p>{sent.message}</p>
        <div className="stretcher-actions">
          <Link className="hub-btn hub-btn--primary" to={`/vehicle-inspections/${apparatus.slug}/issues`}>Back to unit issues</Link>
          <button type="button" className="hub-btn hub-btn--secondary" onClick={startAnother}>Report another</button>
        </div>
      </section>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="stretcher-form" aria-label="Report stretcher issue" data-hub-ui="2">
      <div className="stretcher-unit">
        <div>
          <span className="stretcher-unit__label">Unit</span>
          <strong className="stretcher-unit__name" data-testid="stretcher-unit">{apparatus.name}</strong>
        </div>
        <button type="button" className="hub-btn hub-btn--tertiary" aria-label="Change unit" disabled={sending} onClick={onChangeUnit}>Change</button>
      </div>

      <fieldset aria-describedby={errors.problem ? `${id}-problem-error` : undefined} disabled={sending}>
        <legend>What kind of problem is it? <span className="stretcher-req">(required)</span></legend>
        <div className="stretcher-chips">
          {STRETCHER_PROBLEMS.map(choice => (
            <label key={choice.value} className="stretcher-chip">
              <input
                id={`${id}-problem-${choice.value}`}
                type="radio"
                name={`${id}-problem`}
                value={choice.value}
                checked={problem === choice.value}
                onChange={() => { setProblem(choice.value); setErrors(current => ({ ...current, problem: undefined })); }}
              />
              <span>{choice.label}</span>
            </label>
          ))}
        </div>
        {errors.problem && <p id={`${id}-problem-error`} role="alert" className="stretcher-error">{errors.problem}</p>}
      </fieldset>

      <div className="stretcher-field">
        <label htmlFor={`${id}-description`}>What is wrong? <span className="stretcher-req">(required)</span></label>
        <textarea
          id={`${id}-description`}
          rows={4}
          maxLength={STRETCHER_TEXT_MAX}
          value={description}
          disabled={sending}
          aria-invalid={errors.description ? true : undefined}
          aria-describedby={`${id}-description-count${errors.description ? ` ${id}-description-error` : ''}`}
          onChange={(event) => { setDescription(event.target.value); setErrors(current => ({ ...current, description: undefined })); }}
        />
        <p id={`${id}-description-count`} className="stretcher-hint">{description.length} of {STRETCHER_TEXT_MAX} characters</p>
        {errors.description && <p id={`${id}-description-error`} role="alert" className="stretcher-error">{errors.description}</p>}
      </div>

      <div className="stretcher-photo">
        <label htmlFor={`${id}-photo`}>Photo (optional)</label>
        <input
          key={photoKey}
          id={`${id}-photo`}
          type="file"
          accept="image/*"
          capture="environment"
          disabled={sending}
          aria-describedby={photoProblem ? `${id}-photo-error` : undefined}
          onChange={choosePhoto}
        />
        {photoProblem && <p id={`${id}-photo-error`} role="alert" className="stretcher-error">{photoProblem}</p>}
        {photo && (
          <div className="stretcher-photo__preview">
            {previewUrl && <img src={previewUrl} alt="Photo you chose to send" />}
            <button type="button" className="hub-btn hub-btn--secondary" disabled={sending} onClick={removePhoto}>Remove photo</button>
          </div>
        )}
      </div>

      <label className={`stretcher-toggle${unusable ? ' is-on' : ''}`}>
        <input
          type="checkbox"
          role="switch"
          checked={unusable}
          disabled={sending}
          aria-labelledby={`${id}-unusable-label`}
          aria-describedby={`${id}-unusable-hint`}
          onChange={event => setUnusable(event.target.checked)}
        />
        <span className="stretcher-toggle__text">
          <strong id={`${id}-unusable-label`}>The stretcher can't be used right now</strong>
          <span id={`${id}-unusable-hint`} className="stretcher-hint">Captain 5 and the EMS Captain are told right away.</span>
        </span>
      </label>

      {offline && <p role="status" className="stretcher-banner is-warn" data-testid="stretcher-offline">{OFFLINE_NOTICE}</p>}
      <div aria-live="assertive">
        {failure && <p role="alert" className="stretcher-banner is-error" data-testid="stretcher-error">{failure}</p>}
      </div>

      <div className="stretcher-actions">
        <button type="submit" className="hub-btn hub-btn--primary" disabled={sending} aria-busy={sending}>
          {sending ? 'Sending...' : failure ? `Retry: ${SEND_LABEL}` : SEND_LABEL}
        </button>
      </div>
    </form>
  );
}
