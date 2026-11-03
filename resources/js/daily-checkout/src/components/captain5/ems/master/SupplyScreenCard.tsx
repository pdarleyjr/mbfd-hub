import { useCallback, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import Tag from '../../../ui/Tag';
import { BTN, HINT, LiveNote, useEmsLoad } from '../shared';
import { fetchSupplyScreen } from './api';
import type { KioskScreen, ScreenWithdrawal, SupplyScreenData } from './types';

/** Withdrawals shown before "Show all". */
export const SCREEN_WITHDRAWALS_COLLAPSED = 10;

interface Props {
  /** Changes every time the master list gets an answer from the server; the section refreshes with it. */
  tick: number;
  headingLevel?: 2 | 3;
}

export function screenStatusText(screen: KioskScreen): string {
  return `${screen.name || 'Supply room screen'}: ${screen.state_label}${screen.last_seen ? `, last checked in ${screen.last_seen}` : ''}`;
}

export function searchWordsText(count: number): string {
  return `${count} search ${count === 1 ? 'word' : 'words'} to review in Admin > Medical Supply Room > Supply room screen.`;
}

const STATE_CLASS: Record<KioskScreen['state'], string> = {
  online: 'text-hub-success',
  offline: 'text-hub-danger',
  waiting: 'text-hub-n-600',
  revoked: 'text-hub-n-600',
};

function WithdrawalRow({ entry }: { entry: ScreenWithdrawal }) {
  const undone = entry.status === 'undone';
  return (
    <li className="space-y-1 py-2.5" data-testid="screen-withdrawal" data-status={entry.status}>
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span className="text-sm font-semibold tabular-nums text-hub-ink" data-testid="screen-withdrawal-time">{entry.taken_at}</span>
        <span className="min-w-0 text-sm text-hub-ink [overflow-wrap:anywhere]" data-testid="screen-withdrawal-who">
          {entry.employee_name}, {entry.unit_label ?? 'No unit'}
        </span>
        {undone && <Tag tone="neutral"><span data-testid="screen-withdrawal-undone">Undone</span></Tag>}
      </div>
      {entry.lines.length > 0 && (
        <ul className={`space-y-0.5 text-sm ${undone ? 'text-hub-n-600 line-through decoration-1' : 'text-hub-ink'}`}>
          {entry.lines.map((line, index) => (
            <li key={`${line.name}-${index}`} className="flex flex-wrap items-center gap-x-2 [overflow-wrap:anywhere]" data-testid="screen-withdrawal-line">
              <span>{line.name}: {line.quantity} {line.unit_label}</span>
              {line.count_due && <Tag tone="danger"><span data-testid="screen-line-count-due">Count due</span></Tag>}
            </li>
          ))}
        </ul>
      )}
      {entry.note && <p className={`${HINT} [overflow-wrap:anywhere]`} data-testid="screen-withdrawal-note">Note: {entry.note}</p>}
    </li>
  );
}

function ScreenStatus({ screens }: { screens: KioskScreen[] }) {
  const paired = screens.filter((screen) => screen.state !== 'revoked');
  if (paired.length === 0) {
    return <p className="text-sm text-hub-n-600" data-testid="screen-status-none">No screen is paired yet. Pair one from Admin &gt; Medical Supply Room &gt; Supply room screen.</p>;
  }
  return (
    <ul className="space-y-0.5">
      {paired.map((screen) => (
        <li key={screen.id} className={`text-sm font-semibold [overflow-wrap:anywhere] ${STATE_CLASS[screen.state] ?? 'text-hub-n-600'}`}
          data-testid="screen-status" data-state={screen.state}>{screenStatusText(screen)}</li>
      ))}
    </ul>
  );
}

function Body({ data }: { data: SupplyScreenData }) {
  const [showAll, setShowAll] = useState(false);
  const shown = showAll ? data.withdrawals : data.withdrawals.slice(0, SCREEN_WITHDRAWALS_COLLAPSED);
  return (
    <div className="space-y-2" data-testid="screen-body">
      {data.search_words_waiting > 0 && <p className="text-sm font-semibold text-hub-ink" data-testid="screen-search-words">{searchWordsText(data.search_words_waiting)}</p>}
      {data.withdrawals.length === 0 ? (
        <p className={HINT} data-testid="screen-withdrawals-none">Nothing has been taken at the screen yet.</p>
      ) : (
        <>
          <ul className="divide-y divide-hub-border-soft" aria-label="Recent withdrawals at the supply room screen">
            {shown.map((entry) => <WithdrawalRow key={entry.uuid} entry={entry} />)}
          </ul>
          {data.withdrawals.length > SCREEN_WITHDRAWALS_COLLAPSED && (
            <button type="button" className={`${BTN} hub-btn--tertiary`} data-testid="screen-withdrawals-more" aria-expanded={showAll} onClick={() => setShowAll((open) => !open)}>
              {showAll ? 'Show fewer' : `Show all ${data.withdrawals.length}`}
            </button>
          )}
        </>
      )}
    </div>
  );
}

/**
 * "Supply room screen" under the Low or out card: whether the kiosk is checking in, what members took there, and the
 * search words waiting for review. People without inventory access get a 403; the section then stays out of the way.
 */
export default function SupplyScreenCard({ tick, headingLevel = 3 }: Props) {
  const [open, setOpen] = useState(false);
  const [forbidden, setForbidden] = useState(false);
  const onForbidden = useCallback(() => setForbidden(true), []);
  const screen = useEmsLoad<SupplyScreenData>(fetchSupplyScreen, 'supply-screen', { tick, onForbidden });
  const Heading = headingLevel === 2 ? 'h2' : 'h3';
  if (forbidden) return null;
  const { data } = screen;

  return (
    <section aria-labelledby="supply-screen-heading" data-testid="supply-screen" className="space-y-2 rounded-hub-card border border-hub-border bg-hub-n-0 px-3 py-2 sm:px-4">
      <Heading id="supply-screen-heading" className="font-heading text-base font-bold text-hub-ink">
        <button type="button" className="flex min-h-11 w-full items-center justify-between gap-3 text-left" aria-expanded={open} aria-controls="supply-screen-panel"
          data-testid="supply-screen-toggle" onClick={() => setOpen((value) => !value)}>
          <span>Supply room screen</span>
          <ChevronDown className={`h-5 w-5 flex-none transition-transform motion-reduce:transition-none ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
        </button>
      </Heading>
      {data && <ScreenStatus screens={data.screens} />}
      {!data && screen.loading && <p className={HINT}>Checking the supply room screen.</p>}
      {screen.error && (
        <LiveNote tone="warning" testId="supply-screen-error">
          {data ? `The supply room screen could not be refreshed: ${screen.error}` : `The supply room screen could not be loaded: ${screen.error}`}
        </LiveNote>
      )}
      <div id="supply-screen-panel" hidden={!open}>
        {open && data && <Body data={data} />}
      </div>
    </section>
  );
}
