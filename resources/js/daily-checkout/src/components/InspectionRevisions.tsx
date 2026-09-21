import { useEffect, useState } from 'react';
import type { InspectionRevision } from '../types';
import { ApiClient } from '../utils/api';
import { identityForQueueCapture, type OfflineIdentity } from '../lib/offlineIdentity';

type Draft = { reason: string; value: string };

export default function InspectionRevisions() {
  const [requests, setRequests] = useState<InspectionRevision[]>([]);
  const [identity, setIdentity] = useState<OfflineIdentity | null>(null);
  const [drafts, setDrafts] = useState<Record<number, Draft>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const keyFor = (owner: OfflineIdentity) => `mbfd_inspection_revisions_${owner.userId}_${owner.securityVersion}`;

  const load = async () => {
    try {
      const owner = await identityForQueueCapture();
      if (!owner) return;
      setIdentity(owner);
      const saved = localStorage.getItem(keyFor(owner));
      if (saved) setDrafts(JSON.parse(saved));
      setRequests(await ApiClient.getInspectionRevisions());
      setError(null);
    } catch {
      setError('Requested clarifications are unavailable. Reconnect and retry; saved drafts stay on this device.');
    }
  };
  useEffect(() => { void load(); }, []);

  const update = (id: number, patch: Partial<Draft>) => {
    const next = { ...drafts, [id]: { ...(drafts[id] ?? { reason: '', value: '' }), ...patch } };
    setDrafts(next);
    if (identity) {
      try { localStorage.setItem(keyFor(identity), JSON.stringify(next)); }
      catch { setError('This draft could not be saved. Keep this page open until it is sent.'); }
    }
  };

  const send = async (request: InspectionRevision) => {
    setBusy(request.id);
    try {
      const owner = await identityForQueueCapture();
      if (!owner || owner.userId !== identity?.userId || owner.securityVersion !== identity.securityVersion) throw new Error('Your sign-in changed. Sign in as the original member to send this saved clarification.');
      const draft = drafts[request.id];
      if (!draft?.reason.trim()) throw new Error('Add an explanation before sending.');
      const meter = request.field === 'miles' || request.field === 'engine_hours';
      if (meter && (draft.value === '' || !Number.isFinite(Number(draft.value)))) throw new Error('Enter the corrected reading.');
      await ApiClient.submitInspectionRevision(request.id, { reason: draft.reason, ...(meter ? { value: Number(draft.value) } : {}) });
      setRequests(previous => previous.map(entry => entry.id === request.id ? { ...entry, status: 'revision_submitted' } : entry));
      const next = { ...drafts };
      delete next[request.id];
      setDrafts(next);
      localStorage.setItem(keyFor(owner), JSON.stringify(next));
      setError(null);
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Your clarification was not sent. Your draft remains here; try again.');
    } finally { setBusy(null); }
  };

  if (requests.length === 0 && !error) return null;
  return <section className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4" aria-label="Inspection clarifications">
    <h2 className="font-semibold text-slate-900">Your inspection follow-ups</h2>
    {error && <div role="alert" className="mt-2 text-sm text-red-800">{error} <button className="min-h-11 px-3 underline" onClick={() => void load()}>Retry</button></div>}
    <div className="mt-3 space-y-3">{requests.map(request => <details key={request.id} className="rounded border border-amber-200 bg-white p-3">
      <summary className="min-h-11 cursor-pointer font-medium">{request.apparatus.name} · Vehicle {request.apparatus.vehicle_number} · {request.status === 'revision_submitted' ? 'Clarification sent' : 'Clarification requested'}</summary>
      <p className="my-2 text-sm">{request.reviewer_note}</p>
      {request.status === 'revision_submitted' ? <p role="status" className="text-sm text-green-800">Your clarification is recorded and awaiting review.</p> : <form onSubmit={event => { event.preventDefault(); void send(request); }} className="space-y-3">
        {(request.field === 'engine_hours' || request.field === 'miles') && <>
          <p className="text-sm text-slate-600">{request.field === 'miles' ? 'Mileage' : 'Engine hours'} · Originally reported: {request.submitted_value} · Current fleet value: {request.current_value ?? 'Not recorded'}</p>
          <label className="block text-sm font-medium">Corrected reading<input type="number" required min="0" step={request.field === 'miles' ? '1' : '0.1'} value={drafts[request.id]?.value ?? ''} onChange={event => update(request.id, { value: event.target.value })} className="mt-1 block min-h-12 w-full rounded border border-slate-300 p-3" /></label>
        </>}
        <label className="block text-sm font-medium">Explanation<textarea required maxLength={2000} value={drafts[request.id]?.reason ?? ''} onChange={event => update(request.id, { reason: event.target.value })} className="mt-1 block w-full rounded border border-slate-300 p-3" /></label>
        <button disabled={busy !== null} className="min-h-12 rounded bg-slate-800 px-4 py-2 font-semibold text-white disabled:opacity-50">{busy === request.id ? 'Sending…' : 'Send clarification'}</button>
      </form>}
    </details>)}</div>
  </section>;
}
