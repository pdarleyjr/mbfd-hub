import { useRef } from 'react';
import type { ChecklistItem, Compartment } from '../types';
import { createClientSubmissionId } from '../lib/sync';
import {
  ELSEWHERE, ITEM_REASONS, NOTE_MAX, PHOTO_MAX_BYTES, QUANTITY_MAX, QUEUED_TEXT, SENT_TEXT,
  buildCorrectionBody, correctionErrorMessage, emptyCorrectionForm, sendInventoryCorrection, validateCorrection,
  type CorrectionErrors, type CorrectionForm, type CorrectionMode, type InventoryCorrectionContext,
} from '../utils/inventoryCorrections';

interface Props {
  mode: CorrectionMode;
  /** Unique within the page; prefixes every control id. */
  fieldId: string;
  compartment: Compartment;
  compartments: Compartment[];
  item?: ChecklistItem;
  form: CorrectionForm;
  onForm: (patch: Partial<CorrectionForm>) => void;
  context: InventoryCorrectionContext;
}

const HINT = 'Still record what you see today. Use Missing if it should be here but isn\'t.';

/**
 * "Report inventory correction" (one item) and "Report an unlisted item" (one compartment): a quiet disclosure
 * button that opens an inline panel. The report goes to Logistics; it never changes today's observation.
 */
export default function InventoryCorrectionForm({ mode, fieldId, compartment, compartments, item, form, onForm, context }: Props) {
  const toggleRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const sendingRef = useRef(false);
  const isItem = mode === 'item';
  const panelId = `${fieldId}-panel`;
  const errors: CorrectionErrors = form.showErrors ? validateCorrection(mode, form) : {};
  const reason = isItem ? form.reason : 'new_item';
  const otherCompartments = compartments.filter(entry => entry.id !== compartment.id);
  const showPhoto = reason === 'replaced' || reason === 'new_item';
  const showDetails = reason === 'replaced' || reason === 'new_item';
  const statusText = form.result === 'submitted' ? SENT_TEXT : form.result === 'queued' ? QUEUED_TEXT : '';

  const fieldProps = (key: keyof CorrectionErrors, id: string) => ({
    id,
    'aria-invalid': errors[key] ? true as const : undefined,
    'aria-describedby': errors[key] ? `${id}-error` : undefined,
  });
  const fieldError = (key: keyof CorrectionErrors, id: string) => errors[key] ? <p id={`${id}-error`} role="alert" className="ic-error">{errors[key]}</p> : null;

  const attachPhoto = (file?: File) => {
    if (!file) return;
    if (!file.type.startsWith('image/') || file.size > PHOTO_MAX_BYTES) {
      onForm({ error: 'Choose an image smaller than 5 MB. What you typed is still here.', photoKey: form.photoKey + 1 });
      return;
    }
    const reader = new FileReader();
    reader.onload = () => onForm({ photo: String(reader.result), error: null });
    reader.onerror = () => onForm({ error: 'The image could not be read. Choose it again.', photoKey: form.photoKey + 1 });
    reader.readAsDataURL(file);
  };

  const send = async () => {
    // A second tap before React re-renders must not send the report twice.
    if (form.sending || sendingRef.current) return;
    if (Object.keys(validateCorrection(mode, form)).length > 0) {
      onForm({ showErrors: true, error: null });
      requestAnimationFrame(() => panelRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
      return;
    }
    const body = buildCorrectionBody({ mode, form, checklistVersion: context.checklistVersion, compartmentId: compartment.id, itemId: item?.id, draftId: context.draftId });
    const fingerprint = JSON.stringify(body);
    // One id per distinct report, so a retry of the same payload is idempotent on the server.
    const id = form.attempt?.fingerprint === fingerprint ? form.attempt.id : createClientSubmissionId();
    sendingRef.current = true;
    onForm({ sending: true, error: null, attempt: { id, fingerprint } });
    try {
      const outcome = await sendInventoryCorrection(context.apparatusId, { client_submission_id: id, ...body });
      context.onRecorded({ compartmentId: compartment.id, itemId: isItem ? item?.id ?? null : null, kind: 'pending' });
      onForm({ ...emptyCorrectionForm(item?.expectedQuantity), photoKey: form.photoKey + 1, result: outcome });
      requestAnimationFrame(() => toggleRef.current?.focus());
    } catch (error) {
      onForm({ sending: false, error: correctionErrorMessage(error) });
    } finally {
      sendingRef.current = false;
    }
  };

  const text = (key: 'name' | 'manufacturer' | 'model' | 'description', label: string, max: number, error?: keyof CorrectionErrors) => {
    const id = `${fieldId}-${key}`;
    return <>
      <label htmlFor={id}>{label}</label>
      <input {...(error ? fieldProps(error, id) : { id })} type="text" maxLength={max} autoComplete="off" value={form[key]} onChange={event => onForm({ [key]: event.target.value })} />
      {error && fieldError(error, id)}
    </>;
  };

  return <div className="inventory-correction" data-inventory-correction={mode}>
    <button ref={toggleRef} type="button" className="hub-btn hub-btn--tertiary" aria-expanded={form.open} aria-controls={panelId}
      onClick={() => onForm({ open: !form.open, ...(form.open ? {} : { result: null }) })}>
      {isItem ? 'Report inventory correction' : 'Report an unlisted item'}
    </button>
    <p role="status" className="ic-status">{statusText}</p>
    {form.open && <div id={panelId} ref={panelRef} className="ic-panel" role="group" aria-label={isItem ? `Inventory correction for ${item?.name ?? 'item'}` : `Unlisted item in ${compartment.name}`}>
      <p className="ic-hint">{isItem ? HINT : `Tell Logistics about equipment carried in ${compartment.name} that is not on this list.`}</p>

      {isItem && <fieldset className="ic-reasons">
        <legend>What is wrong with the list?</legend>
        {ITEM_REASONS.map((option, index) => <label key={option.value} className="ic-option">
          <input type="radio" name={`${fieldId}-reason`} value={option.value} checked={form.reason === option.value}
            aria-invalid={index === 0 && errors.reason ? true : undefined} aria-describedby={errors.reason ? `${fieldId}-reason-error` : undefined}
            onChange={() => onForm({ reason: option.value, error: null })} />
          <span>{option.label}</span>
        </label>)}
        {errors.reason && <p id={`${fieldId}-reason-error`} role="alert" className="ic-error">{errors.reason}</p>}
      </fieldset>}

      {reason === 'moved' && <>
        <label htmlFor={`${fieldId}-to`}>Where is it carried now?</label>
        <select {...fieldProps('toCompartmentId', `${fieldId}-to`)} value={form.toCompartmentId} onChange={event => onForm({ toCompartmentId: event.target.value })}>
          <option value="">Choose a compartment</option>
          {otherCompartments.map(entry => <option key={entry.id} value={entry.id}>{entry.name}</option>)}
          <option value={ELSEWHERE}>Somewhere else</option>
        </select>
        {fieldError('toCompartmentId', `${fieldId}-to`)}
        {form.toCompartmentId === ELSEWHERE && <>
          <label htmlFor={`${fieldId}-where`}>Where</label>
          <input {...fieldProps('toLocationText', `${fieldId}-where`)} type="text" maxLength={200} autoComplete="off" value={form.toLocationText} onChange={event => onForm({ toLocationText: event.target.value })} />
          {fieldError('toLocationText', `${fieldId}-where`)}
        </>}
      </>}

      {reason === 'wrong_description' && <>
        {text('name', 'Correct name', 160, 'name')}
        {text('description', 'Correct description (optional)', 500)}
      </>}

      {showDetails && <>
        {reason === 'new_item' && text('name', 'Item name', 160, 'name')}
        {text('manufacturer', 'Manufacturer (optional)', 120)}
        {text('model', 'Model (optional)', 120)}
        {text('description', 'Description (optional)', 500)}
      </>}

      {(reason === 'wrong_quantity' || reason === 'new_item') && <>
        <label htmlFor={`${fieldId}-quantity`}>{reason === 'new_item' ? 'Quantity (optional)' : 'Correct quantity'}</label>
        <input {...fieldProps('quantity', `${fieldId}-quantity`)} type="number" inputMode="numeric" min={1} max={QUANTITY_MAX} step={1} value={form.quantity} onChange={event => onForm({ quantity: event.target.value })} />
        {fieldError('quantity', `${fieldId}-quantity`)}
      </>}

      {reason !== '' && <>
        <label htmlFor={`${fieldId}-note`}>{reason === 'other' ? 'Note (required)' : 'Note (optional)'}</label>
        <textarea {...fieldProps('note', `${fieldId}-note`)} rows={2} maxLength={NOTE_MAX} value={form.note} onChange={event => onForm({ note: event.target.value })} />
        {fieldError('note', `${fieldId}-note`)}
      </>}

      {showPhoto && <>
        <label htmlFor={`${fieldId}-photo`}>Photo (optional)</label>
        <input key={form.photoKey} id={`${fieldId}-photo`} type="file" accept="image/*" onChange={event => attachPhoto(event.target.files?.[0])} />
        {form.photo && <div className="inspection-photo-preview">
          <img src={form.photo} alt="Photo for this report" className="inspection-photo" />
          <button type="button" className="hub-btn hub-btn--destructive" onClick={() => onForm({ photo: undefined, photoKey: form.photoKey + 1 })}>Remove photo</button>
        </div>}
      </>}

      {form.error && <p role="alert" className="inspection-alert ic-failure">{form.error}</p>}
      <div className="ic-actions">
        <button type="button" className="hub-btn hub-btn--primary" disabled={form.sending} onClick={() => { void send(); }}>{form.sending ? 'Sending…' : 'Send to Logistics'}</button>
      </div>
    </div>}
  </div>;
}
