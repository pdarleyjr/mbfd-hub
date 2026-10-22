import { useEffect, useRef, useState, type ChangeEvent, type KeyboardEvent, type RefObject } from 'react';
import { Paperclip, RotateCw, Send, X } from 'lucide-react';
import { formatBytes } from './format';
import { ATTACHMENT_ACCEPT, MAX_ATTACHMENTS } from './types';
import type { CommandCommunications } from './useCommandCommunications';

const FIELD = 'w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 text-base text-hub-ink placeholder:text-hub-n-500';
const MAX_TEXTAREA_PX = 160;

interface ComposerProps {
  comms: CommandCommunications;
  offline: boolean;
  textareaRef: RefObject<HTMLTextAreaElement | null>;
}

function SendProgress({ progress }: { progress: number | null }) {
  if (progress === null) return <p role="status" className="text-[13px] text-hub-n-600">Sending message…</p>;
  const percent = Math.round(progress * 100);
  return (
    <div role="status" className="space-y-1">
      <p className="text-[13px] tabular-nums text-hub-n-600">Uploading {percent}%</p>
      <div className="h-1.5 overflow-hidden rounded-full bg-hub-n-100" role="progressbar" aria-label="Upload progress" aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
        <div className="h-full bg-hub-blue" style={{ width: `${percent}%` }} />
      </div>
    </div>
  );
}

export default function Composer({ comms, offline, textareaRef }: ComposerProps) {
  const { controls, sending, progress, sendError, canSend, send } = comms;
  const { draft } = controls;
  const fileInput = useRef<HTMLInputElement>(null);
  const [fileNotice, setFileNotice] = useState<string | null>(null);

  // Grow with the text up to a cap, then scroll inside the box.
  useEffect(() => {
    const element = textareaRef.current;
    if (!element) return;
    element.style.height = 'auto';
    element.style.height = `${Math.min(element.scrollHeight, MAX_TEXTAREA_PX)}px`;
  }, [draft.body, textareaRef]);

  const submitShortcut = (event: KeyboardEvent<HTMLElement>) => {
    if (event.key !== 'Enter') return;
    // Plain Enter never sends: it is a new line in the message and does nothing in the subject.
    if (event.ctrlKey || event.metaKey) {
      event.preventDefault();
      if (!offline) void send();
    } else if (event.target instanceof HTMLInputElement) {
      event.preventDefault();
    }
  };

  const onPick = (event: ChangeEvent<HTMLInputElement>) => {
    const picked = Array.from(event.target.files ?? []);
    event.target.value = '';
    setFileNotice(controls.addFiles(picked));
  };

  return (
    <form
      className="mt-3 space-y-2 border-t border-hub-glass-border pt-3"
      aria-label="Write a message"
      data-testid="command-composer"
      onSubmit={(event) => { event.preventDefault(); if (!offline) void send(); }}
    >
      {draft.replyTo && (
        <div className="flex items-center justify-between gap-2 rounded-hub-control bg-hub-blue-tint px-3 text-sm text-hub-ink" data-testid="command-reply-chip">
          <span className="min-w-0 truncate py-2">Replying to <strong className="font-semibold">{draft.replyTo.label}</strong></span>
          <button type="button" className="hub-btn hub-btn--tertiary hub-btn--compact !min-h-11 !min-w-11 flex-none" onClick={() => controls.setReplyTo(null)} aria-label="Cancel reply">
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
      )}

      {draft.showSubject ? (
        <div>
          <label htmlFor="command-subject" className="mb-1 block text-[13px] font-semibold text-hub-n-600">Subject</label>
          <input id="command-subject" type="text" className={`${FIELD} min-h-11`} value={draft.subject} onChange={(event) => controls.setSubject(event.target.value)} onKeyDown={submitShortcut} autoComplete="off" />
        </div>
      ) : (
        <button type="button" className="hub-btn hub-btn--tertiary hub-btn--compact !min-h-11" onClick={controls.showSubject}>Add subject</button>
      )}

      <textarea
        ref={textareaRef}
        rows={2}
        aria-label={draft.replyTo ? `Reply to ${draft.replyTo.label}` : 'Message'}
        placeholder={draft.replyTo ? 'Write a reply' : 'Write a message to 300 Command and Administration'}
        className={`${FIELD} block min-h-[3.25rem] max-h-40 resize-none overflow-y-auto`}
        value={draft.body}
        onChange={(event) => controls.setBody(event.target.value)}
        onKeyDown={submitShortcut}
      />

      {draft.files.length > 0 && (
        <ul aria-label="Files to attach" className="flex flex-wrap gap-2">
          {draft.files.map((file) => (
            <li key={`${file.name}-${file.size}-${file.lastModified}`} className="flex min-h-11 max-w-full items-center gap-1 rounded-hub-control border border-hub-glass-border bg-hub-n-0 pl-3 text-sm text-hub-ink">
              <span className="min-w-0 truncate font-semibold">{file.name}</span>
              <span className="flex-none text-[13px] tabular-nums text-hub-n-600">{formatBytes(file.size)}</span>
              <button type="button" className="hub-btn hub-btn--tertiary hub-btn--compact !min-h-11 !min-w-11 flex-none" onClick={() => controls.removeFile(file)} aria-label={`Remove ${file.name}`}>
                <X className="h-4 w-4" aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      )}
      {fileNotice && <p role="alert" className="text-sm text-hub-warning-strong" data-testid="command-file-notice">{fileNotice}</p>}

      {sendError && (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-2 rounded-hub-control border border-hub-danger-border bg-hub-danger-bg p-3 text-sm text-hub-ink" data-testid="command-send-error">
          <p className="min-w-0 flex-1"><strong className="font-semibold text-hub-danger">Message not sent.</strong> {sendError} Your text and files are still here.</p>
          <button type="button" className="hub-btn hub-btn--secondary !min-h-11 gap-2" onClick={() => void send()} disabled={sending || offline}>
            <RotateCw className="h-4 w-4" aria-hidden="true" />Retry
          </button>
        </div>
      )}
      {sending && <SendProgress progress={progress} />}

      <div className="flex flex-wrap items-center gap-2">
        <input ref={fileInput} type="file" hidden multiple accept={ATTACHMENT_ACCEPT} onChange={onPick} data-testid="command-file-input" />
        <button type="button" className="hub-btn hub-btn--secondary !min-h-11 gap-2" onClick={() => fileInput.current?.click()} disabled={sending || draft.files.length >= MAX_ATTACHMENTS}>
          <Paperclip className="h-4 w-4" aria-hidden="true" />Attach
        </button>
        <p className="min-w-0 flex-1 text-[13px] text-hub-n-600">Enter adds a line. Ctrl or Cmd + Enter sends.</p>
        <button type="submit" className="hub-btn hub-btn--primary !min-h-11 gap-2" disabled={!canSend || offline} data-testid="command-send">
          <Send className="h-4 w-4" aria-hidden="true" />{offline ? 'Requires connection' : 'Send'}
        </button>
      </div>
    </form>
  );
}
