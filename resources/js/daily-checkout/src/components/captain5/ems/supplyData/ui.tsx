import { useId, useState, type ReactNode } from 'react';
import { ArrowLeft, ChevronDown, Download } from 'lucide-react';
import Tag from '../../../ui/Tag';
import { BTN_SECONDARY, HINT } from '../shared';
import { BASIS_EXPLANATION, BASIS_LABEL, clampPercent, NOT_ENOUGH_DATA, STATUS_LABEL, STATUS_TONE } from './format.ts';
import type { Basis, StockStatus } from './types.ts';

/** A number that may be unknown. `null` shows a dash that screen readers read as "Not enough data"; `0` shows 0. */
export function Figure({ value, children, why = NOT_ENOUGH_DATA }: { value: number | string | null | undefined; children?: ReactNode; why?: string }) {
  if (value === null || value === undefined) {
    return <span title={why}><span aria-hidden="true">—</span><span className="sr-only">{why}</span></span>;
  }
  return <span className="tabular-nums">{children ?? value}</span>;
}

/** Estimated balances always carry this tag, in the same style as the Estimate tag in the Master Supply Room Inventory. */
export function EstimatedTag() {
  return <span className="rounded-hub-control border border-hub-border px-1.5 text-[12px] font-semibold leading-5 text-hub-n-600" data-testid="estimated-tag">Estimated</span>;
}

/** How the on-hand number is known. Counted reads quietly; Estimated is a tag; Calculated and Not counted say so. */
export function BasisNote({ basis, withWords = false }: { basis: Basis; withWords?: boolean }) {
  // A supply that was never counted already says "Not counted" where its number would be.
  if (basis === 'none' && !withWords) return null;
  if (basis === 'estimate') return <><EstimatedTag />{withWords && <span className={HINT}>{BASIS_EXPLANATION.estimate}</span>}</>;
  return (
    <>
      <span className="text-[13px] leading-5 text-hub-n-600" data-testid={`basis-${basis}`}>{BASIS_LABEL[basis]}</span>
      {withWords && <span className={HINT}>{BASIS_EXPLANATION[basis]}</span>}
    </>
  );
}

export function StockStatusTag({ status }: { status: StockStatus }) {
  return <Tag tone={STATUS_TONE[status]}>{STATUS_LABEL[status]}</Tag>;
}

/** A named block of the screen with a proper heading. */
export function Section({ title, note, children, level = 3, testId, action }: {
  title: string; note?: ReactNode; children: ReactNode; level?: 3 | 4; testId?: string; action?: ReactNode;
}) {
  const id = useId();
  const Heading = level === 3 ? 'h3' : 'h4';
  return (
    <section aria-labelledby={id} className="space-y-3" data-testid={testId}>
      <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <div className="min-w-0 space-y-0.5">
          <Heading id={id} className={`font-heading font-bold text-hub-ink ${level === 3 ? 'text-base' : 'text-[15px]'}`}>{title}</Heading>
          {note && <p className={HINT}>{note}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}

/** Progressive disclosure: closed until asked for. A real button, 44px tall, that says whether it is open. */
export function Disclosure({ title, summary, children, testId, defaultOpen = false }: {
  title: string; summary?: ReactNode; children: ReactNode; testId?: string; defaultOpen?: boolean;
}) {
  const [open, setOpen] = useState(defaultOpen);
  const id = useId();
  return (
    <div className="rounded-hub-control border border-hub-border bg-hub-well" data-testid={testId}>
      <h3 className="m-0">
        <button
          type="button"
          aria-expanded={open}
          aria-controls={`${id}-body`}
          onClick={() => setOpen((value) => !value)}
          className="flex min-h-12 w-full items-center justify-between gap-3 rounded-hub-control px-3.5 py-2 text-left"
        >
          <span className="min-w-0">
            <span className="block font-heading text-[15px] font-bold text-hub-ink">{title}</span>
            {summary && <span className={`${HINT} block`}>{summary}</span>}
          </span>
          <ChevronDown className={`h-5 w-5 flex-none text-hub-n-500 ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
        </button>
      </h3>
      <div id={`${id}-body`} role="region" aria-label={title} hidden={!open} className="border-t border-hub-border-soft px-3.5 py-3">
        {open && children}
      </div>
    </div>
  );
}

/** A label above a number, used in the compact summaries. */
export function Stat({ label, children, hint }: { label: string; children: ReactNode; hint?: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className={HINT}>{label}</dt>
      <dd className="m-0 break-words font-heading text-lg font-bold leading-6 text-hub-ink">{children}</dd>
      {hint && <dd className={`${HINT} m-0`}>{hint}</dd>}
    </div>
  );
}

/** A thin share bar. The number beside it carries the meaning; the bar only reinforces it. */
export function ShareBar({ percent }: { percent: number | null | undefined }) {
  return (
    <span className="block h-2 w-full overflow-hidden rounded-full bg-hub-n-100" aria-hidden="true">
      <span className="block h-full rounded-full bg-hub-blue" style={{ width: `${clampPercent(percent)}%` }} />
    </span>
  );
}

export function BackButton({ onClick, label }: { onClick: () => void; label: string }) {
  return (
    <button type="button" onClick={onClick} className={`${BTN_SECONDARY} gap-2 self-start`} data-testid="supply-data-back">
      <ArrowLeft className="h-4 w-4 flex-none" aria-hidden="true" />
      <span>{label}</span>
    </button>
  );
}

/** A plain link: the browser downloads the workbook. No fetch, so the file is exactly what the server built. */
export function ExportLink({ href }: { href: string }) {
  return (
    <a href={href} download className={`${BTN_SECONDARY} gap-2 no-underline`} data-testid="supply-data-export">
      <Download className="h-4 w-4 flex-none" aria-hidden="true" />
      <span>Export to Excel</span>
    </a>
  );
}

/** Shown under the trend and at the top of a drill-down: what the numbers do and do not mean. */
export function MeaningNote({ label }: { label: string }) {
  return <p className="text-[13px] font-semibold leading-5 text-hub-n-600" data-testid="supply-data-label">{label}</p>;
}
