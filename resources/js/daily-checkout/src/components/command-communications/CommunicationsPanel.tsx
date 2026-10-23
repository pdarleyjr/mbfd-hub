import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { ArrowDown, MessageSquare, WifiOff } from 'lucide-react';
import { useOffline } from '../../hooks/useOffline';
import { EmptyState, LoadError } from '../ui/StateViews';
import Composer from './Composer';
import PinnedStrip from './PinnedStrip';
import PostItem from './PostItem';
import { CHANNEL_COPY, DEFAULT_CHANNEL, type CommandChannel, type CommandPost } from './types';
import type { CommandCommunications } from './useCommandCommunications';
import { useClock } from './usePageClock';

/** Within this many pixels of the end counts as "at the bottom". */
const BOTTOM_SLACK_PX = 48;
/**
 * The feed scrolls inside a bounded box so history never lengthens the page. dvh keeps the box
 * honest on phones whose toolbars and keyboard change the viewport.
 */
const FEED_BOX = 'max-h-[min(50dvh,480px)] min-h-40 overflow-y-auto overscroll-contain sm:max-h-[min(60dvh,560px)]';

interface CommunicationsPanelProps {
  /** From `useCommandCommunications({ ..., channel })`; it already knows which feed it reads and writes. */
  comms: CommandCommunications;
  /** Only changes the wording (empty state, placeholder, labels). Defaults to the hook's channel, else the 300 channel. */
  channel?: CommandChannel;
  /** Opens the page's Department Updates section, for the public alternative to a private message. */
  onOpenUpdates?: () => void;
}

export const MESSAGES_ABOUT = 'Post and receive messages, files, etc. Messages can only be received and sent by admin personnel, Capt. 5 and 300.';

export default function CommunicationsPanel({ comms, channel = comms.channel ?? DEFAULT_CHANNEL, onOpenUpdates }: CommunicationsPanelProps) {
  const copy = CHANNEL_COPY[channel];
  const { feed, controls, sentCount, isVisible } = comms;
  const { setPinned, markRead, loadEarlier, refresh, removePost } = feed;
  const now = useClock();
  const offline = useOffline();
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const feedRef = useRef<HTMLDivElement>(null);
  const atBottomRef = useRef(true);
  const restoreRef = useRef<{ height: number; top: number } | null>(null);
  const [atBottom, setAtBottom] = useState(true);
  const [anchorId, setAnchorId] = useState<string | null>(null);

  const newestId = feed.posts[0]?.id ?? null;
  const newestRef = useRef(newestId);
  newestRef.current = newestId;
  const ordered = useMemo(() => [...feed.posts].reverse(), [feed.posts]);
  const newCount = anchorId ? Math.max(0, feed.posts.findIndex((post) => post.id === anchorId)) : 0;

  const onScroll = useCallback(() => {
    const element = feedRef.current;
    if (!element) return;
    const bottom = element.scrollHeight - element.scrollTop - element.clientHeight <= BOTTOM_SLACK_PX;
    atBottomRef.current = bottom;
    setAtBottom(bottom);
    if (bottom) setAnchorId(newestRef.current);
  }, []);

  const scrollToBottom = useCallback(() => {
    const element = feedRef.current;
    if (!element) return;
    element.scrollTop = element.scrollHeight;
    atBottomRef.current = true;
    setAtBottom(true);
    setAnchorId(newestRef.current);
  }, []);

  // New posts arrive at the bottom. Follow them only when the member is already there; if they
  // scrolled up to read, leave the view alone and offer the jump pill instead.
  useLayoutEffect(() => {
    const element = feedRef.current;
    if (!element) return;
    const restore = restoreRef.current;
    if (restore && element.scrollHeight !== restore.height) {
      element.scrollTop = element.scrollHeight - restore.height + restore.top;
      restoreRef.current = null;
      return;
    }
    if (atBottomRef.current) scrollToBottom();
  }, [feed.posts, feed.status, scrollToBottom]);

  useLayoutEffect(() => {
    if (sentCount > 0) scrollToBottom();
  }, [sentCount, scrollToBottom]);

  // Read receipts: only while the tab is visible and the newest message is in view.
  useEffect(() => {
    if (isVisible && atBottom && feed.status === 'ready' && feed.unreadCount > 0) void markRead();
  }, [isVisible, atBottom, feed.status, feed.unreadCount, markRead]);

  const onLoadEarlier = useCallback(async () => {
    const element = feedRef.current;
    if (element) restoreRef.current = { height: element.scrollHeight, top: element.scrollTop };
    if (!(await loadEarlier())) restoreRef.current = null;
  }, [loadEarlier]);

  const onReply = useCallback((post: CommandPost) => {
    controls.setReplyTo({ id: post.id, label: post.author.name });
    textareaRef.current?.focus();
  }, [controls]);
  const onTogglePin = useCallback((post: CommandPost) => { void setPinned(post, !post.pinned); }, [setPinned]);

  return (
    <div data-testid="command-comms">
      <div className="mb-3 space-y-1" data-testid="command-comms-about">
        <p className="max-w-prose text-sm text-hub-ink">{MESSAGES_ABOUT}</p>
        <p className="max-w-prose text-[13px] leading-5 text-hub-n-600">
          For a notice everyone sees on the home page, use{' '}
          {onOpenUpdates
            ? <button type="button" onClick={onOpenUpdates} className="inline-flex min-h-11 items-center align-middle font-semibold text-hub-blue underline underline-offset-4">Post to Department Updates</button>
            : 'Post to Department Updates'}
          {' '}instead.
        </p>
      </div>
      {offline && (
        <div className="hub-callout hub-callout--warning mb-3 flex items-center gap-2 p-3 text-sm" role="status">
          <WifiOff className="h-4 w-4 flex-none" aria-hidden="true" />
          <span>Requires connection. Messages cannot be sent or refreshed while you are offline.</span>
        </div>
      )}
      {feed.actionError && <div className="hub-callout hub-callout--warning mb-3 p-3 text-sm" role="alert">{feed.actionError}</div>}
      {feed.error && feed.posts.length > 0 && (
        <div className="hub-callout hub-callout--warning mb-3 p-3 text-sm" role="status">
          Messages could not be refreshed: {feed.error}. The list may be out of date; trying again automatically.
        </div>
      )}

      <PinnedStrip pinned={feed.pinned} now={now} onTogglePin={onTogglePin} pinBusyId={feed.pinBusyId} />

      <div className="relative">
        <div
          ref={feedRef}
          onScroll={onScroll}
          role="log"
          aria-label={copy.feedLabel}
          aria-relevant="additions"
          tabIndex={0}
          data-testid="command-feed"
          className={`${FEED_BOX} space-y-3`}
        >
          {feed.status === 'loading' && (
            <div className="space-y-3" role="status" aria-label="Loading messages">
              {[1, 2, 3].map((i) => <span key={i} className="hub-skeleton block !h-20 w-full" />)}
            </div>
          )}
          {feed.status === 'error' && feed.posts.length === 0 && (
            <LoadError title="Messages unavailable" message={feed.error} onRetry={() => { refresh().catch(() => undefined); }} />
          )}
          {feed.status === 'ready' && feed.posts.length === 0 && (
            <EmptyState
              icon={<MessageSquare className="hub-empty__icon" aria-hidden="true" />}
              title="No messages yet"
              body={copy.emptyBody}
            />
          )}
          {feed.nextBefore && (
            <div className="flex justify-center">
              <button type="button" className="hub-btn hub-btn--secondary hub-btn--compact !min-h-11" onClick={() => void onLoadEarlier()} disabled={feed.loadingEarlier || offline} aria-busy={feed.loadingEarlier}>
                {feed.loadingEarlier ? 'Loading earlier messages' : 'Load earlier messages'}
              </button>
            </div>
          )}
          {ordered.map((post) => (
            <PostItem key={post.id} post={post} now={now} onReply={onReply} onTogglePin={onTogglePin} pinBusy={feed.pinBusyId === post.id} onRemove={removePost} />
          ))}
        </div>
        {!atBottom && newCount > 0 && (
          <button
            type="button"
            onClick={scrollToBottom}
            data-testid="command-new-messages"
            className="hub-btn hub-btn--primary absolute bottom-3 left-1/2 !min-h-11 -translate-x-1/2 gap-2"
          >
            <ArrowDown className="h-4 w-4" aria-hidden="true" />
            New messages
            <span className="sr-only"> ({newCount})</span>
          </button>
        )}
      </div>

      <Composer comms={comms} offline={offline} textareaRef={textareaRef} placeholder={copy.placeholder} />
    </div>
  );
}
