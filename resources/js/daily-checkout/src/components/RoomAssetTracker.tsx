import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router';
import type { RoomProfile, StationRequestSummary } from '../types';
import { ApiClient } from '../utils/api';
import PreviousPageButton from './PreviousPageButton';

type ProfileTab = 'assets' | 'open' | 'history' | 'events';

const formatDate = (value: string) => new Date(value).toLocaleString('en-US', {
  month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
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

  if (loading) return <div className="flex min-h-64 items-center justify-center text-sm font-semibold text-hub-ink-secondary font-hub" role="status">Loading room profile…</div>;
  if (error || !profile) return <div className="rounded-xl border border-hub-danger/30 bg-hub-danger/10 p-5 text-hub-danger font-hub"><p className="font-semibold">{error || 'Room not found.'}</p><div className="mt-4 flex flex-wrap gap-3"><button type="button" onClick={loadProfile} className="min-h-12 rounded-lg bg-hub-blue px-5 font-semibold text-white hover:bg-hub-blue-strong focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus">Retry</button><PreviousPageButton fallback={`/stations/${stationId}`} className="inline-flex min-h-12 items-center px-2 font-semibold text-hub-blue hover:text-hub-blue-strong">← Back to previous page</PreviousPageButton></div></div>;

  const room = profile.room;
  const attention = profile.current_assets.filter((asset) => ['poor', 'critical', 'damaged', 'needs_repair', 'out_of_service'].includes(asset.condition)).length;
  const returnTo = encodeURIComponent(`/stations/${stationId}/rooms/${roomId}`);

  const tabs: { id: ProfileTab; label: string; count: number }[] = [
    { id: 'assets', label: 'Current assets', count: profile.current_assets.length },
    { id: 'open', label: 'Open requests', count: profile.open_requests.length },
    { id: 'history', label: 'Request history', count: profile.request_history.length },
    { id: 'events', label: 'Asset events', count: profile.asset_events.length },
  ];

  return (
    <div className="space-y-5 font-hub">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <PreviousPageButton fallback={`/stations/${stationId}`} className="inline-flex min-h-12 items-center gap-2 px-2 text-sm font-semibold text-hub-ink-secondary hover:text-hub-ink">← Back to previous page</PreviousPageButton>
        <Link to={`/forms-hub/station-request?station_id=${stationId}&return_to=${returnTo}`} className="inline-flex min-h-12 items-center justify-center rounded-lg bg-hub-blue px-5 font-semibold text-white hover:bg-hub-blue-strong focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus">New room request</Link>
      </div>

      <header className="rounded-xl border-t-4 border-hub-red bg-hub-header p-5 text-white sm:p-7">
        <p className="text-sm font-semibold text-hub-surface/80">Room profile</p>
        <h1 className="mt-1 font-heading text-3xl font-bold">{room.name}</h1>
        <p className="mt-2 text-sm text-hub-muted-soft">{room.floor ? `${room.floor} floor · ` : ''}{room.type?.replaceAll('_', ' ') || 'Station room'}</p>
        <dl className="mt-6 grid grid-cols-3 gap-3">
          <Stat label="Active assets" value={profile.current_assets.length} />
          <Stat label="Need attention" value={attention} />
          <Stat label="Open requests" value={profile.open_requests.length} />
        </dl>
      </header>

      <section className="overflow-hidden rounded-xl bg-hub-surface shadow-sm ring-1 ring-hub-border/80">
        <div className="flex overflow-x-auto border-b border-hub-border p-2" role="tablist" aria-label="Room profile sections">
          {tabs.map((tab) => <button key={tab.id} type="button" role="tab" aria-selected={activeTab === tab.id} onClick={() => setActiveTab(tab.id)} className={`min-h-12 flex-none rounded-lg px-4 text-sm font-semibold transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus ${activeTab === tab.id ? 'bg-hub-blue/10 text-hub-blue' : 'text-hub-ink-secondary hover:bg-hub-canvas hover:text-hub-ink'}`}>{tab.label} <span className="ml-1 tabular-nums">{tab.count}</span></button>)}
        </div>
        <div className="p-4 sm:p-6">
          {activeTab === 'assets' && (profile.current_assets.length > 0 ? <div className="grid gap-3 sm:grid-cols-2">{profile.current_assets.map((asset) => <article key={asset.id} className="rounded-lg border border-hub-border p-4"><div className="flex items-start justify-between gap-3"><div><h2 className="font-semibold text-hub-ink">{asset.name}</h2><p className="mt-1 text-sm capitalize text-hub-muted">{asset.condition?.replaceAll('_', ' ') || 'Condition unknown'}</p></div><span className="rounded-full bg-hub-surface-muted px-2.5 py-1 text-xs font-semibold text-hub-ink-secondary">Qty {asset.quantity}</span></div>{asset.category && <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-hub-blue">{asset.category}</p>}</article>)}</div> : <Empty text="No active assets are recorded for this room." />)}
          {activeTab === 'open' && <RequestList requests={profile.open_requests} empty="No open requests for this room." />}
          {activeTab === 'history' && <RequestList requests={profile.request_history} empty="No request history for this room." />}
          {activeTab === 'events' && (profile.asset_events.length > 0 ? <ol className="space-y-3">{profile.asset_events.map((event) => <li key={event.id} className="rounded-lg border border-hub-border p-4"><div className="flex flex-wrap items-start justify-between gap-2"><p className="font-semibold text-hub-ink">{event.asset_name}</p><span className="rounded-full bg-hub-blue/10 px-2.5 py-1 text-xs font-semibold capitalize text-hub-blue">{event.event_type.replaceAll('_', ' ')}</span></div><p className="mt-2 text-sm text-hub-muted">{formatDate(event.event_at)}{event.request_number ? ` · ${event.request_number}` : ''}</p></li>)}</ol> : <Empty text="No lifecycle events are recorded for current room assets." />)}
        </div>
      </section>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return <div className="rounded-xl bg-hub-surface/10 p-3"><dt className="text-xs text-hub-muted-soft">{label}</dt><dd className="mt-1 font-mono text-2xl font-bold tabular-nums">{value}</dd></div>;
}

function RequestList({ requests, empty }: { requests: StationRequestSummary[]; empty: string }) {
  if (requests.length === 0) return <Empty text={empty} />;
  return <div className="space-y-3">{requests.map((request) => <article key={request.id} className="rounded-lg border border-hub-border p-4"><div className="flex flex-wrap items-start justify-between gap-2"><div><p className="font-mono text-xs font-semibold text-hub-muted">{request.request_number}</p><h2 className="mt-1 font-semibold text-hub-ink">{request.title}</h2></div><span className="rounded-full bg-hub-blue/10 px-2.5 py-1 text-xs font-semibold capitalize text-hub-blue">{request.status.replaceAll('_', ' ')}</span></div>{request.current_public_response && <p className="mt-3 rounded-lg bg-hub-blue/10 p-3 text-sm text-hub-blue"><span className="font-semibold">Latest response:</span> {request.current_public_response}</p>}<p className="mt-3 text-xs text-hub-muted">{request.request_type === 'repair_service' ? 'Repair / service' : 'Equipment'} · {formatDate(request.created_at)}</p></article>)}</div>;
}

function Empty({ text }: { text: string }) {
  return <div className="py-12 text-center text-sm text-hub-muted">{text}</div>;
}
