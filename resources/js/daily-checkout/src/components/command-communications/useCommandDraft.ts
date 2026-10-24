import { useCallback, useState } from 'react';
import { describeFileProblem } from './format';
import { ALLOWED_EXTENSIONS, MAX_ATTACHMENT_BYTES, MAX_ATTACHMENTS, type CommandDraft } from './types';
import { useUnsavedWorkBusy } from '../../pwa/useUnsavedWorkBusy.ts';

/** crypto.randomUUID needs a secure context; fall back so an http kiosk still gets a unique id. */
export function newSubmissionId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = new Uint8Array(16);
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') crypto.getRandomValues(bytes);
  else bytes.forEach((_, index) => { bytes[index] = Math.floor(Math.random() * 256); });
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

const emptyDraft = (): CommandDraft => ({
  body: '', subject: '', showSubject: false, files: [], replyTo: null, submissionId: newSubmissionId(), attempted: false, outcomeUnknown: false,
});

const extensionOf = (name: string): string => name.slice(name.lastIndexOf('.') + 1).toLowerCase();
const sameFile = (a: File, b: File): boolean => a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;

/** Splits picked files into accepted ones and the first friendly problem found. Server re-validates. */
export function acceptFiles(current: File[], picked: File[]): { files: File[]; notice: string | null } {
  let notice: string | null = null;
  const files = [...current];
  for (const file of picked) {
    if (files.some((existing) => sameFile(existing, file))) continue;
    if (files.length >= MAX_ATTACHMENTS) { notice ??= describeFileProblem(file.name, 'count'); continue; }
    if (!file.type.startsWith('image/') && !ALLOWED_EXTENSIONS.includes(extensionOf(file.name))) { notice ??= describeFileProblem(file.name, 'type'); continue; }
    if (file.size > MAX_ATTACHMENT_BYTES) { notice ??= describeFileProblem(file.name, 'size'); continue; }
    files.push(file);
  }
  return { files, notice };
}

/**
 * Draft held in memory only: never written to any browser storage because the station
 * workstations are shared. It lives in the card, so switching tabs does not lose it.
 */
export function useCommandDraft() {
  const [draft, setDraft] = useState<CommandDraft>(emptyDraft);
  // The draft lives in memory only (shared workstations), so a reload would lose it:
  // tell the update registrar while a message is being written.
  useUnsavedWorkBusy(draft.body.trim() !== '' || draft.subject.trim() !== '' || draft.files.length > 0);

  /** Content changed. If the last attempt may have been stored, this is a new message: new id. */
  const edit = useCallback((patch: Partial<CommandDraft>) => {
    setDraft((current) => ({
      ...current,
      ...patch,
      ...(current.attempted && current.outcomeUnknown ? { submissionId: newSubmissionId(), attempted: false, outcomeUnknown: false } : {}),
    }));
  }, []);

  const addFiles = useCallback((picked: File[]): string | null => {
    const result = acceptFiles(draft.files, picked);
    if (result.files.length !== draft.files.length) edit({ files: result.files });
    return result.notice;
  }, [draft.files, edit]);

  return {
    draft,
    setBody: useCallback((body: string) => edit({ body }), [edit]),
    setSubject: useCallback((subject: string) => edit({ subject }), [edit]),
    showSubject: useCallback(() => setDraft((current) => ({ ...current, showSubject: true })), []),
    addFiles,
    removeFile: useCallback((file: File) => edit({ files: draft.files.filter((candidate) => candidate !== file) }), [draft.files, edit]),
    setReplyTo: useCallback((replyTo: CommandDraft['replyTo']) => edit({ replyTo }), [edit]),
    markAttempt: useCallback((outcomeUnknown: boolean) => setDraft((current) => ({ ...current, attempted: true, outcomeUnknown })), []),
    reset: useCallback(() => setDraft(emptyDraft()), []),
  };
}

export type CommandDraftControls = ReturnType<typeof useCommandDraft>;
