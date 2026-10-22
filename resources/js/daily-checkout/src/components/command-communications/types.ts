/** Shapes of the /api/command-communications contract. */

export type CommandPostSide = 'command' | 'administration';

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

export interface CommandPost {
  id: string;
  parent_id: string | null;
  subject: string | null;
  body: string;
  author: CommandPostAuthor;
  is_mine: boolean;
  created_at: string;
  pinned: boolean;
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
  command: '300 Command',
  administration: 'Administration',
};
