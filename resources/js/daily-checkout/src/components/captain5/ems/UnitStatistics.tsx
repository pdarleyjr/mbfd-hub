import { useMemo, useState, type ReactNode } from 'react';
import { BarChart3 } from 'lucide-react';
import { fetchStatistics } from '../../../utils/emsApi';
import { EmptyState, LoadError, SkeletonRows } from '../../ui/StateViews';
import {
  STATS_FOOTNOTE, dateRangeError, defaultStatsRange, etDate, monthLabel, perWeekText, statsIsEmpty, statsQuery,
} from './logic';
import { FIELD, HINT, LABEL, LiveNote, useEmsLoad } from './shared';
import type { EmsMeta, StatisticsReport } from './types';

const KINDS = [
  { id: '', label: 'All kinds' },
  { id: 'rescue_unit', label: 'Rescue units' },
  { id: 'other_unit', label: 'Other units' },
  { id: 'detail_event', label: 'Detail / Special Event' },
];

function Section({ title, definition, children, testId }: { title: string; definition?: string; children: ReactNode; testId?: string }) {
  return (
    <section className="space-y-2" data-testid={testId} aria-label={title}>
      <h3 className="font-heading text-base font-bold text-hub-ink">{title}</h3>
      {definition && <p className={HINT}>{definition}</p>}
      {children}
    </section>
  );
}

const TH = 'px-3 py-2 text-left text-[13px] font-semibold text-hub-n-600';
const TD = 'px-3 py-2 text-sm text-hub-ink';
const NUM = `${TD} text-right tabular-nums`;

function Table({ caption, head, children }: { caption: string; head: Array<{ label: string; numeric?: boolean }>; children: ReactNode }) {
  return (
    <div className="overflow-x-auto rounded-hub-control border border-hub-border">
      <table className="w-full min-w-[22rem] border-collapse">
        <caption className="sr-only">{caption}</caption>
        <thead className="bg-hub-n-25"><tr>{head.map((cell) => <th key={cell.label} scope="col" className={`${TH} ${cell.numeric ? '!text-right' : ''}`}>{cell.label}</th>)}</tr></thead>
        <tbody className="divide-y divide-hub-border">{children}</tbody>
      </table>
    </div>
  );
}

function Report({ report }: { report: StatisticsReport }) {
  const d = report.definitions;
  const t = report.totals;
  const rows: Array<[string, string, string | undefined]> = [
    ['Requests (not cancelled)', String(t.requests_counted), d.requests_counted],
    ['Cancelled by the member', String(t.requests_cancelled), d.requests_cancelled],
    ['Items requested', String(t.lines_requested), d.lines_requested],
    ['Quantity requested', String(t.qty_requested), d.qty_requested],
    ['Quantity issued (fulfilled)', String(t.qty_issued), d.qty_issued],
    ['Outstanding right now', String(t.qty_outstanding), d.qty_outstanding],
    ['Request frequency', perWeekText(t.requests_per_week), d.requests_per_week],
    ['Yes answers', String(t.yes_answers), d.yes_answers],
  ];
  return (
    <div className="space-y-6">
      <Section title="Totals" testId="ems-stats-totals">
        <dl className="divide-y divide-hub-border rounded-hub-control border border-hub-border">
          {rows.map(([label, value, definition]) => (
            <div key={label} className="space-y-0.5 px-3 py-2.5" data-testid="ems-stat">
              <div className="flex items-baseline justify-between gap-3"><dt className="text-sm font-semibold text-hub-ink">{label}</dt><dd className="text-base font-bold tabular-nums text-hub-ink">{value}</dd></div>
              {definition && <p className={HINT}>{definition}</p>}
            </div>
          ))}
        </dl>
      </Section>

      <Section title="Most common items" definition={d.top_requested_items} testId="ems-stats-top">
        {report.top_requested_items.length === 0 ? <p className={HINT}>No items were requested in this range.</p> : (
          <Table caption="Most requested items" head={[{ label: 'Item' }, { label: 'Requested', numeric: true }, { label: 'Issued', numeric: true }, { label: 'Requests', numeric: true }]}>
            {report.top_requested_items.map((item) => <tr key={`${item.bag_key}|${item.item_key}`}><td className={TD}>{item.label}</td><td className={NUM}>{item.qty_requested}</td><td className={NUM}>{item.qty_issued}</td><td className={NUM}>{item.requests}</td></tr>)}
          </Table>
        )}
      </Section>

      <Section title="Month by month" definition={d.monthly_trend} testId="ems-stats-monthly">
        <Table caption="Requests by month" head={[{ label: 'Month' }, { label: 'Requests', numeric: true }, { label: 'Requested', numeric: true }, { label: 'Issued', numeric: true }]}>
          {report.monthly_trend.map((month) => <tr key={month.month}><td className={TD}>{monthLabel(month.month)}</td><td className={NUM}>{month.requests}</td><td className={NUM}>{month.qty_requested}</td><td className={NUM}>{month.qty_issued}</td></tr>)}
        </Table>
      </Section>

      <Section title="By unit" definition={d.by_unit} testId="ems-stats-units">
        <Table caption="Requests by unit" head={[{ label: 'Unit' }, { label: 'Requests', numeric: true }, { label: 'Requested', numeric: true }, { label: 'Issued', numeric: true }]}>
          {report.by_unit.map((unit) => <tr key={`${unit.apparatus_id ?? 'detail'}-${unit.label}`}><td className={TD}>{unit.label}</td><td className={NUM}>{unit.requests}</td><td className={NUM}>{unit.qty_requested}</td><td className={NUM}>{unit.qty_issued}</td></tr>)}
        </Table>
      </Section>

      <Section title="Detail / Special Event" definition={d.detail_special_event} testId="ems-stats-events">
        <p className="text-sm text-hub-ink">
          {report.detail_special_event.requests} {report.detail_special_event.requests === 1 ? 'request' : 'requests'} for a detail or special event
          {report.detail_special_event.without_event_text > 0 ? `, ${report.detail_special_event.without_event_text} with no event typed` : ''}.
        </p>
        {report.detail_special_event.events.length > 0 && (
          <Table caption="Detail and special events" head={[{ label: 'Event or assignment' }, { label: 'Requests', numeric: true }]}>
            {report.detail_special_event.events.map((event) => <tr key={event.event_assignment}><td className={TD}>{event.event_assignment}</td><td className={NUM}>{event.requests}</td></tr>)}
          </Table>
        )}
      </Section>
    </div>
  );
}

/** Unit statistics: requests and issuances for a date range, with the server's written definition under every figure. */
export default function UnitStatistics({ meta, onForbidden }: { meta: EmsMeta; onForbidden: () => void }) {
  const initial = useMemo(() => defaultStatsRange(), []);
  const [range, setRange] = useState(initial);
  const [unit, setUnit] = useState('');
  const [kind, setKind] = useState('');
  const rangeError = dateRangeError(range.from, range.to);
  const query = statsQuery(rangeError ? initial : range, unit, kind);
  const { data, error, loading, reload } = useEmsLoad(() => fetchStatistics(query), query, { onForbidden });
  const units = meta.units.filter((entry) => entry.id !== null);

  return (
    <div className="space-y-5" data-testid="ems-statistics">
      <p className="text-sm font-semibold text-hub-ink" data-testid="ems-stats-footnote">{STATS_FOOTNOTE}</p>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label className={LABEL} htmlFor="ems-stats-from">From date</label>
          <input id="ems-stats-from" type="date" className={FIELD} value={range.from} aria-invalid={rangeError ? true : undefined} onChange={(event) => setRange((current) => ({ ...current, from: event.target.value }))} />
        </div>
        <div>
          <label className={LABEL} htmlFor="ems-stats-to">To date</label>
          <input id="ems-stats-to" type="date" className={FIELD} value={range.to} aria-invalid={rangeError ? true : undefined} onChange={(event) => setRange((current) => ({ ...current, to: event.target.value }))} />
        </div>
        <div>
          <label className={LABEL} htmlFor="ems-stats-unit">Unit</label>
          <select id="ems-stats-unit" className={FIELD} value={unit} onChange={(event) => setUnit(event.target.value)}>
            <option value="">All units</option>
            {units.map((entry) => <option key={entry.id} value={String(entry.id)}>{entry.label}</option>)}
          </select>
        </div>
        <div>
          <label className={LABEL} htmlFor="ems-stats-kind">Kind of request</label>
          <select id="ems-stats-kind" className={FIELD} value={kind} onChange={(event) => setKind(event.target.value)}>
            {KINDS.map((entry) => <option key={entry.id} value={entry.id}>{entry.label}</option>)}
          </select>
        </div>
      </div>
      <p className={HINT}>The window starts as the last 90 days. Dates are Eastern time.</p>
      {rangeError && <p role="alert" className="text-[13px] font-semibold text-hub-danger">{rangeError} Showing the last 90 days until you fix it.</p>}

      {error && data && <LiveNote tone="warning">The figures could not be refreshed: {error} What you see may be out of date.</LiveNote>}
      {error && !data && <LoadError title="Statistics could not be loaded" message={error} onRetry={() => void reload(false)} />}
      {!error && loading && !data && <SkeletonRows rows={4} />}

      {data && (
        <div aria-busy={loading} className="space-y-4">
          <p className={HINT} data-testid="ems-stats-range">
            Requests submitted {data.filters.date_from ? etDate(`${data.filters.date_from}T12:00:00-05:00`) : 'from the first request'} to {data.filters.date_to ? etDate(`${data.filters.date_to}T12:00:00-05:00`) : 'now'}. {data.label}.
          </p>
          {statsIsEmpty(data)
            ? <EmptyState icon={<BarChart3 className="hub-empty__icon" aria-hidden="true" />} title="No requests in this range" body="Nothing was requested for these filters. Widen the dates, or choose All units and All kinds. Cancelled requests are not counted." />
            : <Report report={data} />}
        </div>
      )}
    </div>
  );
}
