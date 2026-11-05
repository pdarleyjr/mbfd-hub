import { useCallback, useRef, useState } from 'react';
import { useSearchParams } from 'react-router';
import { ClipboardCheck, ExternalLink, MessageSquare, RefreshCw } from 'lucide-react';
import { COMMAND_300_IMAGE } from '../types';
import { useOffline } from '../hooks/useOffline';
import DailyPageNav from './DailyPageNav';
import Command300ShiftSection from './acting/Command300ShiftSection';
import { useCommand300Shift } from './acting/useCommand300Shift';
import AccessDenied from './command-communications/AccessDenied';
import CommunicationsPanel from './command-communications/CommunicationsPanel';
import DepartmentUpdatesPanel from './department-updates/DepartmentUpdatesPanel';
import { useCommandAccess } from './command-communications/useCommandAccess';
import { useCommandCommunications, type CommandCommunications } from './command-communications/useCommandCommunications';
import Tabs, { type TabItem } from './ui/Tabs';
import StationBoard from './command300/StationBoard';
import StationDetailSheet from './command300/StationDetailSheet';
import { useCommandBoard, useCommandStation } from './command300/useCommandBoard';
import { HUB_TIMEZONE } from '../../../shared/timezone.ts';

/** Morning Lineup for Command is entered through the Employee portal; its PIN flow stays server-side. */
export const COMMAND_LINEUP_HREF = '/employee/video-conferencing/command?return_to=%2Fdaily%2Fcommand-300';
export const COMMAND_BOARD_HREF = 'https://cmd.mbfdhub.com';

const ACTION = 'flex min-h-12 items-center gap-2.5 rounded-hub-control px-4 py-2.5 text-sm font-semibold transition-colors';
const ACTION_PRIMARY = `${ACTION} border border-hub-blue bg-hub-blue text-white hover:bg-hub-blue-hover`;
const ACTION_ORDINARY = `${ACTION} border border-hub-blue/30 bg-hub-well text-hub-blue hover:bg-hub-blue-tint`;
const SECONDARY = 'text-[13px] leading-5 text-hub-n-600';

/** Default tab of the messages section. `?tab=updates` selects Department Updates. */
const COMMS_TAB = 'communications';
const UPDATES_TAB = 'department-updates';
const UPDATES_PARAM = 'updates';

const clock = (iso: string): string => new Date(iso).toLocaleTimeString('en-US', { timeZone: HUB_TIMEZONE, hour: 'numeric', minute: '2-digit' });

const parseStationId = (value: string | null): number | null => (value !== null && /^\d+$/.test(value) ? Number(value) : null);

interface MessagesProps {
  comms: CommandCommunications;
  updatesOpen: boolean;
  onSelect: (id: string) => void;
}

/** Communications and Department Updates, unchanged, below the station board. */
function Messages({ comms, updatesOpen, onSelect }: MessagesProps) {
  const tabs: TabItem<string>[] = [
    { id: COMMS_TAB, label: 'Communications', badge: comms.feed.unreadCount, badgeLabel: 'unread messages' },
    { id: UPDATES_TAB, label: 'Department Updates' },
  ];
  return (
    <div className="hub-panel overflow-hidden" data-testid="command-messages">
      <Tabs
        tabs={tabs}
        active={updatesOpen ? UPDATES_TAB : COMMS_TAB}
        onChange={onSelect}
        label="Communications and Department Updates"
        idPrefix="command-stations"
        panelClassName="p-4 sm:p-5"
        renderPanel={(id) => (id === UPDATES_TAB
          ? <DepartmentUpdatesPanel origin="command_300" onOpenMessages={() => onSelect(COMMS_TAB)} />
          : <CommunicationsPanel comms={comms} onOpenUpdates={() => onSelect(UPDATES_TAB)} />)}
      />
    </div>
  );
}

export default function Command300Page() {
  const access = useCommandAccess();
  const [forbidden, setForbidden] = useState(false);
  const [searchParams, setSearchParams] = useSearchParams();
  const isOffline = useOffline();
  const cards = useRef(new Map<number, HTMLButtonElement | null>());
  const messagesRef = useRef<HTMLElement | null>(null);
  const lastOpened = useRef<number | null>(null);

  const allowed = (access === 'allowed' || access === 'unknown') && !forbidden;
  const onDenied = useCallback(() => setForbidden(true), []);
  const board = useCommandBoard({ enabled: allowed, onForbidden: onDenied });

  const requestedStation = parseStationId(searchParams.get('station'));
  // A deep link waits for the board; an id that is not an active station is dropped once it is known.
  const knownStation = board.data?.stations.find((station) => station.id === requestedStation) ?? null;
  const stationId = requestedStation !== null && (knownStation !== null || board.data === null) ? requestedStation : null;
  if (stationId !== null) lastOpened.current = stationId;
  const detail = useCommandStation(allowed ? stationId : null, onDenied);

  const updatesOpen = searchParams.get('tab') === UPDATES_PARAM;
  const comms = useCommandCommunications({
    active: !updatesOpen,
    enabled: allowed,
    onDenied,
  });
  const { refresh: refreshFeed } = comms.feed;
  const shift = useCommand300Shift({ enabled: allowed });
  const { refresh: refreshShift } = shift;

  const setParams = useCallback((change: (next: URLSearchParams) => void) => {
    const next = new URLSearchParams(searchParams);
    change(next);
    setSearchParams(next, { replace: true, preventScrollReset: true });
  }, [searchParams, setSearchParams]);

  const openStation = useCallback((id: number) => setParams((next) => next.set('station', String(id))), [setParams]);
  const closeStation = useCallback(() => setParams((next) => next.delete('station')), [setParams]);
  const selectTab = useCallback((id: string) => setParams((next) => {
    if (id === UPDATES_TAB) next.set('tab', UPDATES_PARAM);
    else next.delete('tab');
  }), [setParams]);

  const onRefresh = () => {
    board.refresh();
    if (stationId !== null) detail.refresh();
    refreshShift();
    if (!updatesOpen) refreshFeed().catch(() => undefined);
  };

  // The server decides. This only keeps protected content off screen for someone it already refused.
  if (forbidden || access === 'denied') return <AccessDenied />;
  if (access === 'checking') {
    return (
      <div data-hub-ui="2" className="space-y-6 font-hub" role="status" aria-label="Checking access to 300">
        <DailyPageNav fallback="/stations" />
        <span className="hub-skeleton block !h-40 w-full" />
        <span className="hub-skeleton block !h-64 w-full" />
      </div>
    );
  }

  const refreshLabel = isOffline ? 'Refresh unavailable while offline' : board.refreshing ? 'Refreshing the board' : 'Refresh the board';
  const unread = comms.feed.unreadCount;

  return (
    <div data-hub-ui="2" className="space-y-6 font-hub">
      <DailyPageNav fallback="/stations" />

      <section className="hub-panel p-4 sm:p-6" aria-labelledby="command-heading">
        <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
          <img
            src={COMMAND_300_IMAGE}
            alt="Miami Beach Fire Rescue Command SUV"
            width="1200"
            height="800"
            decoding="async"
            className="aspect-[3/2] h-auto w-full max-w-[20rem] flex-none self-center rounded-hub-control bg-hub-n-100 object-cover sm:self-start"
          />
          <div className="min-w-0 flex-1">
            <p className="text-[13px] font-semibold text-hub-n-600">Command unit</p>
            <h1 id="command-heading" className="font-heading text-2xl font-bold text-hub-ink sm:text-3xl">300</h1>
            <div className="mt-2">
              <Command300ShiftSection shift={shift} />
            </div>
            <p className={`mt-2 max-w-prose ${SECONDARY}`}>Shift Division Chief. See every station at a glance below, open one for the details, join the command Morning Lineup, or read your messages and Department Updates.</p>
            <nav aria-label="300 actions" className="mt-4 grid gap-2.5 sm:grid-cols-2">
              <a href={COMMAND_LINEUP_HREF} className={ACTION_PRIMARY}>
                <ClipboardCheck size={20} aria-hidden="true" />
                <span className="min-w-0">Morning Lineup — Command</span>
              </a>
              <a href={COMMAND_BOARD_HREF} target="_blank" rel="noopener noreferrer" className={ACTION_ORDINARY}>
                <ExternalLink size={20} aria-hidden="true" />
                <span className="min-w-0">Open Command Board<span className="sr-only"> (opens in a new tab)</span></span>
              </a>
              {/* Shown only in the installed app, where nothing else says this leaves the Hub (hub-shell.css). */}
              <p className="hub-installed-hint col-span-full">Opens outside the Hub. Tap Done or Close to come back.</p>
            </nav>
          </div>
        </div>
      </section>

      <section aria-labelledby="command-overview-heading" className="space-y-4" data-testid="command-overview">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0">
            <h2 id="command-overview-heading" className="font-heading text-xl font-bold text-hub-ink">Stations</h2>
            <p className={SECONDARY} data-testid="command-board-status">
              {board.data ? `Read only · updated ${clock(board.data.generated_at)} · refreshes every minute` : board.loading ? 'Loading stations…' : 'Read-only view of every active station.'}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              className="hub-btn hub-btn--secondary !min-h-11 gap-2"
              onClick={() => messagesRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
              data-testid="command-jump-messages"
            >
              <MessageSquare className="h-4 w-4" aria-hidden="true" />
              <span>Messages{unread > 0 && <span className="hub-tag hub-tag--info ml-2 tabular-nums">{unread}<span className="sr-only"> unread</span></span>}</span>
            </button>
            <button
              type="button"
              data-testid="command-overview-refresh"
              className="hub-btn hub-btn--secondary !min-h-11 !min-w-11 gap-2"
              onClick={onRefresh}
              disabled={board.refreshing || isOffline || board.loading}
              aria-busy={board.refreshing}
              aria-label={refreshLabel}
              title={refreshLabel}
            >
              <RefreshCw className={`h-4 w-4 ${board.refreshing ? 'motion-safe:animate-spin' : ''}`} aria-hidden="true" />
              <span className="hidden sm:inline">{board.refreshing ? 'Refreshing' : 'Refresh'}</span>
            </button>
          </div>
        </div>

        {board.stale && board.data && (
          <div className="hub-callout hub-callout--warning p-3" role="alert">The board could not be refreshed. What is shown may be out of date.</div>
        )}

        <StationBoard
          board={board.data}
          loading={board.loading}
          error={board.error}
          onRetry={board.retry}
          onOpen={openStation}
          cardRef={(id) => (element) => { cards.current.set(id, element); }}
        />
      </section>

      <section aria-labelledby="command-messages-heading" className="space-y-4" ref={messagesRef}>
        <div>
          <h2 id="command-messages-heading" className="font-heading text-xl font-bold text-hub-ink">Messages</h2>
          <p className={SECONDARY}>Communications with Administration and Captain 5, and Department Updates.</p>
        </div>
        <Messages comms={comms} updatesOpen={updatesOpen} onSelect={selectTab} />
      </section>

      <StationDetailSheet
        stationId={stationId}
        stationName={knownStation?.name ?? 'Station'}
        resource={detail}
        onClose={closeStation}
        returnFocusTo={() => (lastOpened.current === null ? null : cards.current.get(lastOpened.current) ?? null)}
      />
    </div>
  );
}
