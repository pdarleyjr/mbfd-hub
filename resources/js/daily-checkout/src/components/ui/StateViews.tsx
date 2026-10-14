import type { ReactNode } from 'react';
import { CircleAlert, Inbox } from 'lucide-react';

interface EmptyStateProps {
  title: string;
  body?: ReactNode;
  icon?: ReactNode;
  /** A way forward: a link or button that lets the member act on the empty state. */
  action?: ReactNode;
}

/** Shared empty state (`.hub-empty`) used across the station and vehicle pages. */
export function EmptyState({ title, body, icon, action }: EmptyStateProps) {
  return (
    <div className="hub-empty">
      {icon ?? <Inbox className="hub-empty__icon" aria-hidden="true" />}
      <p className="hub-empty__title">{title}</p>
      {body && <p className="hub-empty__body">{body}</p>}
      {action && <div className="mt-3 flex flex-wrap justify-center gap-2">{action}</div>}
    </div>
  );
}

interface LoadErrorProps {
  title: string;
  message?: string | null;
  onRetry: () => void;
  retryLabel?: string;
  /** Extra ways forward, such as a contextual Back control. */
  children?: ReactNode;
}

/** Shared failure state: what failed, the server message, Retry and an exit. */
export function LoadError({ title, message, onRetry, retryLabel = 'Retry', children }: LoadErrorProps) {
  return (
    <div role="alert" className="rounded-hub-control border border-hub-danger-border bg-hub-danger-bg p-4 sm:p-5">
      <div className="flex items-start gap-3">
        <CircleAlert className="mt-0.5 h-5 w-5 flex-none text-hub-danger" aria-hidden="true" />
        <div className="min-w-0">
          <p className="font-semibold text-hub-danger">{title}</p>
          {message && message !== title && <p className="mt-1 text-sm text-hub-n-800">{message}</p>}
        </div>
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-3">
        <button type="button" onClick={onRetry} className="hub-btn hub-btn--primary">{retryLabel}</button>
        {children}
      </div>
    </div>
  );
}

/**
 * Loading placeholder rows; the pulse stops under reduced motion.
 * Heights use the important modifier because the scoped `.hub-skeleton` rule sets a 12px line height.
 */
export function SkeletonRows({ rows = 3, className = '!h-16' }: { rows?: number; className?: string }) {
  return (
    <div className="space-y-3" role="status" aria-label="Loading">
      {Array.from({ length: rows }, (_, index) => <span key={index} className={`hub-skeleton w-full !rounded-hub-control ${className}`} />)}
    </div>
  );
}
