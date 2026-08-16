import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router';
import type { RoomProfile, StationRequestSummary } from '../types';
import { ApiClient } from '../utils/api';

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

  useEffect(() => {
    if (!stationId || !roomId) return;
    setLoading(true);
    ApiClient.getRoomProfile(Number(stationId), Number(roomId))
      .then((data) => { setProfile(data); setError(''); })
      .catch((reason) => setError(reason instanceof Error ? reason.message : 'The room profile could not be loaded.'))
      .finally(() => setLoading(false));
  }, [stationId, roomId]);

  if (loading) return <div className="flex min-h-64 items-center justify-center text-sm font-semibold text-stone-600" role="status">Loading room profile…</div>;
  if (error || !profile) return <div className="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800"><p className="font-semibold">{error || 'Room not found.'}</p><Link to={`/stations/${stationId}`} className="mt-4 inline-flex min-h-12 items-center font-semibold text-blue-800">← Back to station</Link></div>;

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
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Link to={`/stations/${stationId}`} className="inline-flex min-h-12 items-center gap-2 px-2 text-sm font-semibold text-stone-600 hover:text-slate-900">← Back to station</Link>
        <Link to={`/forms-hub/station-request?station_id=${stationId}&return_to=${returnTo}`} className="inline-flex min-h-12 items-center justify-center rounded-xl bg-blue-700 px-5 font-semibold text-white hover:bg-blue-800">New room request</Link>
      </div>

      <header className="rounded-2xl bg-slate-900 p-5 text-white sm:p-7">
        <p className="text-sm font-semibold text-blue-200">Room profile</p>
        <h1 className="mt-1 font-heading text-3xl font-bold">{room.name}</h1>
        <p className="mt-2 text-sm text-slate-300">{room.floor ? `${room.floor} floor · ` : ''}{room.type?.replaceAll('_', ' ') || 'Station room'}</p>
        <dl className="mt-6 grid grid-cols-3 gap-3">
          <Stat label="Active assets" value={profile.current_assets.length} />
          <Stat label="Need attention" value={attention} />
          <Stat label="Open requests" value={profile.open_requests.length} />
        </dl>
      </header>

      <section className="overflow-hidden rounded-2xl bg-white ring-1 ring-stone-200">
        <div className="flex overflow-x-auto border-b border-stone-200 p-2" role="tablist" aria-label="Room profile sections">
          {tabs.map((tab) => <button key={tab.id} type="button" role="tab" aria-selected={activeTab === tab.id} onClick={() => setActiveTab(tab.id)} className={`min-h-12 flex-none rounded-xl px-4 text-sm font-semibold ${activeTab === tab.id ? 'bg-blue-50 text-blue-800' : 'text-stone-600 hover:bg-stone-50'}`}>{tab.label} <span className="ml-1 tabular-nums">{tab.count}</span></button>)}
        </div>
        <div className="p-4 sm:p-6">
          {activeTab === 'assets' && (profile.current_assets.length > 0 ? <div className="grid gap-3 sm:grid-cols-2">{profile.current_assets.map((asset) => <article key={asset.id} className="rounded-xl border border-stone-200 p-4"><div className="flex items-start justify-between gap-3"><div><h2 className="font-semibold text-slate-900">{asset.name}</h2><p className="mt-1 text-sm capitalize text-stone-500">{asset.condition?.replaceAll('_', ' ') || 'Condition unknown'}</p></div><span className="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-700">Qty {asset.quantity}</span></div>{asset.category && <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-blue-800">{asset.category}</p>}</article>)}</div> : <Empty text="No active assets are recorded for this room." />)}
          {activeTab === 'open' && <RequestList requests={profile.open_requests} empty="No open requests for this room." />}
          {activeTab === 'history' && <RequestList requests={profile.request_history} empty="No request history for this room." />}
          {activeTab === 'events' && (profile.asset_events.length > 0 ? <ol className="space-y-3">{profile.asset_events.map((event) => <li key={event.id} className="rounded-xl border border-stone-200 p-4"><div className="flex flex-wrap items-start justify-between gap-2"><p className="font-semibold text-slate-900">{event.asset_name}</p><span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold capitalize text-blue-800">{event.event_type.replaceAll('_', ' ')}</span></div><p className="mt-2 text-sm text-stone-500">{formatDate(event.event_at)}{event.request_number ? ` · ${event.request_number}` : ''}</p></li>)}</ol> : <Empty text="No lifecycle events are recorded for current room assets." />)}
        </div>
      </section>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return <div className="rounded-xl bg-white/10 p-3"><dt className="text-xs text-slate-300">{label}</dt><dd className="mt-1 font-mono text-2xl font-bold tabular-nums">{value}</dd></div>;
}

function RequestList({ requests, empty }: { requests: StationRequestSummary[]; empty: string }) {
  if (requests.length === 0) return <Empty text={empty} />;
  return <div className="space-y-3">{requests.map((request) => <article key={request.id} className="rounded-xl border border-stone-200 p-4"><div className="flex flex-wrap items-start justify-between gap-2"><div><p className="font-mono text-xs font-semibold text-stone-500">{request.request_number}</p><h2 className="mt-1 font-semibold text-slate-900">{request.title}</h2></div><span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold capitalize text-blue-800">{request.status.replaceAll('_', ' ')}</span></div>{request.current_public_response && <p className="mt-3 rounded-lg bg-blue-50 p-3 text-sm text-blue-900"><span className="font-semibold">Latest response:</span> {request.current_public_response}</p>}<p className="mt-3 text-xs text-stone-500">{request.request_type === 'repair_service' ? 'Repair / service' : 'Equipment'} · {formatDate(request.created_at)}</p></article>)}</div>;
}

function Empty({ text }: { text: string }) {
  return <div className="py-12 text-center text-sm text-stone-500">{text}</div>;
}
