import { useId, useState } from 'react';
import { RichText } from './PostItem';

/** Past either limit a body collapses behind an explicit "Show full message". */
export const COLLAPSE_CHARS = 480;
export const COLLAPSE_LINES = 8;

/** Splits plain text into paragraphs on blank lines; single line breaks stay inside a paragraph. */
export function toParagraphs(text: string): string[] {
  return text
    .replace(/\r\n?/g, '\n')
    .split(/\n{2,}/)
    .map((part) => part.replace(/^\n+|\n+$/g, ''))
    .filter((part) => part.trim() !== '');
}

export function isLongBody(text: string): boolean {
  return text.length > COLLAPSE_CHARS || text.split('\n').length > COLLAPSE_LINES;
}

interface MessageBodyProps {
  text: string;
  /** Collapse long bodies (default). The pinned strip clamps itself instead. */
  collapsible?: boolean;
  className?: string;
  testId?: string;
}

/**
 * A message body that is comfortable to read: 16px text, 1.55 line height, about 70 characters
 * per line, real paragraphs, line breaks kept, links made safe by RichText (text is never HTML).
 */
export default function MessageBody({ text, collapsible = true, className = '', testId = 'message-body' }: MessageBodyProps) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const long = collapsible && isLongBody(text);
  const collapsed = long && !open;
  return (
    <div className={className}>
      <div
        id={id}
        data-testid={testId}
        data-collapsed={collapsed ? 'true' : 'false'}
        className={`max-w-[70ch] space-y-3 text-base leading-[1.55] text-hub-ink [overflow-wrap:anywhere] ${collapsed ? 'line-clamp-6' : ''}`}
      >
        {toParagraphs(text).map((paragraph, index) => (
          <p key={index} className="whitespace-pre-line"><RichText text={paragraph} /></p>
        ))}
      </div>
      {long && (
        <button
          type="button"
          aria-expanded={open}
          aria-controls={id}
          onClick={() => setOpen((value) => !value)}
          className="mt-1 inline-flex min-h-11 items-center text-sm font-semibold text-hub-blue underline underline-offset-4"
          data-testid="message-body-toggle"
        >
          {open ? 'Show less' : 'Show full message'}
        </button>
      )}
    </div>
  );
}
