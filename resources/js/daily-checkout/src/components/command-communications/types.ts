/**
 * Shapes of the shared conversation contract. One post model serves two channels:
 * /api/command-communications (the 300 channel) and /api/captain5/communications (Captain 5).
 */

/** Which feed a panel shows. `command_300` is the default and the original 300 page behaviour. */
export type CommandChannel = 'command_300' | 'captain_5';

/** Who a post is shown to. `both` appears in the 300 and the Captain 5 feed as one record. */
export type CommandAudience = 'command_300' | 'captain_5' | 'both';

export type CommandPostSide = 'command' | 'administration' | 'captain5';

export interface CommandAttachment {
  id: string;
  name: string;
  mime_type: string;
  size: number;
  kind: 'image' | 'document';
  url: string;
}

export interface CommandPostAuthor {
  name: string;
  rank: string | null;
  side: CommandPostSide;
}

export interface CommandPostRemoval {
  by: string;
  at: string | null;
}

export interface CommandPost {
  id: string;
  parent_id: string | null;
  audience: CommandAudience;
  subject: string | null;
  body: string;
  author: CommandPostAuthor;
  is_mine: boolean;
  created_at: string;
  pinned: boolean;
  /** A removed post is a tombstone: no subject, body or attachments, only who removed it. */
  deleted: boolean;
  removed: CommandPostRemoval | null;
  /** The viewer is the author or a communications administrator. The server enforces it. */
  can_delete: boolean;
  attachments: CommandAttachment[];
  replies: CommandPost[];
}

export interface CommandPostsPage {
  /** Newest-first top-level posts. */
  data: CommandPost[];
  pinned: CommandPost[];
  next_before: string | null;
  unread_count: number;
}

export interface CommandSummary {
  unread_count: number;
  latest_activity_at: string | null;
}

export interface CommandReadReceipt {
  unread_count: number;
  last_read_at: string | null;
}

export interface CommandPostInput {
  subject: string;
  body: string;
  parentId: string | null;
  clientSubmissionId: string;
  files: File[];
}

/** What the composer holds in memory until a post is accepted. Never persisted. */
export interface CommandDraft {
  body: string;
  subject: string;
  showSubject: boolean;
  files: File[];
  replyTo: { id: string; label: string } | null;
  /** Generated once per draft and reused on retry so the server can de-duplicate. */
  submissionId: string;
  attempted: boolean;
  /** The last attempt may have been stored (timeout, network drop, 5xx). */
  outcomeUnknown: boolean;
}

export const MAX_ATTACHMENTS = 5;
export const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;
export const ATTACHMENT_ACCEPT = 'image/*,.pdf,.docx,.xlsx,.pptx,.txt,.csv';
export const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'pdf', 'docx', 'xlsx', 'pptx', 'txt', 'csv'];

export const SOURCE_LABEL: Record<CommandPostSide, string> = {
  command: '300',
  administration: 'Administration',
  captain5: 'Captain 5',
};

export const DEFAULT_CHANNEL: CommandChannel = 'command_300';

/** Wording that differs between the two channels. */
export const CHANNEL_COPY: Record<CommandChannel, { feedLabel: string; emptyBody: string; placeholder: string }> = {
  command_300: {
    feedLabel: 'Command messages',
    emptyBody: 'Post a message for 300 and Administration. New messages appear here.',
    placeholder: 'Write a message to 300 and Administration',
  },
  captain_5: {
    feedLabel: 'Captain 5 messages',
    emptyBody: 'Post an update for Captain 5 and Administration. New messages appear here.',
    placeholder: 'Write a message to Captain 5 and Administration',
  },
};

export const BOTH_AUDIENCE_LABEL = '300 + Captain 5';
