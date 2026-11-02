import Tag from '../../../ui/Tag';
import { humanize } from '../../../ui/status';
import { etDate, etDateTime } from '../logic';
import { HINT } from '../shared';
import DetailShell from './DetailShell.tsx';
import { fetchItem } from './api.ts';
import { changeSentence, DISTRIBUTED_LABEL, daysOfSupplyText, formatNumber, periodText, plural, quantityText, unitWord } from './format.ts';
import { exportHref, selectionKey } from './params.ts';
import TrendChart from './TrendChart.tsx';
import type { ItemReport, PeriodSelection } from './types.ts';
import { BasisNote, Disclosure, EstimatedTag, ExportLink, Figure, MeaningNote, Section, ShareBar, Stat, StockStatusTag } from './ui.tsx';

interface Props {
  itemId: number;
  selection: PeriodSelection;
  /** "Back to Supply Data", or "Back to unit" when the supply was opened from a unit's page. */
  backLabel: string;
  onBack: () => void;
  onForbidden: () => void;
}

const signed = (quantity: number): string => (quantity > 0 ? `+${formatNumber(quantity)}` : quantity < 0 ? `−${formatNumber(Math.abs(quantity))}` : '0');

function Balance({ report }: { report: ItemReport }) {
  const { balance, item } = report;
  return (
    <Section title="On hand now" testId="item-balance" note="Today's number, not tied to the time period.">
      <div className="rounded-hub-control border border-hub-border bg-hub-well p-3 sm:p-4">
        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          {balance.on_hand === null
            ? <span className="text-lg font-bold text-hub-n-600" data-testid="item-on-hand">Not counted</span>
            : <span className="font-heading text-3xl font-bold leading-9 tabular-nums text-hub-ink" data-testid="item-on-hand">{quantityText(balance.on_hand, item.count_unit)}</span>}
          <BasisNote basis={balance.basis} />
          <StockStatusTag status={balance.status} />
          {balance.needs_reorder && <Tag tone="warning">Needs reorder</Tag>}
        </div>
        {balance.basis === 'estimate' && <p className={`${HINT} mt-1`} data-testid="item-estimate-note">This is a best-guess starting number that has not been counted yet. It is not a verified count.</p>}
        {!item.units_resolved && <p className={`${HINT} mt-1`} data-testid="item-units-note">The count per {item.package_unit ?? 'package'} is not on file, so quantities here are in single units.</p>}
        <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-hub-border-soft pt-3 sm:grid-cols-3">
          <Stat label="Last counted">{balance.last_counted_at ? etDate(balance.last_counted_at) : 'No physical count yet'}</Stat>
          <Stat label="Taken out since the count"><Figure value={balance.deductions_since_count}>{balance.deductions_since_count === null ? null : formatNumber(balance.deductions_since_count)}</Figure></Stat>
          <Stat label="Minimum / order level" hint="One level: below it, an order is suggested.">
            <Figure value={balance.minimum} why="No minimum set">{balance.minimum === null ? null : quantityText(balance.minimum, item.count_unit)}</Figure>
          </Stat>
          <Stat label="Days of supply" hint={balance.days_of_supply === null ? 'Needs a counted balance and at least 3 distributions.' : 'At the recent distribution pace.'}>
            <Figure value={balance.days_of_supply}>{balance.days_of_supply === null ? null : daysOfSupplyText(balance.days_of_supply)}</Figure>
          </Stat>
          <Stat label="Suggested order"><Figure value={balance.recommended_order} why="No order suggested">{balance.recommended_order === null ? null : quantityText(balance.recommended_order, balance.recommended_order_unit ?? item.count_unit)}</Figure></Stat>
        </dl>
      </div>
    </Section>
  );
}

function Distribution({ report }: { report: ItemReport }) {
  const { distribution: d, item } = report;
  return (
    <Section title="Distributed in this period" testId="item-distribution">
      <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-hub-control border border-hub-border bg-hub-well p-3 sm:grid-cols-3 sm:p-4">
        <Stat label="Quantity distributed">{quantityText(d.distributed, item.count_unit)}</Stat>
        <Stat label="Distribution events" hint={changeSentence(d.change_pct, d.previous_distribution_events).text}>{formatNumber(d.distribution_events)}</Stat>
        <Stat label="Last distributed">{d.last_distributed_at ? etDate(d.last_distributed_at) : 'None yet'}</Stat>
        <Stat label="Average per week"><Figure value={d.avg_weekly}>{d.avg_weekly === null ? null : formatNumber(d.avg_weekly)}</Figure></Stat>
        <Stat label="Typical week"><Figure value={d.median_weekly} why="Not worked out for monthly periods">{d.median_weekly === null ? null : formatNumber(d.median_weekly)}</Figure></Stat>
      </dl>
      {report.trend.some((bucket) => bucket.distribution_events > 0) ? (
        <TrendChart
          title={report.trend[0]?.month_start !== undefined ? 'Distribution events by month' : 'Distribution events by week'}
          buckets={report.trend}
          noun="distribution event"
          columns={[
            { name: 'Distribution events', value: (bucket) => bucket.distribution_events },
            { name: 'Quantity', value: (bucket) => bucket.distributed },
          ]}
        />
      ) : (
        <p className="text-sm text-hub-n-800" data-testid="item-trend-empty">This supply was not distributed in this period, so there is no trend to draw yet.</p>
      )}
    </Section>
  );
}

function ReceivingUnits({ report }: { report: ItemReport }) {
  if (report.units.length === 0) return null;
  return (
    <Section title="Which units received it" testId="item-units">
      <ul className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border bg-hub-well">
        {report.units.map((unit) => (
          <li key={unit.unit_key} className="space-y-1.5 px-3.5 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
              <span className="min-w-0 break-words font-semibold text-hub-ink">{unit.label}</span>
              <span className="text-sm tabular-nums text-hub-ink">{unit.share_pct === null ? <Figure value={null} /> : `${formatNumber(unit.share_pct)}%`}</span>
            </div>
            <ShareBar percent={unit.share_pct} />
            <p className={HINT}>{quantityText(unit.distributed, report.item.count_unit)} · {unit.distribution_events} {plural(unit.distribution_events, 'distribution event')}</p>
          </li>
        ))}
      </ul>
    </Section>
  );
}

function Replenishment({ report }: { report: ItemReport }) {
  return (
    <Section title="Restocked" testId="item-replenishment">
      {report.replenishment.length === 0 ? (
        <p className="text-sm text-hub-n-800">Nothing was received or added in this period.</p>
      ) : (
        <ul className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border bg-hub-well">
          {report.replenishment.map((entry, index) => (
            <li key={`${entry.occurred_at}-${index}`} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 px-3.5 py-2.5">
              <span className="text-sm font-semibold text-hub-ink">{entry.type === 'received' ? 'Received' : 'Adjusted'} · {signed(entry.quantity)} {unitWord(report.item.count_unit, Math.abs(entry.quantity))}</span>
              <span className={HINT}>{etDate(entry.occurred_at)}{entry.reason ? ` · ${entry.reason}` : ''}</span>
            </li>
          ))}
        </ul>
      )}
    </Section>
  );
}

function CountHistory({ report }: { report: ItemReport }) {
  return (
    <Section title="Count history" testId="item-counts" note="A count is compared with what the books expected only when the count before it was also a physical count.">
      {report.counts.length === 0 ? (
        <p className="text-sm text-hub-n-800">No physical count has been recorded for this supply in this period.</p>
      ) : (
        <ul className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border bg-hub-well">
          {report.counts.map((entry) => (
            <li key={entry.counted_at} className="space-y-0.5 px-3.5 py-2.5 text-sm">
              <p className="font-semibold text-hub-ink">{etDate(entry.counted_at)}</p>
              <p className="text-hub-ink">
                {entry.verified === null && entry.estimated !== null
                  ? <>Estimate <span className="tabular-nums">{quantityText(entry.estimated, report.item.count_unit)}</span> <EstimatedTag /></>
                  : <>Counted <Figure value={entry.verified}>{entry.verified === null ? null : quantityText(entry.verified, report.item.count_unit)}</Figure></>}
                {' · '}Expected <Figure value={entry.expected} why="Not enough data to say what was expected">{entry.expected === null ? null : formatNumber(entry.expected)}</Figure>
                {' · '}Difference <Figure value={entry.variance} why="Not compared">{entry.variance === null ? null : signed(entry.variance)}</Figure>
              </p>
              {entry.basis_note && <p className={HINT}>{entry.basis_note}</p>}
            </li>
          ))}
        </ul>
      )}
    </Section>
  );
}

function Movements({ report }: { report: ItemReport }) {
  return (
    <Disclosure title="Supporting movements" summary={`${report.movements.length} ${plural(report.movements.length, 'movement')} in this period`} testId="item-movements">
      {report.movements.length === 0 ? (
        <p className="text-sm text-hub-n-800">No stock movements in this period.</p>
      ) : (
        <>
          <ul className="divide-y divide-hub-border-soft">
            {report.movements.map((movement) => (
              <li key={movement.id} className="space-y-0.5 py-2.5 text-sm">
                <p className="flex flex-wrap items-baseline justify-between gap-x-3">
                  <span className="font-semibold text-hub-ink">{humanize(movement.type)} · <span className="tabular-nums">{signed(movement.quantity)}</span></span>
                  <span className={HINT}>{etDateTime(movement.occurred_at)}</span>
                </p>
                <p className={HINT}>{[movement.unit_label, movement.request_number, movement.reason].filter(Boolean).join(' · ') || 'No unit or reason recorded'}{movement.corrects_movement_id !== null ? ' · Correction' : ''}{movement.reversed ? ' · Reversed later' : ''}</p>
              </li>
            ))}
          </ul>
          {report.movements_truncated && <p className={`${HINT} mt-2`}>Showing the newest 50 in this period. Export to Excel for the full set.</p>}
        </>
      )}
    </Disclosure>
  );
}

/** One supply: what is on the shelf, how it moved in the period, who received it, restocks, counts and the movements behind them. */
export default function ItemDetail({ itemId, selection, backLabel, onBack, onForbidden }: Props) {
  return (
    <DetailShell<ItemReport>
      key={itemId}
      noun="This supply"
      loadKey={`item:${itemId}:${selectionKey(selection)}`}
      load={() => fetchItem(itemId, selection)}
      backLabel={backLabel}
      onBack={onBack}
      onForbidden={onForbidden}
    >
      {(report) => (
        <div className="space-y-6" data-testid="item-detail">
          <div className="space-y-1.5">
            <h2 tabIndex={-1} data-detail-heading className="font-heading text-xl font-bold text-hub-ink outline-none sm:text-2xl">{report.item.name}</h2>
            <p className={HINT}>{[report.item.category, report.item.location, report.item.count_unit === 'each' ? 'Counted one by one' : `Counted in ${unitWord(report.item.count_unit, 2)}`].filter(Boolean).join(' · ')}</p>
            <p className="flex flex-wrap items-center gap-2 text-sm text-hub-n-800">
              <span>{periodText(report.period)}</span>
              {!report.item.active && <Tag>Not in use</Tag>}
            </p>
            <MeaningNote label={report.label || DISTRIBUTED_LABEL} />
          </div>
          <Balance report={report} />
          <Distribution report={report} />
          <ReceivingUnits report={report} />
          <Replenishment report={report} />
          <CountHistory report={report} />
          <Movements report={report} />
          <ExportLink href={exportHref(selection, { itemId })} />
        </div>
      )}
    </DetailShell>
  );
}
