import { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ClipboardCheck, ExternalLink, RefreshCw } from 'lucide-react';
import { COMMAND_300_IMAGE } from '../types';
import type { CommandOverview, CommandOverviewApparatus, CommandOverviewStation } from '../types';
import { ApiClient, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../utils/api';
import { isForbiddenError } from '../utils/commandCommunicationsApi';
import { useOffline } from '../hooks/useOffline';
import DailyPageNav from './DailyPageNav';
import AccessDenied from './command-communications/AccessDenied';
import CommunicationsPanel from './command-communications/CommunicationsPanel';
import DepartmentUpdatesPanel from './department-updates/DepartmentUpdatesPanel';
import { useCommandAccess } from './command-communications/useCommandAccess';
import { useCommandCommunications, type CommandCommunications } from './command-communications/useCommandCommunications';
import Tabs, { type TabItem } from './ui/Tabs';
import Tag, { StatusTag } from './ui/Tag';
import { LoadError } from './ui/StateViews';
import type { Tone } from './ui/status';

/** Morning Lineup for Command is entered through the Employee portal; its PIN flow stays server-side. */
export const COMMAND_LINEUP_HREF = '/employee/video-conferencing/command?return_to=%2Fdaily%2Fcommand-300';
export const COMMAND_BOARD_HREF = 'https://cmd.mbfdhub.com';

/** A checkout older than this is stale, not healthy. */
const STALE_AFTER_HOURS = 36;

/** First and default tab of the card. Every other tab id is a station id, except Department Updates. */
const COMMS_TAB = 'communications';
const UPDATES_TAB = 'department-updates';
/** `?tab=updates` selects the Department Updates tab; `?station=` still selects a station. */
const UPDATES_PARAM = 'updates';

const ACTION = 'flex min-h-12 items-center gap-2.5 rounded-hub-control px-4 py-2.5 text-sm font-semibold transition-colors';
const ACTION_PRIMARY = `${ACTION} border border-hub-blue bg-hub-blue text-white hover:bg-hub-blue-hover`;
const ACTION_ORDINARY = `${ACTION} border border-hub-blue/30 bg-hub-well text-hub-blue hover:bg-hub-blue-tint`;
const SECONDARY = 'text-[13px] leading-5 text-hub-n-600';
const ROW_GRID = 'md:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_5.5rem_5.5rem_minmax(0,1.3fr)]';

const formatDateTime = (iso: string): string => new Date(iso).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });

/** Unknown and stale are shown as such; only a recent recorded checkout reads as healthy. */
function freshness(iso: string | null): { tone: Tone; text: string } {
  if (!iso) return { tone: 'neutral', text: 'No checkout recorded' };
  const ageHours = (Date.now() - new Date(iso).getTime()) / 3_600_000;
  return ageHours > STALE_AFTER_HOURS
    ? { tone: 'warning', text: `Stale · ${formatDateTime(iso)}` }
    : { tone: 'success', text: formatDateTime(iso) };
}

function serviceTag(unit: CommandOverviewApparatus) {
  if (unit.service_state === 'in_service') return <StatusTag status="in_service" />;
  if (unit.service_state === 'out_of_service') return <StatusTag status="out_of_service" />;
  return <Tag>{unit.status ? `Status: ${unit.status}` : 'Status unknown'}</Tag>;
}

function UnitRow({ unit }: { unit: CommandOverviewApparatus }) {
  const checkout = freshness(unit.last_checkout_at);
  return (
    <li className={`grid gap-2 border-t border-hub-border py-3 md:items-center md:gap-4 ${ROW_GRID}`}>
      <div className="min-w-0">
        <p className="font-semibold text-hub-ink">{unit.name || unit.unit_id || 'Unit'}</p>
        <p className={`capitalize ${SECONDARY}`}>{unit.type || 'apparatus'}</p>
      </div>
      <div>{serviceTag(unit)}</div>
      <p className="tabular-nums"><span className={`md:sr-only ${SECONDARY}`}>Open defects: </span>{unit.open_defects}</p>
      <p className="tabular-nums"><span className={`md:sr-only ${SECONDARY}`}>Open tickets: </span>{unit.open_service_tickets}</p>
      <div className="flex flex-wrap items-center gap-2">
        {unit.daily_checkout_state && <StatusTag status={unit.daily_checkout_state} />}
        <Tag tone={checkout.tone}>{checkout.text}</Tag>
      </div>
    </li>
  );
}

function StationSection({ station }: { station: CommandOverviewStation }) {
  const checkout = freshness(station.last_checkout_at);
  const { daily_checkout: summary } = station;
  return (
    <section aria-labelledby={`command-station-${station.id}`} data-testid="command-station">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 id={`command-station-${station.id}`} className="font-heading text-lg font-bold text-hub-ink">{station.name}</h3>
          <p className={SECONDARY}>
            {station.apparatus_count} {station.apparatus_count === 1 ? 'apparatus' : 'apparatuses'}
            {summary.required_total > 0 ? ` · ${summary.completed} of ${summary.required_total} checked today` : ' · No daily checkouts required'}
          </p>
        </div>
        <Link to={`/stations/${station.id}?return_to=${encodeURIComponent(`/daily/command-300?station=${station.id}`)}`} className="hub-btn hub-btn--secondary !min-h-11" aria-label={`Open ${station.name}`}>
          View station
        </Link>
      </div>
      <div className="mt-3 flex flex-wrap gap-2">
        <Tag tone={station.open_defects > 0 ? 'warning' : 'success'}>{station.open_defects} open {station.open_defects === 1 ? 'defect' : 'defects'}</Tag>
        <Tag tone={station.open_service_tickets > 0 ? 'info' : 'neutral'}>{station.open_service_tickets} open service {station.open_service_tickets === 1 ? 'ticket' : 'tickets'}</Tag>
        <Tag tone={checkout.tone}>Last checkout: {checkout.text}</Tag>
      </div>
      {station.apparatuses.length > 0 ? (
        <ul className="mt-4" aria-label={`${station.name} apparatus`}>
          <li aria-hidden="true" className={`hidden gap-4 pb-2 text-[13px] font-semibold text-hub-n-600 md:grid ${ROW_GRID}`}>
            <span>Unit</span><span>Status</span><span>Defects</span><span>Tickets</span><span>Checkout</span>
          </li>
          {station.apparatuses.map((unit) => <UnitRow key={unit.id} unit={unit} />)}
        </ul>
      ) : <p className={`mt-4 ${SECONDARY}`}>No apparatus is assigned to this station.</p>}
    </section>
  );
}

function StationPanelSkeleton() {
  return (
    <div className="space-y-3" role="status" aria-label="Loading station">
      {[1, 2].map((i) => <span key={i} className="hub-skeleton block !h-16 w-full" />)}
    </div>
  );
}

interface CommandCenterProps {
  overview: CommandOverview | null;
  loading: boolean;
  comms: CommandCommunications;
  /** The raw `?station=` value; anything that is not a known station falls back to Communications. */
  requested: string | null;
  /** The page is on the Department Updates tab (and no station is requested). */
  updatesOpen: boolean;
  onSelect: (id: string) => void;
}

const isStationId = (value: string | null): value is string => value !== null && /^\d+$/.test(value);

/** Communications first, then one tab per station. The card mounts whether or not the overview loads. */
function CommandCenter({ overview, loading, comms, requested, updatesOpen, onSelect }: CommandCenterProps) {
  const stations = overview?.stations ?? [];
  const known = stations.some((station) => String(station.id) === requested);
  // A deep link to a station waits for the overview instead of flashing Communications first.
  const pending = !known && loading && !overview && isStationId(requested);
  const stationId = (known || pending) && requested ? requested : COMMS_TAB;
  const activeId = stationId === COMMS_TAB && updatesOpen ? UPDATES_TAB : stationId;

  const tabs: TabItem<string>[] = [
    { id: COMMS_TAB, label: 'Communications', badge: comms.feed.unreadCount, badgeLabel: 'unread messages' },
    { id: UPDATES_TAB, label: 'Department Updates' },
    ...stations.map((station) => ({ id: String(station.id), label: station.name })),
    ...(pending && requested ? [{ id: requested, label: 'Station' }] : []),
  ];

  return (
    <div className="hub-panel overflow-hidden" data-testid="command-stations">
      <Tabs
        tabs={tabs}
        active={activeId}
        onChange={onSelect}
        label="Communications and stations"
        idPrefix="command-stations"
        panelClassName="p-4 sm:p-5"
        renderPanel={(id) => {
          if (id === COMMS_TAB) return <CommunicationsPanel comms={comms} onOpenUpdates={() => onSelect(UPDATES_TAB)} />;
          if (id === UPDATES_TAB) return <DepartmentUpdatesPanel origin="command_300" onOpenMessages={() => onSelect(COMMS_TAB)} />;
          const station = stations.find((candidate) => String(candidate.id) === id);
          return station ? <StationSection station={station} /> : <StationPanelSkeleton />;
        }}
      />
    </div>
  );
}

export default function Command300Page() {
  const access = useCommandAccess();
  const [forbidden, setForbidden] = useState(false);
  const [overview, setOverview] = useState<CommandOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [searchParams, setSearchParams] = useSearchParams();
  const isOffline = useOffline();

  const requested = searchParams.get('station');
  const onStationTab = overview
    ? overview.stations.some((station) => String(station.id) === requested)
    : loading && isStationId(requested);
  const updatesOpen = !onStationTab && searchParams.get('tab') === UPDATES_PARAM;
  const onDenied = useCallback(() => setForbidden(true), []);
  const comms = useCommandCommunications({
    active: !onStationTab && !updatesOpen,
    enabled: (access === 'allowed' || access === 'unknown') && !forbidden,
    onDenied,
  });
  const { refresh: refreshFeed } = comms.feed;

  // Stations load once and on the manual refresh button; only messages poll.
  const load = useCallback(async (manual = false) => {
    if (manual) setRefreshing(true);
    try {
      setOverview(await ApiClient.getCommandOverview());
      setError(null);
    } catch (err) {
      if (isApiAuthenticationError(err)) {
        redirectToLoginAfterSessionExpiry();
        return;
      }
      if (isForbiddenError(err)) {
        setForbidden(true);
        return;
      }
      setError(err instanceof Error ? err.message : 'Failed to load the station overview');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const onSelect = useCallback((id: string) => {
    const next = new URLSearchParams(searchParams);
    next.delete('tab');
    if (id === COMMS_TAB) next.delete('station');
    else if (id === UPDATES_TAB) { next.delete('station'); next.set('tab', UPDATES_PARAM); }
    else next.set('station', id);
    setSearchParams(next, { replace: true, preventScrollReset: true });
  }, [searchParams, setSearchParams]);

  const onRefresh = () => {
    void load(true);
    if (!onStationTab && !updatesOpen) refreshFeed().catch(() => undefined);
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

  const refreshLabel = isOffline ? 'Refresh unavailable while offline' : refreshing ? 'Refreshing overview' : 'Refresh overview';
  const description = overview
    ? `Stations are read-only. Loaded ${formatDateTime(overview.generated_at)}.`
    : loading ? 'Loading stations…' : 'Messages and Department Updates for 300, with a read-only summary of each active station.';

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
            <p className={`mt-1 max-w-prose ${SECONDARY}`}>Shift Division Chief. Join the command Morning Lineup, open the Command Board, message Administration and Captain 5, post to Department Updates, or review station readiness below.</p>
            <nav aria-label="300 actions" className="mt-4 grid gap-2.5 sm:grid-cols-2">
              <a href={COMMAND_LINEUP_HREF} className={ACTION_PRIMARY}>
                <ClipboardCheck size={20} aria-hidden="true" />
                <span className="min-w-0">Morning Lineup — Command</span>
              </a>
              <a href={COMMAND_BOARD_HREF} target="_blank" rel="noopener noreferrer" className={ACTION_ORDINARY}>
                <ExternalLink size={20} aria-hidden="true" />
                <span className="min-w-0">Open Command Board<span className="sr-only"> (opens in a new tab)</span></span>
              </a>
            </nav>
          </div>
        </div>
      </section>

      <section aria-labelledby="command-overview-heading" className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 id="command-overview-heading" className="font-heading text-xl font-bold text-hub-ink">Communications and stations</h2>
            <p className={SECONDARY}>{description}</p>
          </div>
          <button
            type="button"
            data-testid="command-overview-refresh"
            className="hub-btn hub-btn--secondary !min-h-11 !min-w-11 gap-2"
            onClick={onRefresh}
            disabled={refreshing || isOffline || loading}
            aria-busy={refreshing}
            aria-label={refreshLabel}
            title={refreshLabel}
          >
            <RefreshCw className={`h-4 w-4 ${refreshing ? 'motion-safe:animate-spin' : ''}`} aria-hidden="true" />
            <span className="hidden sm:inline">{refreshing ? 'Refreshing' : 'Refresh'}</span>
          </button>
        </div>

        {!loading && error && !overview && (
          <LoadError title="Station overview unavailable" message={`${error} Messages are still available below.`} onRetry={() => void load(true)} />
        )}
        {!loading && error && overview && (
          <div className="hub-callout hub-callout--warning p-3" role="alert">The overview could not be refreshed: {error}. The data below may be out of date.</div>
        )}
        {!loading && overview && overview.stations.length === 0 && (
          <div className="hub-callout p-3 text-sm" role="status">
            <p className="font-semibold text-hub-ink">No active stations</p>
            <p className="text-hub-n-600">Active stations appear here once they are set up in Administration.</p>
          </div>
        )}

        <CommandCenter overview={overview} loading={loading} comms={comms} requested={requested} updatesOpen={updatesOpen} onSelect={onSelect} />
      </section>
    </div>
  );
}
