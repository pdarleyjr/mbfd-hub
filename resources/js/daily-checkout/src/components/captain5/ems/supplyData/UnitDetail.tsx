import { ChevronRight } from 'lucide-react';
import { EmptyState } from '../../../ui/StateViews';
import { humanize } from '../../../ui/status';
import Tag from '../../../ui/Tag';
import { etDate, etDateTime } from '../logic';
import { HINT } from '../shared';
import DetailShell from './DetailShell.tsx';
import { fetchUnit } from './api.ts';
import { DISTRIBUTED_LABEL, formatNumber, periodText, plural, quantityText } from './format.ts';
import { exportHref, selectionKey } from './params.ts';
import TrendChart from './TrendChart.tsx';
import type { PeriodSelection, UnitReport } from './types.ts';
import { ExportLink, MeaningNote, Section, Stat } from './ui.tsx';

interface Props {
  unitKey: string;
  selection: PeriodSelection;
  /** Only people who can see Submitted Inventory Forms get the link to a request. */
  canOpenRequests: boolean;
  onOpenRequest: (uuid: string) => void;
  onOpenItem: (itemId: number) => void;
  onBack: () => void;
  onForbidden: () => void;
}

const STATUS_TONE: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  reconciled: 'success', submitted: 'warning', reviewed: 'warning', prepared: 'warning', returned: 'danger', cancelled: 'neutral', superseded: 'neutral', closed: 'neutral',
};

function Requests({ report, canOpen, onOpen }: { report: UnitReport; canOpen: boolean; onOpen: (uuid: string) => void }) {
  if (report.requests.length === 0) {
    return <EmptyState title="No requests in this period" body="When this unit submits a medical inventory form it is listed here, newest first." />;
  }
  return (
    <ul className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border bg-hub-well" aria-label="Requests" data-testid="unit-requests">
      {report.requests.map((request) => {
        const body = (
          <>
            <span className="flex min-w-0 flex-1 flex-col gap-1">
              <span className="flex flex-wrap items-center gap-2">
                <span className="font-semibold text-hub-ink">{request.number}</span>
                <Tag tone={STATUS_TONE[request.status] ?? 'neutral'}>{humanize(request.status)}</Tag>
              </span>
              <span className={HINT}>
                {request.submitted_at ? `Submitted ${etDateTime(request.submitted_at)}` : 'Submission time not recorded'} · {request.lines} {plural(request.lines, 'line')} · {formatNumber(request.qty_requested)} asked for
              </span>
              {request.reconciled_at && <span className={HINT}>Reconciled {etDate(request.reconciled_at)}</span>}
            </span>
            {canOpen && <ChevronRight className="h-5 w-5 flex-none text-hub-n-500" aria-hidden="true" />}
          </>
        );
        return (
          <li key={request.uuid}>
            {canOpen ? (
              <button type="button" onClick={() => onOpen(request.uuid)} aria-label={`${request.number}, ${humanize(request.status)}. Open the request.`} className="flex min-h-14 w-full min-w-0 items-center gap-3 rounded-hub-control px-3.5 py-3 text-left hover:bg-hub-blue-tint">{body}</button>
            ) : (
              <div className="flex min-h-14 items-center gap-3 px-3.5 py-3">{body}</div>
            )}
          </li>
        );
      })}
    </ul>
  );
}

/** One unit: what it asked for, what it was given, how its weeks look and its requests, each linking to the existing request view. */
export default function UnitDetail({ unitKey, selection, canOpenRequests, onOpenRequest, onOpenItem, onBack, onForbidden }: Props) {
  return (
    <DetailShell<UnitReport>
      key={unitKey}
      noun="This unit"
      loadKey={`unit:${unitKey}:${selectionKey(selection)}`}
      load={() => fetchUnit(unitKey, selection)}
      backLabel="Back to Supply Data"
      onBack={onBack}
      onForbidden={onForbidden}
    >
      {(report) => (
        <div className="space-y-6" data-testid="unit-detail">
          <div className="space-y-1.5">
            <h2 tabIndex={-1} data-detail-heading className="font-heading text-xl font-bold text-hub-ink outline-none sm:text-2xl">{report.unit.label}</h2>
            <p className={HINT}>{report.unit.kind === 'detail' ? 'Detail / Special Event requests' : report.unit.kind === 'unassigned' ? 'Requests whose unit is no longer on file' : 'Unit'} · {periodText(report.period)}</p>
            <MeaningNote label={DISTRIBUTED_LABEL} />
          </div>

          <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-hub-control border border-hub-border bg-hub-well p-3 sm:grid-cols-4 sm:p-4" data-testid="unit-summary">
            <Stat label="Requests">{formatNumber(report.summary.requests)}</Stat>
            <Stat label="Reconciled">{formatNumber(report.summary.requests_reconciled)}</Stat>
            <Stat label="Asked for, in total">{formatNumber(report.summary.qty_requested)}</Stat>
            <Stat label="Distribution events">{formatNumber(report.summary.distributed_events)}</Stat>
            <Stat label="Last restocked">{report.summary.last_replenished_at ? etDate(report.summary.last_replenished_at) : 'No restock recorded'}</Stat>
          </dl>

          <Section title="Most requested" testId="unit-top-requested" note="By quantity asked for on forms that counted.">
            {report.top_requested.length === 0 ? (
              <p className="text-sm text-hub-n-800">Nothing was requested in this period.</p>
            ) : (
              <ol className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border bg-hub-well">
                {report.top_requested.map((row) => (
                  <li key={row.item_key} className="flex flex-wrap items-baseline justify-between gap-x-3 px-3.5 py-2.5">
                    <span className="min-w-0 break-words font-semibold text-hub-ink">{row.label}</span>
                    <span className="text-sm tabular-nums text-hub-ink">{formatNumber(row.qty_requested)} on {row.requests} {plural(row.requests, 'request')}</span>
                  </li>
                ))}
              </ol>
            )}
          </Section>

          <Section title="Most distributed" testId="unit-top-distributed" note="What actually left the supply room for this unit.">
            {report.top_distributed.length === 0 ? (
              <p className="text-sm text-hub-n-800">Nothing was distributed to this unit in this period.</p>
            ) : (
              <ol className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border bg-hub-well">
                {report.top_distributed.map((row) => (
                  <li key={row.item_id}>
                    <button type="button" onClick={() => onOpenItem(row.item_id)} className="flex min-h-12 w-full min-w-0 items-center justify-between gap-3 rounded-hub-control px-3.5 py-2.5 text-left hover:bg-hub-blue-tint">
                      <span className="min-w-0 break-words font-semibold text-hub-ink">{row.name}</span>
                      <span className="flex flex-none items-center gap-2 text-sm tabular-nums text-hub-ink">
                        {quantityText(row.distributed, row.count_unit)} · {row.distribution_events} {plural(row.distribution_events, 'event')}
                        <ChevronRight className="h-5 w-5 text-hub-n-500" aria-hidden="true" />
                      </span>
                    </button>
                  </li>
                ))}
              </ol>
            )}
          </Section>

          <Section title="Weekly pattern" testId="unit-pattern">
            {report.trend.some((bucket) => bucket.distribution_events > 0 || (bucket.requests ?? 0) > 0) ? (
              <TrendChart
                title={report.trend[0]?.month_start !== undefined ? 'Distribution events by month' : 'Distribution events by week'}
                buckets={report.trend}
                noun="distribution event"
                columns={[
                  { name: 'Distribution events', value: (bucket) => bucket.distribution_events },
                  { name: 'Requests', value: (bucket) => bucket.requests },
                ]}
              />
            ) : (
              <p className="text-sm text-hub-n-800" data-testid="unit-trend-empty">No requests or distributions in this period, so there is no pattern to draw yet.</p>
            )}
          </Section>

          <Section title="Request history" testId="unit-request-history" note={canOpenRequests ? 'Newest 50 in this period. Open one to see the form itself.' : 'Newest 50 in this period.'}>
            <Requests report={report} canOpen={canOpenRequests} onOpen={onOpenRequest} />
          </Section>

          <ExportLink href={exportHref(selection, { unitKey })} />
        </div>
      )}
    </DetailShell>
  );
}
