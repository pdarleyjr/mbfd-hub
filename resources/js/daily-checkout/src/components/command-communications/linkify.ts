export type TextPart =
  | { kind: 'text'; text: string }
  | { kind: 'link'; text: string; href: string };

/** Candidate links start with http:// or https:// only; every other scheme stays plain text. */
const URL_PATTERN = /https?:\/\/[^\s<>"'`]+/gi;
const TRAILING_PUNCTUATION = new Set(['.', ',', ';', ':', '!', '?']);
const CLOSERS: Record<string, string> = { ')': '(', ']': '[', '}': '{' };

const count = (value: string, char: string): number => value.split(char).length - 1;

/** Sentence punctuation and unbalanced closing brackets are not part of the address. */
function trimCandidate(candidate: string): string {
  let end = candidate;
  while (end.length > 0) {
    const last = end[end.length - 1];
    const opener = CLOSERS[last];
    if (TRAILING_PUNCTUATION.has(last) || (opener && count(end, last) > count(end, opener))) {
      end = end.slice(0, -1);
      continue;
    }
    break;
  }
  return end;
}

function toHref(candidate: string): string | null {
  try {
    const url = new URL(candidate);
    const web = url.protocol === 'http:' || url.protocol === 'https:';
    // Embedded credentials are a common look-alike trick; leave those as text.
    return web && url.username === '' && url.password === '' ? url.href : null;
  } catch {
    return null;
  }
}

/**
 * Splits plain text into text and link parts. The caller renders the parts as React nodes,
 * so the text is always escaped and no HTML is ever injected.
 */
export function linkify(input: string): TextPart[] {
  const parts: TextPart[] = [];
  let cursor = 0;

  const pushText = (text: string) => {
    if (text === '') return;
    const previous = parts[parts.length - 1];
    if (previous?.kind === 'text') {
      parts[parts.length - 1] = { kind: 'text', text: previous.text + text };
    } else {
      parts.push({ kind: 'text', text });
    }
  };

  for (const match of input.matchAll(URL_PATTERN)) {
    const start = match.index ?? 0;
    const candidate = trimCandidate(match[0]);
    const href = toHref(candidate);
    pushText(input.slice(cursor, start));
    if (href) {
      parts.push({ kind: 'link', text: candidate, href });
      pushText(match[0].slice(candidate.length));
    } else {
      pushText(match[0]);
    }
    cursor = start + match[0].length;
  }
  pushText(input.slice(cursor));

  return parts;
}

/** Server-provided file addresses: same-origin paths or http(s) only. */
export function safeHref(value: string): string | null {
  if (/^\/(?!\/)[^\s\\]*$/.test(value)) return value;
  return /^https?:\/\//i.test(value) ? toHref(value) : null;
}

/** Adds the inline-view flag the file endpoint understands, preserving any existing query. */
export function inlineHref(value: string): string | null {
  const href = safeHref(value);
  if (!href) return null;
  return `${href}${href.includes('?') ? '&' : '?'}inline=1`;
}
