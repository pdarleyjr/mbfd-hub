import type { ReactNode } from 'react';
import { humanize, statusTone, type Tone } from './status';

interface TagProps {
  tone?: Tone;
  children: ReactNode;
  className?: string;
}

/** Shared `.hub-tag`: status is carried by its text, the tone only reinforces it. */
export default function Tag({ tone = 'neutral', children, className = '' }: TagProps) {
  const variant = tone === 'neutral' ? '' : ` hub-tag--${tone}`;
  return <span className={`hub-tag${variant}${className ? ` ${className}` : ''}`}>{children}</span>;
}

interface StatusTagProps {
  status: string | null | undefined;
  /** Optional readable suffix, for example "priority", so the tag reads "High priority". */
  suffix?: string;
  fallback?: string;
}

export function StatusTag({ status, suffix, fallback }: StatusTagProps) {
  const label = humanize(status, fallback);
  return <Tag tone={statusTone(status)}>{suffix && status ? `${label} ${suffix}` : label}</Tag>;
}
