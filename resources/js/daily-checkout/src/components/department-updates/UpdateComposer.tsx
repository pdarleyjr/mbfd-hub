import { useEffect, useId, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { CircleCheck, WifiOff } from 'lucide-react';
import { useOffline } from '../../hooks/useOffline';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { DepartmentUpdateError, postDepartmentUpdate } from '../../utils/departmentUpdatesApi';
import { newKey, todayYmd } from '../captain5/format';
import {
  ATTACHMENT_ACCEPT, BODY_MAX, CATEGORY_OPTIONS, DEFAULT_CATEGORY, IMAGE_ACCEPT, MAX_EXPIRY_DAYS, TITLE_MAX,
  attachmentProblem, fileSizeText, imageProblem,
  type PostedUpdate, type UpdateCategory, type UpdateOrigin,
} from './types';

const FIELD = 'w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 text-base text-hub-ink placeholder:text-hub-n-500 min-h-11';
const FILE_FIELD = `${FIELD} file:mr-3 file:min-h-9 file:rounded-hub-control file:border-0 file:bg-hub-blue-tint file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-hub-blue`;
const LABEL = 'mb-1 block text-sm font-semibold text-hub-ink';
const HINT = 'text-[13px] leading-5 text-hub-n-600';
const BTN = 'hub-btn !min-h-11';

interface Draft { title: string; body: string; category: UpdateCategory; expiresOn: string }
const EMPTY_DRAFT: Draft = { title: '', body: '', category: DEFAULT_CATEGORY, expiresOn: '' };
const draftKey = (origin: UpdateOrigin) => `mbfd-department-update-draft:${origin}`;

/** Text only: a browser cannot restore a chosen file. Storage can be blocked, so every access is guarded. */
function readDraft(origin: UpdateOrigin): Draft {
  try {
    const raw = sessionStorage.getItem(draftKey(origin));
    if (!raw) return EMPTY_DRAFT;
    const saved = JSON.parse(raw) as Partial<Draft>;
    return {
      title: typeof saved.title === 'string' ? saved.title : '',
      body: typeof saved.body === 'string' ? saved.body : '',
      category: CATEGORY_OPTIONS.some((option) => option.value === saved.category) ? saved.category as UpdateCategory : DEFAULT_CATEGORY,
      expiresOn: typeof saved.expiresOn === 'string' ? saved.expiresOn : '',
    };
  } catch {
    return EMPTY_DRAFT;
  }
}

function writeDraft(origin: UpdateOrigin, draft: Draft | null): void {
  try {
    if (draft === null || (draft.title === '' && draft.body === '' && draft.expiresOn === '')) sessionStorage.removeItem(draftKey(origin));
    else sessionStorage.setItem(draftKey(origin), JSON.stringify(draft));
  } catch {
    // The draft still lives on screen; only the cross-tab copy is lost.
  }
}

const addDays = (ymd: string, days: number): string => {
  const [y, m, d] = ymd.split('-').map(Number);
  const date = new Date(y, m - 1, d + days);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

type FieldName = 'title' | 'body' | 'category' | 'expires_at' | 'image' | 'attachment';

function FieldError({ id, text }: { id: string; text?: string }) {
  return text ? <p id={id} role="alert" className="mt-1 text-[13px] font-semibold text-hub-danger">{text}</p> : null;
}

interface Props {
  origin: UpdateOrigin;
  onPosted: (update: PostedUpdate) => void;
}

/** Title, plain-text body, category, optional end date, optional picture and optional document. */
export default function UpdateComposer({ origin, onPosted }: Props) {
  const ids = useId();
  const offline = useOffline();
  const [draft, setDraft] = useState<Draft>(() => readDraft(origin));
  const [image, setImage] = useState<File | null>(null);
  const [attachment, setAttachment] = useState<File | null>(null);
  const [fileKey, setFileKey] = useState(0);
  const [errors, setErrors] = useState<Partial<Record<FieldName, string>>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState(0);
  const [posted, setPosted] = useState<PostedUpdate | null>(null);
  // The same key goes out again while a retry sends the same thing, so a lost answer never posts twice.
  const attempt = useRef<{ signature: string; key: string } | null>(null);
  const keyRef = useRef(newKey());

  useEffect(() => { writeDraft(origin, draft); }, [origin, draft]);

  const change = (patch: Partial<Draft>) => { setDraft((current) => ({ ...current, ...patch })); setPosted(null); setFailure(null); };
  const tomorrow = addDays(todayYmd(), 1);
  const lastDay = addDays(todayYmd(), MAX_EXPIRY_DAYS);

  const chooseImage = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0] ?? null;
    const problem = file ? imageProblem(file) : null;
    setImage(problem ? null : file);
    setErrors((current) => ({ ...current, image: problem ?? undefined }));
    if (problem) { event.target.value = ''; }
    setPosted(null);
  };
  const chooseAttachment = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0] ?? null;
    const problem = file ? attachmentProblem(file) : null;
    setAttachment(problem ? null : file);
    setErrors((current) => ({ ...current, attachment: problem ?? undefined }));
    if (problem) { event.target.value = ''; }
    setPosted(null);
  };

  const validate = (): Partial<Record<FieldName, string>> => {
    const found: Partial<Record<FieldName, string>> = {};
    if (draft.title.trim() === '') found.title = 'Add a title.';
    if (draft.body.trim() === '') found.body = 'Write the update.';
    if (draft.expiresOn !== '' && (draft.expiresOn < tomorrow || draft.expiresOn > lastDay)) found.expires_at = 'Choose a day after today, within the next 4 months.';
    return found;
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (busy) return;
    const found = validate();
    setErrors(found);
    setFailure(null);
    if (Object.keys(found).length > 0) return;

    const signature = JSON.stringify([draft, image?.name, image?.size, attachment?.name, attachment?.size]);
    if (attempt.current && attempt.current.signature !== signature) keyRef.current = newKey();
    attempt.current = { signature, key: keyRef.current };
    setBusy(true);
    setProgress(0);
    try {
      const update = await postDepartmentUpdate(origin, {
        title: draft.title, body: draft.body, category: draft.category, expiresOn: draft.expiresOn,
        image, attachment, clientRequestId: keyRef.current,
      }, setProgress);
      keyRef.current = newKey();
      attempt.current = null;
      setDraft(EMPTY_DRAFT);
      setImage(null);
      setAttachment(null);
      setFileKey((n) => n + 1);
      setPosted(update);
      onPosted(update);
    } catch (error) {
      if (isApiAuthenticationError(error)) { redirectToLoginAfterSessionExpiry(); return; }
      if (error instanceof DepartmentUpdateError && error.status !== 0 && error.status < 500 && error.status !== 429) {
        // The server refused it, so nothing was stored: the next try is a new request.
        keyRef.current = newKey();
        attempt.current = null;
      }
      if (error instanceof DepartmentUpdateError && Object.keys(error.fieldErrors).length > 0) {
        setErrors(error.fieldErrors as Partial<Record<FieldName, string>>);
        setFailure('Check the highlighted fields and post again.');
      } else {
        setFailure(error instanceof Error ? error.message : 'The update could not be posted. Try again.');
      }
    } finally {
      setBusy(false);
    }
  };

  const percent = Math.round(progress * 100);
  const hasFiles = image !== null || attachment !== null;

  return (
    <form onSubmit={submit} noValidate className="space-y-4" aria-label="Post to Department Updates" data-testid="department-update-form">
      {offline && (
        <div className="hub-callout hub-callout--warning flex items-center gap-2 p-3 text-sm" role="status">
          <WifiOff className="h-4 w-4 flex-none" aria-hidden="true" />
          <span>Requires connection. Updates cannot be posted while you are offline. What you write is kept.</span>
        </div>
      )}

      <div>
        <label className={LABEL} htmlFor={`${ids}-title`}>Title</label>
        <input
          id={`${ids}-title`} type="text" className={FIELD} value={draft.title} maxLength={TITLE_MAX} disabled={busy} autoComplete="off"
          aria-invalid={errors.title ? true : undefined} aria-describedby={errors.title ? `${ids}-title-error` : undefined}
          onChange={(event) => change({ title: event.target.value })}
        />
        <FieldError id={`${ids}-title-error`} text={errors.title} />
      </div>

      <div>
        <label className={LABEL} htmlFor={`${ids}-body`}>Details</label>
        <textarea
          id={`${ids}-body`} rows={6} className={FIELD} value={draft.body} maxLength={BODY_MAX} disabled={busy}
          aria-invalid={errors.body ? true : undefined} aria-describedby={`${ids}-body-hint${errors.body ? ` ${ids}-body-error` : ''}`}
          onChange={(event) => change({ body: event.target.value })}
        />
        <p id={`${ids}-body-hint`} className={`${HINT} mt-1`}>Plain text. Leave a blank line between paragraphs.</p>
        <FieldError id={`${ids}-body-error`} text={errors.body} />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className={LABEL} htmlFor={`${ids}-category`}>Category</label>
          <select
            id={`${ids}-category`} className={FIELD} value={draft.category} disabled={busy}
            aria-invalid={errors.category ? true : undefined} aria-describedby={errors.category ? `${ids}-category-error` : undefined}
            onChange={(event) => change({ category: event.target.value as UpdateCategory })}
          >
            {CATEGORY_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
          <FieldError id={`${ids}-category-error`} text={errors.category} />
        </div>
        <div>
          <label className={LABEL} htmlFor={`${ids}-expires`}>Remove from the home page after</label>
          <input
            id={`${ids}-expires`} type="date" className={FIELD} value={draft.expiresOn} min={tomorrow} max={lastDay} disabled={busy}
            aria-invalid={errors.expires_at ? true : undefined} aria-describedby={`${ids}-expires-hint${errors.expires_at ? ` ${ids}-expires-error` : ''}`}
            onChange={(event) => change({ expiresOn: event.target.value })}
          />
          <p id={`${ids}-expires-hint`} className={`${HINT} mt-1`}>Optional. Without a date it stays until you take it down.</p>
          <FieldError id={`${ids}-expires-error`} text={errors.expires_at} />
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className={LABEL} htmlFor={`${ids}-image`}>Picture (optional)</label>
          <input
            key={`image-${fileKey}`} id={`${ids}-image`} type="file" accept={IMAGE_ACCEPT} className={FILE_FIELD} disabled={busy}
            aria-invalid={errors.image ? true : undefined} aria-describedby={`${ids}-image-hint${errors.image ? ` ${ids}-image-error` : ''}`}
            onChange={chooseImage}
          />
          <p id={`${ids}-image-hint`} className={`${HINT} mt-1`}>
            {image ? `${image.name} (${fileSizeText(image.size)})` : 'JPEG, PNG or WebP, up to 5 MB.'}
          </p>
          <FieldError id={`${ids}-image-error`} text={errors.image} />
        </div>
        <div>
          <label className={LABEL} htmlFor={`${ids}-file`}>File (optional)</label>
          <input
            key={`file-${fileKey}`} id={`${ids}-file`} type="file" accept={ATTACHMENT_ACCEPT} className={FILE_FIELD} disabled={busy}
            aria-invalid={errors.attachment ? true : undefined} aria-describedby={`${ids}-file-hint${errors.attachment ? ` ${ids}-file-error` : ''}`}
            onChange={chooseAttachment}
          />
          <p id={`${ids}-file-hint`} className={`${HINT} mt-1`}>
            {attachment ? `${attachment.name} (${fileSizeText(attachment.size)})` : 'For example a schedule. PDF, DOCX, XLSX, PPTX, TXT or CSV, up to 15 MB.'}
          </p>
          <FieldError id={`${ids}-file-error`} text={errors.attachment} />
        </div>
      </div>

      {failure && <p role="alert" data-testid="department-update-error" className="hub-callout hub-callout--warning p-3 text-sm">{failure}</p>}

      {busy && hasFiles && (
        <div role="status" className="space-y-1">
          <progress className="block h-2 w-full" max={100} value={percent} aria-label="Upload progress" />
          <p className={HINT}>Uploading {percent}%</p>
        </div>
      )}

      <button type="submit" className={`${BTN} hub-btn--primary`} disabled={busy} aria-busy={busy}>
        {busy ? 'Posting…' : failure ? 'Try posting again' : 'Post to Department Updates'}
      </button>

      {posted && (
        <div className="hub-callout flex items-start gap-2 p-3 text-sm" role="status" data-testid="department-update-success">
          <CircleCheck className="mt-0.5 h-5 w-5 flex-none text-hub-success" aria-hidden="true" />
          <div className="min-w-0">
            <p className="font-semibold text-hub-ink">Posted. Everyone in the department can now see it on the home page.</p>
            {posted.url && (
              <a href={posted.url} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 items-center font-semibold text-hub-blue underline underline-offset-4">
                View the update<span className="sr-only"> (opens in a new tab)</span>
              </a>
            )}
          </div>
        </div>
      )}
    </form>
  );
}
