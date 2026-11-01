import { useRef, useState, type Dispatch, type SetStateAction } from 'react';
import { Plus } from 'lucide-react';
import type { ConcernDraft, ConcernIssueRef, ConcernKind } from '../../types/issueHistory.ts';
import {
  MAX_CONCERNS,
  MAX_CONCERN_DESCRIPTION,
  MAX_CONCERN_LOCATION,
  concernDraftProblems,
  issueRefLabel,
  newConcernDraft,
  suggestExistingIssue,
} from '../../utils/concerns.ts';
import { photoProblem, readPhotoFile } from '../../utils/photoFile.ts';
import { createClientSubmissionId } from '../../utils/storage';
import './findings.css';

export const CONCERNS_HEADING = 'Concerns, Damage & Additional Notes';

interface Props {
  concerns: ConcernDraft[];
  onChange: Dispatch<SetStateAction<ConcernDraft[]>>;
  /** Open issues on this apparatus, offered first as "Is this one of these?". */
  openIssues: ConcernIssueRef[];
  /** Show what stops an entry from being sent (after a submit attempt). */
  showErrors?: boolean;
}

const KIND_OPTIONS: Array<{ kind: ConcernKind; label: string }> = [
  { kind: 'note', label: 'Note for the record: no action needed' },
  { kind: 'issue', label: 'Problem that needs attention' },
];

/**
 * Anything the member noticed that the checklist did not ask about: damage, a worry, or a note for the record. Any
 * number of entries up to 20. An entry can add to an issue already on record, which is offered first. Entries are
 * part of the draft and are sent with the checkout on every apparatus.
 */
export default function ConcernsSection({ concerns, onChange, openIssues, showErrors = false }: Props) {
  const [focusId, setFocusId] = useState<string | null>(null);
  const [photoError, setPhotoError] = useState<{ id: string; message: string } | null>(null);
  // Bumped to clear a file picker after a rejected file or a removal, so the same file can be chosen again.
  const [pickerResets, setPickerResets] = useState<Record<string, number>>({});
  const addRef = useRef<HTMLButtonElement>(null);
  const problems = showErrors ? concernDraftProblems(concerns) : [];
  const atLimit = concerns.length >= MAX_CONCERNS;

  const patch = (id: string, changes: Partial<ConcernDraft>) => onChange(previous => previous.map(draft => draft.client_id === id ? { ...draft, ...changes } : draft));
  const resetPicker = (id: string) => setPickerResets(previous => ({ ...previous, [id]: (previous[id] ?? 0) + 1 }));

  const add = () => {
    const draft = newConcernDraft(createClientSubmissionId());
    setFocusId(draft.client_id);
    onChange(previous => previous.length >= MAX_CONCERNS ? previous : [...previous, draft]);
  };

  const remove = (id: string) => {
    onChange(previous => previous.filter(draft => draft.client_id !== id));
    setPhotoError(null);
    requestAnimationFrame(() => addRef.current?.focus());
  };

  const choosePhoto = (id: string, file?: File) => {
    if (!file) return;
    const problem = photoProblem(file);
    if (problem) {
      setPhotoError({ id, message: problem });
      resetPicker(id);
      return;
    }
    setPhotoError(null);
    readPhotoFile(file).then(photo => patch(id, { photo })).catch((error: unknown) => {
      setPhotoError({ id, message: error instanceof Error ? error.message : 'The image could not be read. Choose it again.' });
      resetPicker(id);
    });
  };

  const removePhoto = (id: string) => {
    patch(id, { photo: undefined });
    resetPicker(id);
    requestAnimationFrame(() => document.getElementById(`concern-photo-${id}`)?.focus());
  };

  return (
    <section data-hub-ui="2" className="concerns-section inspection-review-details" aria-labelledby="concerns-heading" data-concerns-count={concerns.length}>
      <h3 id="concerns-heading" className="mb-1 font-semibold">{CONCERNS_HEADING}</h3>
      <p className="inspection-muted">Add anything you noticed that the checklist did not ask about, such as damage, a worry, or a note for the record.</p>

      {concerns.map((draft, index) => {
        const fieldProblems = problems.filter(problem => problem.client_id === draft.client_id);
        const descriptionProblem = fieldProblems.find(problem => problem.field === 'description');
        const kindProblem = fieldProblems.find(problem => problem.field === 'kind');
        const known = draft.existing_issue_id !== null;
        const options = known && !openIssues.some(issue => issue.id === draft.existing_issue_id)
          ? [...openIssues, { id: draft.existing_issue_id as number, item: `Issue ${draft.existing_issue_id}`, compartment: '', issue_type: '', status: 'No longer open', description: null }]
          : openIssues;
        const suggestion = !known && draft.description.trim() !== '' ? suggestExistingIssue(draft.description, openIssues) : null;
        const id = draft.client_id;

        return (
          <fieldset key={id} className="concern-entry" data-concern-entry={index + 1}>
            <legend>Entry {index + 1}</legend>

            {openIssues.length > 0 && (
              <fieldset className="concern-existing">
                <legend>Is this one of these?</legend>
                {options.map(issue => (
                  <label key={issue.id} className="concern-option">
                    <input type="radio" name={`concern-existing-${id}`} checked={draft.existing_issue_id === issue.id} onChange={() => patch(id, { existing_issue_id: issue.id, kind: 'issue' })} />
                    <span>{issueRefLabel(issue)}</span>
                  </label>
                ))}
                <label className="concern-option">
                  <input type="radio" name={`concern-existing-${id}`} checked={!known} onChange={() => patch(id, { existing_issue_id: null, kind: null })} />
                  <span>No, this is something new</span>
                </label>
              </fieldset>
            )}

            <label htmlFor={`concern-description-${id}`}>What did you notice?</label>
            <textarea
              id={`concern-description-${id}`}
              rows={3}
              maxLength={MAX_CONCERN_DESCRIPTION}
              value={draft.description}
              autoFocus={focusId === id}
              aria-required="true"
              aria-invalid={descriptionProblem ? true : undefined}
              aria-describedby={descriptionProblem ? `concern-description-error-${id}` : undefined}
              onChange={event => patch(id, { description: event.target.value })}
            />
            {descriptionProblem && <p id={`concern-description-error-${id}`} role="alert" className="concern-error">{descriptionProblem.message}</p>}

            {suggestion && (
              <div className="concern-hint" role="status">
                <p>This looks like an issue already on record: {issueRefLabel(suggestion)}.</p>
                <button type="button" className="hub-btn hub-btn--secondary" onClick={() => patch(id, { existing_issue_id: suggestion.id, kind: 'issue' })}>Add it to that issue</button>
              </div>
            )}

            <label htmlFor={`concern-location-${id}`}>Where is it? (optional)</label>
            <input id={`concern-location-${id}`} type="text" maxLength={MAX_CONCERN_LOCATION} placeholder="Area, item or equipment name" value={draft.location} onChange={event => patch(id, { location: event.target.value })} />

            {known ? (
              <p className="inspection-muted">This will be added to the issue you chose. It is not a new issue.</p>
            ) : (
              <fieldset className="concern-kind" aria-describedby={kindProblem ? `concern-kind-error-${id}` : undefined}>
                <legend>What kind of entry is this?</legend>
                {KIND_OPTIONS.map(option => (
                  <label key={option.kind} className="concern-option">
                    <input type="radio" name={`concern-kind-${id}`} checked={draft.kind === option.kind} onChange={() => patch(id, { kind: option.kind })} />
                    <span>{option.label}</span>
                  </label>
                ))}
                {kindProblem && <p id={`concern-kind-error-${id}`} role="alert" className="concern-error">{kindProblem.message}</p>}
              </fieldset>
            )}

            <label htmlFor={`concern-photo-${id}`}>Photo (optional)</label>
            <input key={`concern-photo-${id}-${pickerResets[id] ?? 0}`} id={`concern-photo-${id}`} type="file" accept="image/*" onChange={event => choosePhoto(id, event.target.files?.[0])} />
            {photoError?.id === id && <p role="alert" className="concern-error">{photoError.message}</p>}
            {draft.photo && (
              <div className="inspection-photo-preview">
                <img src={draft.photo} alt={`Photo for entry ${index + 1}`} className="inspection-photo" />
                <button type="button" className="hub-btn hub-btn--destructive" onClick={() => removePhoto(id)}>Remove photo</button>
              </div>
            )}

            <button type="button" className="hub-btn hub-btn--tertiary concern-remove" onClick={() => remove(id)}>Remove entry {index + 1}</button>
          </fieldset>
        );
      })}

      <button ref={addRef} type="button" className="hub-btn hub-btn--secondary concern-add" disabled={atLimit} onClick={add}>
        <Plus size={18} aria-hidden="true" />{concerns.length === 0 ? 'Add a concern or note' : 'Add another'}
      </button>
      {atLimit && <p className="inspection-muted">You have added the most entries allowed ({MAX_CONCERNS}).</p>}
    </section>
  );
}
