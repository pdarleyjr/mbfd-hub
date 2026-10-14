/** Semantic tones shared by Daily status tags. Each maps to a `.hub-tag` variant. */
export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

/** Turns a stored status or enum value ("waiting_for_parts") into readable text ("Waiting for parts"). */
export function humanize(value: string | null | undefined, fallback = 'Not recorded'): string {
  const words = (value ?? '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
  if (!words) return fallback;
  return words.charAt(0).toUpperCase() + words.slice(1).toLowerCase();
}

// Existing domain wording keeps its established meaning; red is reserved for failures and critical states.
const STATUS_TONES: Record<string, Tone> = {
  pass: 'success',
  passed: 'success',
  approved: 'success',
  fulfilled: 'success',
  completed: 'success',
  resolved: 'success',
  checked: 'success',
  valid: 'success',
  in_service: 'success',
  fail: 'danger',
  failed: 'danger',
  denied: 'danger',
  rejected: 'danger',
  critical: 'danger',
  urgent: 'danger',
  expired: 'danger',
  not_checked: 'danger',
  out_of_service: 'danger',
  needs_attention: 'warning',
  attention: 'warning',
  acknowledged: 'warning',
  under_review: 'warning',
  submitted: 'warning',
  high: 'warning',
  classification_required: 'warning',
  expiring: 'warning',
  pending: 'info',
  scheduled: 'info',
  ordered: 'info',
  in_progress: 'info',
  medium: 'info',
  review_pending: 'info',
};

export function statusTone(value: string | null | undefined): Tone {
  const key = (value ?? '').trim().toLowerCase().replace(/[\s-]+/g, '_');
  return STATUS_TONES[key] ?? 'neutral';
}
