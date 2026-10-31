import type { ReactNode } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import Tag from '../ui/Tag';
import { kindLabel, statusOf } from './format';
import type { IssueSummary } from './types';

export const FIELD = 'w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 text-base text-hub-ink placeholder:text-hub-n-500 min-h-11';
export const LABEL = 'mb-1 block text-sm font-semibold text-hub-ink';
export const HINT = 'text-[13px] leading-5 text-hub-n-600';
export const BTN = 'hub-btn !min-h-11';
export const BTN_PRIMARY = `${BTN} hub-btn--primary`;
export const BTN_SECONDARY = `${BTN} hub-btn--secondary`;

/** Status, kind and the three markers that need attention. Every marker is text; colour only reinforces it. */
export function IssueTags({ issue, showKind = true }: { issue: IssueSummary; showKind?: boolean }) {
  const status = statusOf(issue);
  return (
    <span className="flex flex-wrap items-center gap-1.5">
      <Tag tone={status.tone}>{status.label}</Tag>
      {issue.overdue && <Tag tone="danger">Overdue</Tag>}
      {issue.severity === 'significant' && <Tag tone="danger">Significant damage</Tag>}
      {issue.awaiting_verification && <Tag tone="warning">Awaiting verification</Tag>}
      {showKind && <Tag>{kindLabel(issue.kind)}</Tag>}
    </span>
  );
}

interface SheetProps {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  testId: string;
  /** Stacking layer: 0 for the issue sheet, 1 for the inspection view opened over it. */
  layer?: 0 | 1;
  /** The control to focus when the sheet closes, for sheets opened from a list that may not hold focus itself. */
  returnFocusTo?: () => HTMLElement | null;
  children: ReactNode;
}

/**
 * Overlay panel (level 3): a bottom-anchored full-height sheet on phones, a right-hand panel from sm up.
 * Radix supplies the focus trap, Escape, scroll lock and focus return; nothing here is hover-only.
 */
export function Sheet({ open, onClose, title, description, testId, layer = 0, returnFocusTo, children }: SheetProps) {
  return (
    <Dialog.Root open={open} onOpenChange={(next) => { if (!next) onClose(); }}>
      <Dialog.Portal>
        <Dialog.Overlay className={`fixed inset-0 bg-hub-n-900/40 ${layer === 0 ? 'z-[70]' : 'z-[80]'}`} />
        <Dialog.Content
          data-hub-ui="2"
          data-testid={testId}
          onCloseAutoFocus={returnFocusTo ? (event) => {
            const target = returnFocusTo();
            if (target?.isConnected) {
              event.preventDefault();
              target.focus();
            }
          } : undefined}
          className={`fixed inset-y-0 right-0 flex w-full flex-col bg-hub-n-0 font-hub shadow-[var(--hub-elevation-3)] sm:max-w-2xl sm:border-l sm:border-hub-glass-border ${layer === 0 ? 'z-[71]' : 'z-[81]'}`}
        >
          <header className="flex items-start gap-3 border-b border-hub-glass-border px-4 py-3 sm:px-5">
            <div className="min-w-0 flex-1">
              <Dialog.Title className="font-heading text-lg font-bold text-hub-ink">{title}</Dialog.Title>
              {description
                ? <Dialog.Description className={HINT}>{description}</Dialog.Description>
                : <Dialog.Description className="sr-only">{title}</Dialog.Description>}
            </div>
            <Dialog.Close className="hub-btn hub-btn--secondary !min-h-11 !min-w-11 flex-none gap-1.5" aria-label="Close">
              <X className="h-4 w-4" aria-hidden="true" />
              <span className="hidden sm:inline">Close</span>
            </Dialog.Close>
          </header>
          <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4 sm:px-5">{children}</div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

/** A label/value pair for summaries; renders nothing for an empty value. */
export function Fact({ label, children }: { label: string; children: ReactNode }) {
  if (children === null || children === undefined || children === '' || children === false) return null;
  return (
    <div className="min-w-0">
      <dt className={HINT}>{label}</dt>
      <dd className="break-words text-sm text-hub-ink">{children}</dd>
    </div>
  );
}
