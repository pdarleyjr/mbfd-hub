import { memo, useState } from 'react';
import { Pin, PinOff, Reply } from 'lucide-react';
import Tag from '../ui/Tag';
import AttachmentList from './AttachmentList';
import { formatAbsolute, formatRelative } from './format';
import { linkify } from './linkify';
import RemoveControl from './RemoveControl';
import { BOTH_AUDIENCE_LABEL, SOURCE_LABEL, type CommandPost } from './types';

const VISIBLE_REPLIES = 2;
const SMALL_ACTION = 'hub-btn hub-btn--tertiary hub-btn--compact !min-h-11 gap-1.5';

const LINK_CLASS = 'font-semibold text-hub-blue underline underline-offset-2 [overflow-wrap:anywhere]';

/** This Hub (this origin or its www/apex sibling) stays in the app; any other site opens apart. */
function isHubLink(href: string): boolean {
  try {
    const url = new URL(href, window.location.href);
    const bare = (host: string) => host.replace(/^www\./, '');
    return bare(url.hostname) === bare(window.location.hostname) && url.port === window.location.port;
  } catch {
    return false;
  }
}

/** Escaped text with http(s) links only. Line breaks are kept by `whitespace-pre-wrap`. */
export function RichText({ text }: { text: string }) {
  return (
    <>
      {linkify(text).map((part, index) => {
        if (part.kind !== 'link') return <span key={index}>{part.text}</span>;
        return isHubLink(part.href)
          ? <a key={index} href={part.href} className={LINK_CLASS}>{part.text}</a>
          : <a key={index} href={part.href} target="_blank" rel="noopener noreferrer nofollow" className={LINK_CLASS}>{part.text}<span className="sr-only"> (opens in a new tab)</span></a>;
      })}
    </>
  );
}

/** Replaces a removed message's content: who removed it and when, never what it said. */
function Tombstone({ post, now }: { post: CommandPost; now: number }) {
  return (
    <p className="text-sm italic text-hub-n-600" data-testid="command-tombstone">
      Message removed by {post.removed?.by ?? 'an administrator'}
      {post.removed?.at ? <> <time dateTime={post.removed.at} title={formatAbsolute(post.removed.at)}>{formatRelative(post.removed.at, now)}</time></> : null}
    </p>
  );
}

function PostHeader({ post, now }: { post: CommandPost; now: number }) {
  const author = post.author.rank ? `${post.author.rank} ${post.author.name}` : post.author.name;
  return (
    <header className="flex flex-wrap items-center gap-x-2 gap-y-1">
      <span className="font-semibold text-hub-ink">{author}</span>
      <Tag tone={post.author.side === 'administration' ? 'neutral' : 'info'}>{SOURCE_LABEL[post.author.side]}</Tag>
      {post.is_mine && <Tag>You</Tag>}
      {!post.parent_id && post.audience === 'both' && <Tag tone="warning">{BOTH_AUDIENCE_LABEL}</Tag>}
      {post.pinned && <Tag tone="warning">Pinned</Tag>}
      <time dateTime={post.created_at} title={formatAbsolute(post.created_at)} className="text-[13px] tabular-nums text-hub-n-600">
        {formatRelative(post.created_at, now)}
      </time>
    </header>
  );
}

type RemoveHandler = (post: CommandPost, reason: string) => Promise<string | null>;

function ReplyItem({ reply, now, onRemove }: { reply: CommandPost; now: number; onRemove?: RemoveHandler }) {
  return (
    <li className="space-y-1" data-testid="command-reply">
      <PostHeader post={reply} now={now} />
      {reply.deleted ? <Tombstone post={reply} now={now} /> : (
        <>
          {reply.body && <p className="whitespace-pre-wrap text-sm text-hub-ink [overflow-wrap:anywhere]"><RichText text={reply.body} /></p>}
          <AttachmentList attachments={reply.attachments} />
          {reply.can_delete && onRemove && <div className="flex flex-wrap items-center gap-1"><RemoveControl post={reply} onRemove={onRemove} /></div>}
        </>
      )}
    </li>
  );
}

interface PostItemProps {
  post: CommandPost;
  now: number;
  onReply?: (post: CommandPost) => void;
  onTogglePin: (post: CommandPost) => void;
  pinBusy: boolean;
  /** Removes a message with a reason (see RemoveControl). Omitted where removal is not offered. */
  onRemove?: RemoveHandler;
  /** Inside the Pinned strip: no replies, clamped body, no surface of its own. */
  pinnedView?: boolean;
}

function PostItem({ post, now, onReply, onTogglePin, pinBusy, onRemove, pinnedView = false }: PostItemProps) {
  const [showAllReplies, setShowAllReplies] = useState(false);
  const replies = [...post.replies].sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime());
  const hidden = showAllReplies ? 0 : Math.max(0, replies.length - VISIBLE_REPLIES);
  const shown = hidden > 0 ? replies.slice(replies.length - VISIBLE_REPLIES) : replies;
  const name = post.author.name;

  return (
    <article className={pinnedView ? 'space-y-1' : 'hub-well space-y-2 p-3'} data-testid={pinnedView ? 'command-pinned-post' : 'command-post'} aria-label={post.deleted ? 'Removed message' : `Message from ${name}`}>
      <PostHeader post={post} now={now} />
      {post.deleted ? <Tombstone post={post} now={now} /> : (
        <>
          {post.subject && <h4 className="font-heading text-base font-bold text-hub-ink [overflow-wrap:anywhere]">{post.subject}</h4>}
          {post.body && (
            <p className={`whitespace-pre-wrap text-sm text-hub-ink [overflow-wrap:anywhere] ${pinnedView ? 'line-clamp-4' : ''}`}>
              <RichText text={post.body} />
            </p>
          )}
          <AttachmentList attachments={post.attachments} />

          <div className="flex flex-wrap items-center gap-1">
            {!pinnedView && onReply && (
              <button type="button" className={SMALL_ACTION} onClick={() => onReply(post)} aria-label={`Reply to ${name}`}>
                <Reply className="h-4 w-4" aria-hidden="true" />Reply
              </button>
            )}
            <button
              type="button"
              className={SMALL_ACTION}
              onClick={() => onTogglePin(post)}
              disabled={pinBusy}
              aria-label={post.pinned ? `Unpin message from ${name}` : `Pin message from ${name}`}
            >
              {post.pinned ? <PinOff className="h-4 w-4" aria-hidden="true" /> : <Pin className="h-4 w-4" aria-hidden="true" />}
              {post.pinned ? 'Unpin' : 'Pin'}
            </button>
            {!pinnedView && post.can_delete && onRemove && <RemoveControl post={post} onRemove={onRemove} />}
          </div>
        </>
      )}

      {!pinnedView && replies.length > 0 && (
        <div className="space-y-2 border-t border-hub-glass-border pt-3 pl-3">
          {hidden > 0 && (
            <button type="button" className="hub-btn hub-btn--secondary hub-btn--compact !min-h-11" onClick={() => setShowAllReplies(true)}>
              Show {hidden} earlier {hidden === 1 ? 'reply' : 'replies'}
            </button>
          )}
          <ul aria-label={`Replies to ${name}`} className="space-y-3">
            {shown.map((reply) => <ReplyItem key={reply.id} reply={reply} now={now} onRemove={onRemove} />)}
          </ul>
        </div>
      )}
    </article>
  );
}

export default memo(PostItem);
