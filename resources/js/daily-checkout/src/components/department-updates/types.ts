/** "Post to Department Updates" from the 300 and Captain 5 pages. The server contract is in CommandDepartmentUpdateController. */

/** Which page the post comes from; the server applies that page's access rule. */
export type UpdateOrigin = 'captain_5' | 'command_300';

export type UpdateCategory = 'operations' | 'general' | 'training';

export interface PostedUpdate {
  id: number;
  title: string;
  excerpt: string;
  /** 'taken_down' is archived: off the home page, kept as history. */
  status: 'published' | 'taken_down';
  posted_at: string | null;
  expires_at: string | null;
  /** "Rank Name" from the poster's account; never typed in on the page. */
  author: string;
  author_rank: string | null;
  author_name: string | null;
  /** The viewer wrote it, so only the viewer can take it down. */
  own: boolean;
  has_image: boolean;
  has_attachment: boolean;
  /** Path to the update on the MBFD Hub, outside the Daily app. */
  url: string;
}

export interface UpdateInput {
  title: string;
  body: string;
  category: UpdateCategory;
  /** Y-m-d, or '' to keep it up until someone takes it down. */
  expiresOn: string;
  image: File | null;
  attachment: File | null;
  clientRequestId: string;
}

export const CATEGORY_OPTIONS: ReadonlyArray<{ value: UpdateCategory; label: string }> = [
  { value: 'operations', label: 'Operations' },
  { value: 'general', label: 'General' },
  { value: 'training', label: 'Training' },
];

export const DEFAULT_CATEGORY: UpdateCategory = 'operations';

export const TITLE_MAX = 180;
export const BODY_MAX = 10_000;

export const IMAGE_MAX_BYTES = 5 * 1024 * 1024;
export const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'] as const;
export const IMAGE_ACCEPT = '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp';

export const ATTACHMENT_MAX_BYTES = 15 * 1024 * 1024;
export const ATTACHMENT_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'pptx', 'txt', 'csv'] as const;
export const ATTACHMENT_ACCEPT = '.pdf,.docx,.xlsx,.pptx,.txt,.csv';

/** The server keeps a notice up for at most 120 days. */
export const MAX_EXPIRY_DAYS = 119;

const extensionOf = (name: string): string => (name.includes('.') ? name.split('.').pop()!.toLowerCase() : '');

/** Returns the problem with a chosen picture, or null when it is fine. */
export function imageProblem(file: File): string | null {
  if (!(IMAGE_EXTENSIONS as readonly string[]).includes(extensionOf(file.name))) return 'The picture must be a JPEG, PNG or WebP image.';
  return file.size > IMAGE_MAX_BYTES ? 'The picture is larger than 5 MB. Choose a smaller one.' : null;
}

/** Returns the problem with a chosen document, or null when it is fine. */
export function attachmentProblem(file: File): string | null {
  if (!(ATTACHMENT_EXTENSIONS as readonly string[]).includes(extensionOf(file.name))) return 'The file must be a PDF, DOCX, XLSX, PPTX, TXT or CSV document.';
  return file.size > ATTACHMENT_MAX_BYTES ? 'The file is larger than 15 MB. Choose a smaller one.' : null;
}

export const fileSizeText = (bytes: number): string => (bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);
