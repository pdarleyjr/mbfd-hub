import {
  STRETCHER_PHOTO_MAX_BYTES, STRETCHER_TEXT_MAX, STRETCHER_TEXT_MIN, type StretcherProblem,
} from '../../utils/memberIssueActionsApi';

/** The problems a member can pick, in the order they are shown. */
export const STRETCHER_PROBLEMS: ReadonlyArray<{ value: StretcherProblem; label: string }> = [
  { value: 'loading', label: 'Loading / unloading' },
  { value: 'armrest', label: 'Armrest' },
  { value: 'battery', label: 'Battery' },
  { value: 'charger', label: 'Charger' },
  { value: 'restraint', label: 'Restraint' },
  { value: 'mechanical', label: 'Mechanical' },
  { value: 'other', label: 'Other' },
];

export const PROBLEM_REQUIRED = 'Choose the kind of problem.';
export const DESCRIPTION_TOO_SHORT = `Write at least ${STRETCHER_TEXT_MIN} characters about what is wrong.`;
export const PHOTO_FORMAT_PROBLEM = 'Choose a JPEG, PNG or WebP photo under 10 MB.';

export interface StretcherFields {
  problem: StretcherProblem | null;
  description: string;
}

export interface StretcherFieldErrors {
  problem?: string;
  description?: string;
}

export function validateStretcher(fields: StretcherFields): StretcherFieldErrors {
  const errors: StretcherFieldErrors = {};
  if (fields.problem === null) errors.problem = PROBLEM_REQUIRED;
  const length = fields.description.trim().length;
  if (length < STRETCHER_TEXT_MIN) errors.description = DESCRIPTION_TOO_SHORT;
  else if (length > STRETCHER_TEXT_MAX) errors.description = `Keep this under ${STRETCHER_TEXT_MAX} characters.`;

  return errors;
}

const PHOTO_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
const PHOTO_EXTENSION = /\.(?:jpe?g|png|webp)$/i;

/** Null when the file can be sent. Some phones give a photo no type at all, so the name stands in. */
export function stretcherPhotoProblem(file: Pick<File, 'name' | 'type' | 'size'>): string | null {
  if (file.size > STRETCHER_PHOTO_MAX_BYTES) return PHOTO_FORMAT_PROBLEM;
  const type = file.type.toLowerCase();
  const allowed = type === '' ? PHOTO_EXTENSION.test(file.name) : PHOTO_TYPES.has(type);

  return allowed ? null : PHOTO_FORMAT_PROBLEM;
}
