import { useCallback, useState } from 'react';
import { ApiRequestError, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { sendCommandPost } from '../../utils/commandCommunicationsApi';
import { useCommandDraft } from './useCommandDraft';
import { useCommandFeed } from './useCommandFeed';
import { usePageVisible } from './usePageClock';

interface Options {
  active: boolean;
  enabled: boolean;
  onDenied: () => void;
}

/**
 * Feed, draft and send state for the Communications tab. A message is added to the feed only
 * after the server answers 200/201; on failure the text, files and submission id are kept so
 * Retry sends the identical request.
 */
export function useCommandCommunications({ active, enabled, onDenied }: Options) {
  const isVisible = usePageVisible();
  const feed = useCommandFeed({ active, enabled, onDenied, isVisible });
  const controls = useCommandDraft();
  const { draft, markAttempt, reset } = controls;
  const [sending, setSending] = useState(false);
  const [progress, setProgress] = useState<number | null>(null);
  const [sendError, setSendError] = useState<string | null>(null);
  const [sentCount, setSentCount] = useState(0);
  const { addPost, refresh } = feed;

  const canSend = !sending && (draft.body.trim() !== '' || draft.files.length > 0);

  const send = useCallback(async () => {
    if (!canSend) return;
    setSending(true);
    setProgress(draft.files.length > 0 ? 0 : null);
    setSendError(null);
    try {
      const post = await sendCommandPost({
        subject: draft.subject,
        body: draft.body,
        parentId: draft.replyTo?.id ?? null,
        clientSubmissionId: draft.submissionId,
        files: draft.files,
      }, setProgress);
      addPost(post);
      reset();
      setSentCount((count) => count + 1);
      refresh().catch(() => undefined);
    } catch (error) {
      if (isApiAuthenticationError(error)) {
        redirectToLoginAfterSessionExpiry();
        return;
      }
      const status = error instanceof ApiRequestError ? error.status : 0;
      markAttempt(status === 0 || status >= 500);
      setSendError(error instanceof Error && error.message ? error.message : 'The message could not be sent. Try again.');
    } finally {
      setSending(false);
      setProgress(null);
    }
  }, [addPost, canSend, draft, markAttempt, refresh, reset]);

  return { feed, controls, sending, progress, sendError, sentCount, canSend, send, isVisible };
}

export type CommandCommunications = ReturnType<typeof useCommandCommunications>;
