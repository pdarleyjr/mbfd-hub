import type {
  VehicleIdentification,
  VehicleIdentificationAnswer,
  VehicleIdentificationAnswers,
  VehicleIdentificationQuestion,
  VehicleIdentificationReason,
  VehicleIdentificationSubmission,
  VehicleIdentificationSubmittedAnswer,
} from '../types';

/** Longest note a crew member can attach to "Information not available". */
export const IDENTIFICATION_NOTE_MAX = 200;

const DEFAULT_TEXT_MAX = 32;
const UPPER_CASE_FIELDS = new Set(['vin', 'plate']);

const record = (value: unknown): Record<string, unknown> | null => (
  value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : null
);

const text = (value: unknown): string | null => (typeof value === 'string' && value.trim() !== '' ? value.trim() : null);

function parseQuestion(raw: unknown): VehicleIdentificationQuestion | null {
  const row = record(raw);
  const field = text(row?.field);
  const label = text(row?.label);
  if (!row || !field || !label) return null;
  const input = field === 'year' || row.input === 'year' || row.input === 'number' ? 'year' : 'text';
  const max = typeof row.max === 'number' && Number.isInteger(row.max) && row.max > 0 ? row.max : DEFAULT_TEXT_MAX;
  const currentValue = text(row.current_value);

  return {
    field,
    label,
    hint: text(row.hint),
    input,
    max: input === 'year' ? 4 : max,
    current_value: currentValue,
    // Confirming needs a value on record to confirm against.
    confirm: row.confirm === true && currentValue !== null,
  };
}

function parseReason(raw: unknown): VehicleIdentificationReason | null {
  const row = record(raw);
  const code = text(row?.code);
  const label = text(row?.label);

  return row && code && label ? { code, label, needs_note: row.needs_note === true } : null;
}

/**
 * The server block, rebuilt field by field (unknown keys are dropped). Returns undefined when nothing is
 * open, so an absent block, `required: false` and an empty list all mean "no questions".
 */
export function parseVehicleIdentification(raw: unknown): VehicleIdentification | undefined {
  const block = record(raw);
  if (!block || block.required === false || !Array.isArray(block.questions)) return undefined;
  const seen = new Set<string>();
  const questions = block.questions.flatMap((entry): VehicleIdentificationQuestion[] => {
    const question = parseQuestion(entry);
    if (!question || seen.has(question.field)) return [];
    seen.add(question.field);

    return [question];
  });
  if (questions.length === 0) return undefined;

  return {
    note: typeof block.note === 'string' ? block.note.trim() : '',
    kind: block.kind === 'boat' ? 'boat' : 'vehicle',
    questions,
    reasons: Array.isArray(block.reasons) ? block.reasons.flatMap((entry) => parseReason(entry) ?? []) : [],
    questions_version: typeof block.questions_version === 'string' ? block.questions_version : '',
  };
}

/** The refreshed block from a save call: either the block itself or wrapped under its key. */
export const parseVehicleIdentificationResponse = (payload: unknown): VehicleIdentification | undefined => (
  parseVehicleIdentification(record(payload)?.vehicle_identification ?? payload)
);

export const openQuestions = (block: VehicleIdentification | null | undefined): VehicleIdentificationQuestion[] => block?.questions ?? [];

const EMPTY_ANSWER: VehicleIdentificationAnswer = { mode: '', value: '', reason: '', note: '' };

export const answerFor = (answers: VehicleIdentificationAnswers, question: VehicleIdentificationQuestion): VehicleIdentificationAnswer => (
  answers[question.field] ?? EMPTY_ANSWER
);

/** A plain question starts in "enter it"; a confirm question starts unanswered. */
export const effectiveMode = (question: VehicleIdentificationQuestion, answer: VehicleIdentificationAnswer): VehicleIdentificationAnswer['mode'] => {
  if (answer.mode === 'confirm' && !question.confirm) return '';

  return answer.mode !== '' ? answer.mode : question.confirm ? '' : 'value';
};

/** What the crew typed, shaped as it is sent: plate and VIN upper case, year digits only, never longer than the server allows. */
export function normalizeTyped(question: VehicleIdentificationQuestion, raw: string): string {
  if (question.input === 'year') return raw.replace(/\D/g, '').slice(0, 4);
  const shaped = UPPER_CASE_FIELDS.has(question.field) ? raw.toUpperCase() : raw;

  return shaped.slice(0, question.max);
}

/** Immutable update of one question's answer. */
export const withAnswer = (
  answers: VehicleIdentificationAnswers,
  field: string,
  patch: Partial<VehicleIdentificationAnswer>,
): VehicleIdentificationAnswers => ({
  ...answers,
  [field]: { ...(answers[field] ?? EMPTY_ANSWER), ...patch },
});

const plainVin = (value: string): string => value.toUpperCase().replace(/[\s-]/g, '');

/** Why this question is not answered yet, in the crew's words; null when it is answered. */
export function questionProblem(
  block: VehicleIdentification,
  question: VehicleIdentificationQuestion,
  answer: VehicleIdentificationAnswer,
): string | null {
  const mode = effectiveMode(question, answer);
  if (mode === 'unavailable') {
    const reason = block.reasons.find((entry) => entry.code === answer.reason);
    if (!reason) return 'Choose why it is not available.';

    return reason.needs_note && answer.note.trim() === '' ? 'Add a short note.' : null;
  }
  if (mode === 'confirm') return null;
  if (mode === '') return 'Confirm it, or enter what is on the vehicle.';
  const value = answer.value.trim();
  if (value === '') return 'Enter it, or choose Information not available.';
  if (question.input === 'year') return /^(19|20)\d{2}$/.test(value) ? null : 'Enter the 4 digit year.';
  if (question.field === 'vin' && block.kind === 'vehicle' && !/^[A-Z0-9]{17}$/.test(plainVin(value))) {
    return 'A VIN has 17 letters and numbers.';
  }

  return null;
}

/** True when nothing is open or every open question is answered or marked not available with its reason. */
export const vehicleIdentificationComplete = (
  block: VehicleIdentification | null | undefined,
  answers: VehicleIdentificationAnswers,
): boolean => !block || block.questions.every((question) => questionProblem(block, question, answerFor(answers, question)) === null);

/** The key of the first open question that still needs an answer. */
export const firstIncompleteField = (
  block: VehicleIdentification | null | undefined,
  answers: VehicleIdentificationAnswers,
): string | null => (
  block?.questions.find((question) => questionProblem(block, question, answerFor(answers, question)) !== null)?.field ?? null
);

/**
 * What is sent with the checkout and queued for replay. Undefined when no question is open, so the key is
 * omitted and older payloads stay byte for byte the same.
 */
export function toVehicleIdentificationSubmission(
  block: VehicleIdentification | null | undefined,
  answers: VehicleIdentificationAnswers,
): VehicleIdentificationSubmission | undefined {
  if (!block || block.questions.length === 0) return undefined;
  const list = block.questions.flatMap((question): VehicleIdentificationSubmittedAnswer[] => {
    const answer = answerFor(answers, question);
    const mode = effectiveMode(question, answer);
    if (mode === 'unavailable') {
      const reason = block.reasons.find((entry) => entry.code === answer.reason);
      const note = answer.note.trim();

      return reason ? [{ field: question.field, unavailable_reason: reason.code, note: reason.needs_note && note !== '' ? note : null }] : [];
    }
    if (mode === 'confirm') return question.current_value ? [{ field: question.field, value: question.current_value }] : [];
    const value = normalizeTyped(question, answer.value).trim();

    return mode === 'value' && value !== '' ? [{ field: question.field, value }] : [];
  });

  return { questions_version: block.questions_version, answers: list };
}
