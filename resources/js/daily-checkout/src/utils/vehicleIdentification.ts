import { isVehicleMismatch } from './vehicleConfirmation';
import type {
  OfficerInfo,
  VehicleIdentification,
  VehicleIdentificationAnswer,
  VehicleIdentificationAnswers,
  VehicleIdentificationQuestion,
  VehicleIdentificationReason,
  VehicleIdentificationSubmission,
  VehicleIdentificationSubmittedAnswer,
  VehicleIdentificationUnit,
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
    // A server that does not send can_answer predates the flag: the crew can answer.
    can_answer: block.can_answer !== false,
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

/**
 * The station page's `vehicle_identification_units`, rebuilt unit by unit. A missing key, a non-list, a
 * unit without a numeric apparatus id, or a unit whose block has no open question all mean "nothing to
 * show" for that unit, so the page renders nothing for them and never throws on an older or newer server.
 */
export function parseVehicleIdentificationUnits(raw: unknown): VehicleIdentificationUnit[] {
  if (!Array.isArray(raw)) return [];
  const seen = new Set<number>();

  return raw.flatMap((entry): VehicleIdentificationUnit[] => {
    const row = record(entry);
    const apparatusId = row?.apparatus_id;
    if (!row || typeof apparatusId !== 'number' || !Number.isInteger(apparatusId) || apparatusId <= 0 || seen.has(apparatusId)) return [];
    const block = parseVehicleIdentification(row.vehicle_identification);
    if (!block) return [];
    seen.add(apparatusId);

    return [{ apparatus_id: apparatusId, designation: text(row.designation) ?? `Unit ${apparatusId}`, vehicle_identification: block }];
  });
}

/**
 * The block that applies to this checkout, or undefined. It does not apply when the server says the crew
 * cannot answer, or while the crew reports a different physical vehicle than the one assigned (the server
 * ignores identification answers for another vehicle, so nothing is asked, required or sent).
 */
export function activeVehicleIdentification(
  block: VehicleIdentification | null | undefined,
  officer: Pick<OfficerInfo, 'vehicleConfirmation'>,
): VehicleIdentification | undefined {
  if (!block || !block.can_answer) return undefined;
  const confirmation = officer.vehicleConfirmation;
  // With no vehicle number on record there is nothing for another vehicle to differ from.
  const differentVehicle = confirmation !== undefined && confirmation.assignedVehicle !== '' && isVehicleMismatch(confirmation);

  return differentVehicle ? undefined : block;
}

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

/** Upper case with spaces and dashes removed: how the server compares plates, VINs and hull IDs. */
const compact = (value: string): string => value.toUpperCase().replace(/[\s-]/g, '');

const BLANK_MARKERS = new Set(['N/A', 'NA', 'NONE', '-', '0', 'UNKNOWN', 'X']);
const VEHICLE_NUMBER_PATTERN = /^[A-Za-z0-9][A-Za-z0-9 ./#-]{3,31}$/;
const HELP = "If it isn't on the vehicle or you can't read it, choose Information not available.";

/** Make and model are free text: a lone "X" is a real model (Tesla Model X), so only identifiers treat it as a placeholder. */
const FREE_TEXT_FIELDS = new Set(['make', 'model']);

/**
 * A placeholder such as N/A, or one character repeated, is not a real value. The server drops these too.
 * Identifiers (VIN/HIN, plate, vehicle number) reject X as well; make and model keep it.
 */
const looksLikeBlank = (value: string, allowX = false): boolean => {
  const marker = value.trim().toUpperCase().replace(/\s+/g, '');
  const letters = compact(value);
  if (allowX && marker === 'X') return false;

  return BLANK_MARKERS.has(marker) || letters === '' || (letters.length > 1 && /^(.)\1+$/.test(letters));
};

/** The format message for a typed value, or null when it is acceptable. Mirrors the server rules. */
function formatProblem(block: VehicleIdentification, question: VehicleIdentificationQuestion, value: string): string | null {
  if (question.input === 'year') return /^(19|20)\d{2}$/.test(value) ? null : 'Enter a year from 1900 to 2099.';
  if (looksLikeBlank(value, FREE_TEXT_FIELDS.has(question.field))) return `That is not a real value. ${HELP}`;
  const letters = compact(value);
  if (question.field === 'plate') {
    return /^[A-Z0-9]{2,16}$/.test(letters) ? null : `A plate has 2 to 16 letters and numbers. ${HELP}`;
  }
  if (question.field === 'vehicle_number') {
    return VEHICLE_NUMBER_PATTERN.test(value.trim())
      ? null
      : `A vehicle number has 4 to 32 characters: letters, numbers, spaces and . / # -. ${HELP}`;
  }
  if (question.field === 'vin') {
    return block.kind === 'boat'
      ? (/^[A-Z0-9]{6,32}$/.test(letters) ? null : `A hull ID has 6 to 32 letters and numbers. ${HELP}`)
      : (/^[A-Z0-9]{17}$/.test(letters) ? null : `A VIN has 17 letters and numbers. ${HELP}`);
  }

  return null;
}

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

  return formatProblem(block, question, value);
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
