import type { CommandPost, CommandPostsPage } from './types';

/** The fresh first page shares no post with what is loaded: more than a page of new posts arrived. */
export function isDisconnected(existing: CommandPost[], page: CommandPostsPage): boolean {
  const freshIds = new Set(page.data.map((post) => post.id));
  return existing.length > 0 && page.next_before !== null && !existing.some((post) => freshIds.has(post.id));
}

/** Keeps the first occurrence of each id so a post never renders twice in one list. */
export function uniqueById(posts: CommandPost[]): CommandPost[] {
  const seen = new Set<string>();
  return posts.filter((post) => (seen.has(post.id) ? false : (seen.add(post.id), true)));
}

/**
 * Merges a freshly fetched first page into what is already loaded (all newest-first).
 * Fresh posts win; older pages the member already loaded are kept below them.
 */
export function mergeFirstPage(existing: CommandPost[], page: CommandPostsPage): CommandPost[] {
  // No older page exists, so the fresh page is the complete list (this also drops deleted posts).
  // A disconnected page means more than a page of new posts arrived; start again from the newest.
  if (page.data.length === 0 || page.next_before === null || isDisconnected(existing, page)) return uniqueById(page.data);
  const freshIds = new Set(page.data.map((post) => post.id));
  const oldestFresh = new Date(page.data[page.data.length - 1].created_at).getTime();
  const older = existing.filter((post) => !freshIds.has(post.id) && new Date(post.created_at).getTime() <= oldestFresh); // timestamps are second-precision, so ties are kept
  return uniqueById([...page.data, ...older]);
}

export function appendOlder(existing: CommandPost[], older: CommandPost[]): CommandPost[] {
  const known = new Set(existing.map((post) => post.id));
  return [...existing, ...older.filter((post) => !known.has(post.id))];
}

/** Adds an accepted post: a reply joins its parent, a top-level post goes first. Replaces by id. */
export function insertPost(existing: CommandPost[], post: CommandPost): CommandPost[] {
  if (post.parent_id) {
    return existing.map((candidate) => (candidate.id === post.parent_id
      ? { ...candidate, replies: [...candidate.replies.filter((reply) => reply.id !== post.id), post] }
      : candidate));
  }
  return existing.some((candidate) => candidate.id === post.id)
    ? existing.map((candidate) => (candidate.id === post.id ? post : candidate))
    : [post, ...existing];
}

/**
 * Applies the tombstone the server returned after a removal. A removed top-level post keeps the
 * replies the server sent with it; a removed reply is swapped in place inside its parent.
 */
export function applyRemoval(existing: CommandPost[], removed: CommandPost): CommandPost[] {
  if (removed.parent_id) {
    return existing.map((candidate) => (candidate.id === removed.parent_id
      ? { ...candidate, replies: candidate.replies.map((reply) => (reply.id === removed.id ? removed : reply)) }
      : candidate));
  }
  return existing.map((candidate) => (candidate.id === removed.id ? removed : candidate));
}

export function replaceTopLevel(existing: CommandPost[], post: CommandPost): CommandPost[] {
  return existing.map((candidate) => (candidate.id === post.id ? { ...candidate, pinned: post.pinned } : candidate));
}

export function withPinned(pinned: CommandPost[], post: CommandPost): CommandPost[] {
  const rest = pinned.filter((candidate) => candidate.id !== post.id);
  return post.pinned ? [post, ...rest] : rest;
}
