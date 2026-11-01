import { FileText } from 'lucide-react';
import { formatBytes } from './format';
import { inlineHref, safeHref } from './linkify';
import type { CommandAttachment } from './types';

function ImageThumb({ attachment }: { attachment: CommandAttachment }) {
  const src = inlineHref(attachment.url);
  if (!src) return null;
  return (
    <li>
      <a
        href={src}
        target="_blank"
        rel="noopener noreferrer"
        data-hub-viewer="image"
        data-hub-viewer-title={attachment.name}
        data-hub-viewer-download={safeHref(attachment.url) ?? undefined}
        className="block h-20 w-20 overflow-hidden rounded-hub-control border border-hub-glass-border bg-hub-n-100"
        aria-label={`Open image ${attachment.name}`}
      >
        <img src={src} alt={attachment.name} width={80} height={80} loading="lazy" decoding="async" className="h-full w-full object-cover" />
      </a>
    </li>
  );
}

function DocumentChip({ attachment }: { attachment: CommandAttachment }) {
  const href = safeHref(attachment.url);
  if (!href) return null;
  const size = formatBytes(attachment.size);
  return (
    <li className="min-w-0 max-w-full">
      <a
        href={href}
        download={attachment.name}
        className="inline-flex min-h-11 max-w-full items-center gap-2 rounded-hub-control border border-hub-glass-border bg-hub-n-0 px-3 py-2 text-sm text-hub-ink hover:bg-hub-n-50"
      >
        <FileText className="h-4 w-4 flex-none text-hub-n-600" aria-hidden="true" />
        <span className="min-w-0 truncate font-semibold">{attachment.name}</span>
        {size && <span className="flex-none text-[13px] tabular-nums text-hub-n-600">{size}</span>}
        <span className="sr-only">Download</span>
      </a>
    </li>
  );
}

/** Images as small lazy thumbnails that open the inline view; documents as download chips. */
export default function AttachmentList({ attachments }: { attachments: CommandAttachment[] }) {
  if (attachments.length === 0) return null;
  const images = attachments.filter((attachment) => attachment.kind === 'image');
  const documents = attachments.filter((attachment) => attachment.kind !== 'image');
  return (
    <div className="mt-2 space-y-2" data-testid="command-attachments">
      {images.length > 0 && <ul aria-label="Images" className="flex flex-wrap gap-2">{images.map((attachment) => <ImageThumb key={attachment.id} attachment={attachment} />)}</ul>}
      {documents.length > 0 && <ul aria-label="Documents" className="flex flex-wrap gap-2">{documents.map((attachment) => <DocumentChip key={attachment.id} attachment={attachment} />)}</ul>}
    </div>
  );
}
