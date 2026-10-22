import { forwardRef, useMemo, useState } from 'react';
import type { Compartment, ReviewFlagDraft } from '../types';
import {
  EMPTY_REVIEW_FLAG,
  REVIEW_FLAG_HELP,
  REVIEW_FLAG_LABEL,
  REVIEW_FLAG_MAX_EXPLANATION,
  reviewFlagItemKey,
} from '../utils/reviewFlag';
import './inspection-workspace.css';

/** The server accepts at most 50 related items. */
const MAX_RELATED_ITEMS = 50;
/** Short checklists do not need a filter. */
const FILTER_THRESHOLD = 15;

interface Props {
  draft: ReviewFlagDraft;
  onChange: (draft: ReviewFlagDraft) => void;
  compartments: Compartment[];
  /** True after a submit attempt with a blank explanation. */
  showError: boolean;
}

interface PickerGroup {
  compartment: Compartment;
  items: Array<{ id: string; name: string; reported: string | null }>;
}

/** Areas holding items the member marked Missing or Damaged come first, and so do those items within an area. */
function pickerGroups(compartments: Compartment[], filter: string): PickerGroup[] {
  const needle = filter.trim().toLowerCase();
  const groups = compartments.map(compartment => {
    const items = compartment.items
      .map(item => ({ id: item.id, name: item.name, reported: item.observed === true && item.status !== 'Present' ? item.status : null }))
      .filter(item => needle === '' || item.name.toLowerCase().includes(needle) || compartment.name.toLowerCase().includes(needle));
    return { compartment, items: [...items.filter(item => item.reported), ...items.filter(item => !item.reported)] };
  }).filter(group => group.items.length > 0);
  return [...groups.filter(group => group.items.some(item => item.reported)), ...groups.filter(group => !group.items.some(item => item.reported))];
}

/**
 * "Flag this inspection for further review": a checkbox, its guidance, and once checked a required
 * explanation plus an optional picker of related checklist items. State lives in the wizard so it is
 * autosaved and restored with the rest of the draft.
 */
const ReviewFlagControl = forwardRef<HTMLTextAreaElement, Props>(function ReviewFlagControl({ draft, onChange, compartments, showError }, textareaRef) {
  const [filter, setFilter] = useState('');
  const totalItems = useMemo(() => compartments.reduce((sum, compartment) => sum + compartment.items.length, 0), [compartments]);
  const groups = useMemo(() => pickerGroups(compartments, filter), [compartments, filter]);
  const selected = useMemo(() => new Set(draft.items.map(reviewFlagItemKey)), [draft.items]);
  const blank = draft.explanation.trim() === '';
  const invalid = showError && blank;
  const limitReached = draft.items.length >= MAX_RELATED_ITEMS;

  const toggleItem = (compartmentId: string, itemId: string, checked: boolean) => {
    const ref = { compartment_id: compartmentId, item_id: itemId };
    const key = reviewFlagItemKey(ref);
    onChange({
      ...draft,
      items: checked ? [...draft.items.filter(entry => reviewFlagItemKey(entry) !== key), ref] : draft.items.filter(entry => reviewFlagItemKey(entry) !== key),
    });
  };

  return <div className="review-flag" data-review-flag>
    <label className="review-flag-toggle" htmlFor="review-flag-toggle">
      <input
        id="review-flag-toggle"
        type="checkbox"
        checked={draft.checked}
        aria-describedby="review-flag-help"
        onChange={event => {
          // Unchecking discards the explanation and related items so a hidden flag can never be submitted.
          if (!event.target.checked) setFilter('');
          onChange(event.target.checked ? { ...draft, checked: true } : EMPTY_REVIEW_FLAG);
        }}
      />
      <span>{REVIEW_FLAG_LABEL}</span>
    </label>
    <p id="review-flag-help" className="review-flag-help">{REVIEW_FLAG_HELP}</p>

    {draft.checked && <div className="review-flag-body">
      <label htmlFor="review-flag-explanation" className="review-flag-field-label">What needs special attention?</label>
      <textarea
        id="review-flag-explanation"
        ref={textareaRef}
        rows={4}
        maxLength={REVIEW_FLAG_MAX_EXPLANATION}
        aria-required="true"
        aria-invalid={invalid || undefined}
        aria-describedby={invalid ? 'review-flag-explanation-error review-flag-count' : 'review-flag-count'}
        className={invalid ? 'is-invalid' : undefined}
        value={draft.explanation}
        onChange={event => onChange({ ...draft, explanation: event.target.value })}
      />
      <div className="review-flag-meta">
        {invalid && <p id="review-flag-explanation-error" role="alert" className="review-flag-error">Describe what needs special attention before submitting.</p>}
        <p id="review-flag-count" className="review-flag-count">{draft.explanation.length} of {REVIEW_FLAG_MAX_EXPLANATION}</p>
      </div>

      <details className="review-flag-items">
        <summary>
          <span>Related checklist items (optional)</span>
          <span className="review-flag-selected" aria-live="polite">selected ({draft.items.length})</span>
        </summary>
        {totalItems > FILTER_THRESHOLD && <>
          <label htmlFor="review-flag-filter" className="review-flag-field-label">Filter items</label>
          <input id="review-flag-filter" type="search" value={filter} autoComplete="off" onChange={event => setFilter(event.target.value)} />
        </>}
        {limitReached && <p className="review-flag-count">You can relate up to {MAX_RELATED_ITEMS} items.</p>}
        {groups.length === 0 && <p className="review-flag-count">No checklist items match.</p>}
        {groups.map(group => <fieldset key={group.compartment.id} className="review-flag-group">
          <legend>{group.compartment.name}</legend>
          {group.items.map(item => {
            const checked = selected.has(reviewFlagItemKey({ compartment_id: group.compartment.id, item_id: item.id }));
            return <label key={item.id} className="review-flag-option">
              <input type="checkbox" checked={checked} disabled={!checked && limitReached} onChange={event => toggleItem(group.compartment.id, item.id, event.target.checked)} />
              <span>{item.name}{item.reported && <small className="hub-tag hub-tag--danger">{item.reported}</small>}</span>
            </label>;
          })}
        </fieldset>)}
      </details>
    </div>}
  </div>;
});

export default ReviewFlagControl;
