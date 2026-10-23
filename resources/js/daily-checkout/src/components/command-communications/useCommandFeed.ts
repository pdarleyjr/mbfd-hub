import { useCallback, useEffect, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry, ApiRequestError } from '../../utils/api';
import {
  deleteCommandPost, fetchCommandPosts, fetchCommandSummary, isForbiddenError, markCommandPostsRead, setCommandPostPinned,
} from '../../utils/commandCommunicationsApi';
import { appendOlder, applyRemoval, insertPost, isDisconnected, mergeFirstPage, replaceTopLevel, withPinned } from './feedMerge';
import { DEFAULT_CHANNEL, type CommandChannel, type CommandPost } from './types';

const POLL_MS = 20_000;
const MAX_BACKOFF_MS = 300_000;
const PAGE_SIZE = 20;

interface FeedState {
  posts: CommandPost[];
  pinned: CommandPost[];
  nextBefore: string | null;
  olderLoaded: boolean;
  unreadCount: number;
  status: 'loading' | 'ready' | 'error';
  error: string | null;
}

const INITIAL: FeedState = { posts: [], pinned: [], nextBefore: null, olderLoaded: false, unreadCount: 0, status: 'loading', error: null };

const messageOf = (error: unknown, fallback: string): string => (error instanceof Error && error.message ? error.message : fallback);

interface FeedOptions {
  /** The Communications tab is the one showing. Polling only runs while it is. */
  active: boolean;
  /** False until access is confirmed (and after it is refused): no request is made. */
  enabled: boolean;
  onDenied: () => void;
  isVisible: boolean;
  /** Which feed this is; the 300 channel unless a page says otherwise. */
  channel?: CommandChannel;
}

/**
 * Newest-first feed state. Polls the first page every 20 seconds while the tab is visible and
 * active, backs off exponentially (up to 5 minutes) on failure, and refreshes the moment the
 * page becomes visible again. The state lives with the caller, so switching tabs keeps it.
 */
export function useCommandFeed({ active, enabled, onDenied, isVisible, channel = DEFAULT_CHANNEL }: FeedOptions) {
  const [state, setState] = useState<FeedState>(INITIAL);
  const [loadingEarlier, setLoadingEarlier] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [pinBusyId, setPinBusyId] = useState<string | null>(null);
  const inFlight = useRef<Promise<void> | null>(null);
  const readInFlight = useRef(false);
  const stopped = useRef(false);
  const nextBeforeRef = useRef<string | null>(null);
  nextBeforeRef.current = state.nextBefore;

  /** True when the failure ends the session of use (sign-in expired, access removed). */
  const settleTerminal = useCallback((error: unknown): boolean => {
    if (isApiAuthenticationError(error)) {
      stopped.current = true;
      redirectToLoginAfterSessionExpiry();
      return true;
    }
    if (isForbiddenError(error)) {
      stopped.current = true;
      onDenied();
      return true;
    }
    return false;
  }, [onDenied]);

  const refresh = useCallback((): Promise<void> => {
    if (inFlight.current) return inFlight.current;
    const run = (async () => {
      try {
        const page = await fetchCommandPosts({ limit: PAGE_SIZE, channel });
        setState((current) => {
          const keepOlder = current.olderLoaded && page.next_before !== null && !isDisconnected(current.posts, page);
          return {
            posts: mergeFirstPage(current.posts, page),
            pinned: page.pinned,
            nextBefore: keepOlder ? current.nextBefore : page.next_before,
            olderLoaded: keepOlder,
            unreadCount: page.unread_count,
            status: 'ready',
            error: null,
          };
        });
      } catch (error) {
        if (!settleTerminal(error)) {
          setState((current) => ({ ...current, status: current.posts.length > 0 || current.status === 'ready' ? current.status : 'error', error: messageOf(error, 'Messages could not be loaded.') }));
        }
        throw error;
      } finally {
        inFlight.current = null;
      }
    })();
    inFlight.current = run;
    return run;
  }, [channel, settleTerminal]);

  // Seed the Communications badge when the page opens on a station tab (one cheap request).
  const seeded = useRef(false);
  useEffect(() => {
    if (seeded.current || active || !enabled) return;
    seeded.current = true;
    fetchCommandSummary(channel)
      .then((summary) => setState((current) => (current.status === 'loading' ? { ...current, unreadCount: summary.unread_count } : current)))
      .catch((error) => { settleTerminal(error); });
  }, [active, channel, enabled, settleTerminal]);

  useEffect(() => {
    if (!enabled || !active || !isVisible || stopped.current) return undefined;
    let cancelled = false;
    let failures = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const run = async () => {
      let retryAfterMs = 0;
      try {
        await refresh();
        failures = 0;
      } catch (error) {
        failures += 1;
        if (error instanceof ApiRequestError && error.retryAfterMs) retryAfterMs = error.retryAfterMs;
      }
      if (cancelled || stopped.current) return;
      const backoff = Math.min(POLL_MS * 2 ** failures, MAX_BACKOFF_MS);
      timer = setTimeout(() => { void run(); }, failures === 0 ? POLL_MS : Math.max(backoff, retryAfterMs));
    };

    void run();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [enabled, active, isVisible, refresh]);

  const loadEarlier = useCallback(async (): Promise<boolean> => {
    const before = nextBeforeRef.current;
    if (!before) return false;
    setLoadingEarlier(true);
    setActionError(null);
    try {
      const page = await fetchCommandPosts({ before, limit: PAGE_SIZE, channel });
      setState((current) => ({ ...current, posts: appendOlder(current.posts, page.data), nextBefore: page.next_before, olderLoaded: true }));
      return true;
    } catch (error) {
      if (!settleTerminal(error)) setActionError(messageOf(error, 'Earlier messages could not be loaded.'));
      return false;
    } finally {
      setLoadingEarlier(false);
    }
  }, [channel, settleTerminal]);

  const markRead = useCallback(async () => {
    if (readInFlight.current) return;
    readInFlight.current = true;
    try {
      const receipt = await markCommandPostsRead(channel);
      setState((current) => ({ ...current, unreadCount: receipt.unread_count }));
    } catch (error) {
      settleTerminal(error);
    } finally {
      readInFlight.current = false;
    }
  }, [channel, settleTerminal]);

  const setPinned = useCallback(async (post: CommandPost, pinned: boolean) => {
    setPinBusyId(post.id);
    setActionError(null);
    try {
      const updated = await setCommandPostPinned(post.id, pinned, channel);
      setState((current) => ({ ...current, posts: replaceTopLevel(current.posts, updated), pinned: withPinned(current.pinned, updated) }));
    } catch (error) {
      if (isApiAuthenticationError(error)) settleTerminal(error);
      else setActionError(messageOf(error, pinned ? 'The message could not be pinned.' : 'The message could not be unpinned.'));
    } finally {
      setPinBusyId(null);
    }
  }, [channel, settleTerminal]);

  /** Removes a message with a required reason. Resolves null on success, else the message to show beside the prompt. */
  const removePost = useCallback(async (post: CommandPost, reason: string): Promise<string | null> => {
    try {
      const tombstone = await deleteCommandPost(post.id, reason, channel);
      setState((current) => ({ ...current, posts: applyRemoval(current.posts, tombstone), pinned: withPinned(current.pinned, tombstone) }));
      return null;
    } catch (error) {
      if (isApiAuthenticationError(error)) {
        settleTerminal(error);
        return 'Your sign-in expired.';
      }
      return messageOf(error, 'The message could not be removed. Try again.');
    }
  }, [channel, settleTerminal]);

  const addPost = useCallback((post: CommandPost) => {
    setState((current) => ({ ...current, posts: insertPost(current.posts, post), status: 'ready', error: null }));
  }, []);

  return { ...state, loadingEarlier, actionError, pinBusyId, refresh, loadEarlier, markRead, setPinned, removePost, addPost };
}

export type CommandFeed = ReturnType<typeof useCommandFeed>;
