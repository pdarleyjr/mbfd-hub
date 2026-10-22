import { useState } from 'react';
import { ChevronDown, ChevronUp, Pin } from 'lucide-react';
import { uniqueById } from './feedMerge';
import PostItem from './PostItem';
import type { CommandPost } from './types';

interface PinnedStripProps {
  pinned: CommandPost[];
  now: number;
  onTogglePin: (post: CommandPost) => void;
  pinBusyId: string | null;
}

function preview(post: CommandPost): string {
  return (post.subject ?? post.body).replace(/\s+/g, ' ').trim();
}

/** Compact, collapsed-by-default strip above the feed. It stays put while the feed scrolls. */
export default function PinnedStrip({ pinned: pinnedPosts, now, onTogglePin, pinBusyId }: PinnedStripProps) {
  const [open, setOpen] = useState(false);
  const pinned = uniqueById(pinnedPosts);
  if (pinned.length === 0) return null;

  return (
    <section className="hub-well mb-3" aria-label="Pinned messages" data-testid="command-pinned">
      <button
        type="button"
        id="command-pinned-toggle"
        aria-expanded={open}
        aria-controls="command-pinned-list"
        onClick={() => setOpen((value) => !value)}
        className="flex min-h-11 w-full items-center gap-2 px-3 text-left text-sm font-semibold text-hub-ink"
      >
        <Pin className="h-4 w-4 flex-none text-hub-n-600" aria-hidden="true" />
        <span className="flex-none">Pinned ({pinned.length})</span>
        {!open && <span className="min-w-0 flex-1 truncate font-normal text-hub-n-600">{preview(pinned[0])}</span>}
        <span className="ml-auto flex-none">
          {open ? <ChevronUp className="h-4 w-4" aria-hidden="true" /> : <ChevronDown className="h-4 w-4" aria-hidden="true" />}
          <span className="sr-only">{open ? 'Hide pinned messages' : 'Show pinned messages'}</span>
        </span>
      </button>
      {open && (
        <ul id="command-pinned-list" className="max-h-48 divide-y divide-hub-glass-border overflow-y-auto overscroll-contain border-t border-hub-glass-border px-3">
          {pinned.map((post) => (
            <li key={post.id} className="py-3">
              <PostItem post={post} now={now} onTogglePin={onTogglePin} pinBusy={pinBusyId === post.id} pinnedView />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
