import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router';
import { Boxes, ClipboardList, History } from 'lucide-react';
import type { RoomProfile, StationRequestSummary } from '../types';
import { ApiClient } from '../utils/api';
import PreviousPageButton from './PreviousPageButton';
import DailyPageNav from './DailyPageNav';
import Tabs, { type TabItem } from './ui/Tabs';
import Tag, { StatusTag } from './ui/Tag';
import { EmptyState, LoadError, SkeletonRows } from './ui/StateViews';
import { humanize } from './ui/status';
import { HUB_TIMEZONE } from '../../../shared/timezone.ts';

type ProfileTab = 'assets' | 'open' | 'history' | 'events';

const ATTENTION_CONDITIONS = ['poor', 'critical', 'damaged', 'needs_repair', 'out_of_service'];
const SECONDARY = 'text-[13px] leading-5 text-hub-n-600';

const formatDate = (value: string) => new Date(value).toLocaleString('en-US', {
  timeZone: HUB_TIMEZONE, month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
});

export default function RoomAssetTracker() {
  const { stationId, roomId } = useParams<{ stationId: string; roomId: string }>();
  const [profile, setProfile] = useState<RoomProfile | null>(null);
  const [activeTab, setActiveTab] = useState<ProfileTab>('assets');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const loadSequence = useRef(0);
  const lastLoadedProfileKey = useRef('');

  const loadProfile = useCallback(() => {
    if (!stationId || !roomId) return;
    const sequence = ++loadSequence.current;
    setLoading(true);
    setError('');
    ApiClient.getRoomProfile(Number(stationId), Number(roomId))
      .then((data) => {
        if (sequence !== loadSequence.current) return;
        setProfile(data);
        setError('');
      })
      .catch((reason) => {
        if (sequence === loadSequence.current) {
          setError(reason instanceof Error ? reason.message : 'The room profile could not be loaded.');
        }
      })
      .finally(() => {
        if (sequence === loadSequence.current) setLoading(false);
      });
  }, [stationId, roomId]);

  useEffect(() => {
    const profileKey = `${stationId}:${roomId}`;
    if (lastLoadedProfileKey.current === profileKey) return;
    lastLoadedProfileKey.current = profileKey;
    loadProfile();
  }, [loadProfile]);

  if (loading) return <div data-hub-ui="2" className="space-y-4 font-hub" aria-label="Loading room profile"><span className="hub-skeleton !h-11 w-24" /><div className="hub-panel p-5"><SkeletonRows rows={2} className="!h-10" /></div><div className="hub-panel p-5"><SkeletonRows /></div></div>;
  if (error || !profile) return <div data-hub-ui="2" className="mx-auto max-w-lg font-hub"><LoadError title="Room profile unavailable" message={error || 'Room not found.'} onRetry={loadProfile}><PreviousPageButton contextual fallback={`/stations/${stationId}`} className="hub-btn hub-btn--secondary" /></LoadError></div>;

  const room = profile.room;
  const attention = profile.current_assets.filter((asset) => ATTENTION_CONDITIONS.includes(asset.condition)).length;
  const returnTo = encodeURIComponent(`/stations/${stationId}/rooms/${roomId}`);
  const newRequestPath = `/forms-hub/station-request?station_id=${stationId}&return_to=${returnTo}`;

  const tabs: TabItem<ProfileTab>[] = [
    { id: 'assets', label: 'Current assets', badge: profile.current_assets.length },
    { id: 'open', label: 'Open requests', badge: profile.open_requests.length },
    { id: 'history', label: 'Request history', badge: profile.request_history.length },
    { id: 'events', label: 'Asset events', badge: profile.asset_events.length },
  ];

  const renderPanel = (tab: ProfileTab) => {
    if (tab === 'assets') return profile.current_assets.length > 0 ? <ul className="grid gap-3 sm:grid-cols-2">{profile.current_assets.map((asset) => <li key={asset.id} className="hub-well p-4"><div className="flex items-start justify-between gap-3"><div className="min-w-0"><h2 className="font-semibold text-hub-ink">{asset.name}</h2><p className={`mt-1 ${SECONDARY}`}>{humanize(asset.condition, 'Condition unknown')}</p></div><Tag className="tabular-nums">Qty {asset.quantity}</Tag></div>{asset.category && <p className={`mt-3 ${SECONDARY}`}>{humanize(asset.category)}</p>}</li>)}</ul> : <EmptyState icon={<Boxes className="hub-empty__icon" aria-hidden="true" />} title="No active assets" body="No active assets are recorded for this room." />;
    if (tab === 'open') return <RequestList requests={profile.open_requests} empty="No open requests for this room." newRequestPath={newRequestPath} />;
    if (tab === 'history') return <RequestList requests={profile.request_history} empty="No request history for this room." newRequestPath={newRequestPath} />;
    return profile.asset_events.length > 0 ? <ol className="space-y-3">{profile.asset_events.map((event) => <li key={event.id} className="hub-well p-4"><div className="flex flex-wrap items-start justify-between gap-2"><p className="font-semibold text-hub-ink">{event.asset_name}</p><Tag tone="info">{humanize(event.event_type)}</Tag></div><p className={`mt-2 tabular-nums ${SECONDARY}`}>{formatDate(event.event_at)}{event.request_number ? ` · ${event.request_number}` : ''}</p></li>)}</ol> : <EmptyState icon={<History className="hub-empty__icon" aria-hidden="true" />} title="No asset events" body="No lifecycle events are recorded for current room assets." />;
  };

  return (
    <div data-hub-ui="2" className="space-y-5 font-hub">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <DailyPageNav fallback={`/stations/${stationId}`} className="!mb-0" />
        <Link to={newRequestPath} className="hub-btn hub-btn--primary">New room request</Link>
      </div>

      <header className="hub-panel p-4 sm:p-6">
        <p className="text-[13px] font-semibold text-hub-n-600">Room profile</p>
        <h1 className="mt-1 font-heading text-2xl font-bold text-hub-ink sm:text-3xl">{room.name}</h1>
        <p className={`mt-1 ${SECONDARY}`}>{room.floor ? `${room.floor} floor · ` : ''}{humanize(room.type, 'Station room')}</p>
        <dl className="mt-5 grid grid-cols-3 gap-3">
          <Stat label="Active assets" value={profile.current_assets.length} />
          <Stat label="Need attention" value={attention} />
          <Stat label="Open requests" value={profile.open_requests.length} />
        </dl>
      </header>

      <section className="hub-panel overflow-hidden" aria-label="Room details">
        <Tabs tabs={tabs} active={activeTab} onChange={setActiveTab} label="Room profile sections" idPrefix="room" renderPanel={renderPanel} panelClassName="p-4 sm:p-6" />
      </section>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return <div className="hub-well p-3"><dt className={SECONDARY}>{label}</dt><dd className="mt-1 text-2xl font-bold tabular-nums text-hub-ink">{value}</dd></div>;
}

function RequestList({ requests, empty, newRequestPath }: { requests: StationRequestSummary[]; empty: string; newRequestPath: string }) {
  if (requests.length === 0) return <EmptyState icon={<ClipboardList className="hub-empty__icon" aria-hidden="true" />} title={empty} action={<Link to={newRequestPath} className="hub-btn hub-btn--secondary">New room request</Link>} />;
  return <ul className="space-y-3">{requests.map((request) => <li key={request.id} className="hub-well p-4"><div className="flex flex-wrap items-start justify-between gap-2"><div className="min-w-0"><p className="font-mono text-[13px] font-semibold text-hub-n-600">{request.request_number}</p><h2 className="mt-1 font-semibold text-hub-ink">{request.title}</h2></div><StatusTag status={request.status} /></div>{request.current_public_response && <p className="mt-3 rounded-hub-control border border-hub-info-border bg-hub-info-bg p-3 text-sm text-hub-n-800"><span className="font-semibold">Latest response:</span> {request.current_public_response}</p>}<p className={`mt-3 ${SECONDARY}`}>{request.request_type === 'repair_service' ? 'Repair / service' : 'Equipment'} · {formatDate(request.created_at)}</p></li>)}</ul>;
}
