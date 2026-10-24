import { useId } from 'react';
import type { VehicleIdentification, VehicleIdentificationAnswers, VehicleIdentificationQuestion } from '../types';
import {
  IDENTIFICATION_NOTE_MAX,
  answerFor,
  effectiveMode,
  normalizeTyped,
  questionProblem,
  withAnswer,
} from '../utils/vehicleIdentification';

interface Props {
  block: VehicleIdentification;
  answers: VehicleIdentificationAnswers;
  onChange: (next: VehicleIdentificationAnswers) => void;
  /** Show what is still missing on each open question. */
  showErrors?: boolean;
  disabled?: boolean;
}

const CONTROL = 'mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 font-normal text-hub-ink';
const CHOICE = 'hub-well flex min-h-11 items-center gap-3 px-3 py-2 text-hub-ink';
const CHOICE_INPUT = 'h-5 w-5 flex-none border-hub-control-border text-hub-blue focus:ring-hub-focus';

/** Move focus to the first control of one question (used when a save is blocked). */
export function focusIdentificationField(root: ParentNode | null, field: string): void {
  const group = Array.from(root?.querySelectorAll<HTMLElement>('[data-vid-field]') ?? []).find((element) => element.dataset.vidField === field);
  const target = group?.querySelector<HTMLElement>('input, textarea');
  group?.scrollIntoView({ block: 'center' });
  target?.focus({ preventScroll: true });
}

interface QuestionProps {
  block: VehicleIdentification;
  question: VehicleIdentificationQuestion;
  answers: VehicleIdentificationAnswers;
  onChange: (next: VehicleIdentificationAnswers) => void;
  showErrors: boolean;
  disabled: boolean;
  prefix: string;
}

function Question({ block, question, answers, onChange, showErrors, disabled, prefix }: QuestionProps) {
  const answer = answerFor(answers, question);
  const mode = effectiveMode(question, answer);
  const problem = showErrors ? questionProblem(block, question, answer) : null;
  const id = `${prefix}-${question.field}`;
  const reason = block.reasons.find((entry) => entry.code === answer.reason);
  const describedBy = [question.hint ? `${id}-hint` : '', problem ? `${id}-error` : ''].filter(Boolean).join(' ') || undefined;
  const upper = question.field === 'vin' || question.field === 'plate';
  const set = (patch: Parameters<typeof withAnswer>[2]) => onChange(withAnswer(answers, question.field, patch));
  const showInput = mode === 'value' || (!question.confirm && mode !== 'unavailable');

  return <div role="group" aria-labelledby={`${id}-label`} data-vid-field={question.field} className="grid gap-2">
    <label id={`${id}-label`} htmlFor={`${id}-input`} className="text-sm font-semibold">{question.label}</label>
    {question.hint && <p id={`${id}-hint`} className="text-sm text-hub-n-600">{question.hint}</p>}

    {question.confirm && mode !== 'unavailable' && <>
      <p className="text-sm text-hub-ink">The Hub has <strong className="tabular-nums">{question.current_value}</strong> on record.</p>
      <label className={CHOICE}>
        <input
          type="radio"
          name={`${id}-confirm`}
          checked={mode === 'confirm'}
          disabled={disabled}
          onChange={() => set({ mode: 'confirm' })}
          className={CHOICE_INPUT}
        />
        <span className="font-medium">Yes, that is what is on the vehicle</span>
      </label>
      <label className={CHOICE}>
        <input
          type="radio"
          name={`${id}-confirm`}
          checked={mode === 'value'}
          disabled={disabled}
          onChange={() => set({ mode: 'value' })}
          className={CHOICE_INPUT}
        />
        <span className="font-medium">No, I will enter what is on the vehicle</span>
      </label>
    </>}

    {showInput && <input
      id={`${id}-input`}
      type="text"
      value={answer.value}
      disabled={disabled}
      maxLength={question.max}
      inputMode={question.input === 'year' ? 'numeric' : undefined}
      autoCapitalize={upper ? 'characters' : 'off'}
      autoComplete="off"
      autoCorrect="off"
      spellCheck={false}
      aria-describedby={describedBy}
      aria-invalid={problem ? true : undefined}
      onChange={(event) => set({ mode: 'value', value: normalizeTyped(question, event.target.value) })}
      className={`${CONTROL} tabular-nums`}
    />}

    {block.reasons.length > 0 && <label className={CHOICE}>
      <input
        type="checkbox"
        checked={mode === 'unavailable'}
        disabled={disabled}
        onChange={(event) => set({ mode: event.target.checked ? 'unavailable' : question.confirm ? '' : 'value' })}
        className={CHOICE_INPUT}
      />
      <span className="font-medium">Information not available</span>
    </label>}

    {mode === 'unavailable' && <div role="radiogroup" aria-labelledby={`${id}-why`} className="grid gap-2">
      <p id={`${id}-why`} className="text-sm font-semibold">Why is it not available?</p>
      {block.reasons.map((entry) => <label key={entry.code} className={CHOICE}>
        <input
          type="radio"
          name={`${id}-reason`}
          value={entry.code}
          checked={answer.reason === entry.code}
          disabled={disabled}
          onChange={() => set({ reason: entry.code })}
          className={CHOICE_INPUT}
        />
        <span className="font-medium">{entry.label}</span>
      </label>)}
      {reason?.needs_note && <label className="text-sm font-semibold" htmlFor={`${id}-note`}>Short note (required)
        <input
          id={`${id}-note`}
          type="text"
          value={answer.note}
          disabled={disabled}
          maxLength={IDENTIFICATION_NOTE_MAX}
          autoComplete="off"
          onChange={(event) => set({ note: event.target.value })}
          className={CONTROL}
        />
      </label>}
    </div>}

    {problem && <p id={`${id}-error`} role="alert" className="text-sm font-semibold text-hub-danger">{problem}</p>}
  </div>;
}

/**
 * The one-time vehicle information card. The same component is used in the Daily Checkout (Member / Vehicle
 * Info step) and on the Captain 5 page. It renders nothing when the server has no open question.
 */
export default function VehicleIdentificationCard({ block, answers, onChange, showErrors = false, disabled = false }: Props) {
  const prefix = useId();
  if (block.questions.length === 0) return null;

  return <section data-vehicle-identification aria-labelledby={`${prefix}-title`} className="rounded-xl border border-hub-glass-border bg-hub-n-50 p-4">
    <h3 id={`${prefix}-title`} className="text-base font-semibold text-hub-ink">One-time vehicle information</h3>
    {block.note && <p className="mt-1 text-sm text-hub-n-600">{block.note}</p>}
    <div className="mt-4 grid gap-5">
      {block.questions.map((question) => <Question
        key={question.field}
        block={block}
        question={question}
        answers={answers}
        onChange={onChange}
        showErrors={showErrors}
        disabled={disabled}
        prefix={prefix}
      />)}
    </div>
  </section>;
}
