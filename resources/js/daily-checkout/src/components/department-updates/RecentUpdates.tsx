import { useState } from 'react';
import { Megaphone } from 'lucide-react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { takeDownDepartmentUpdate } from '../../utils/departmentUpdatesApi';
import { EmptyState, LoadError, SkeletonRows } from '../ui/StateViews';
import Tag from '../ui/Tag';
import MessageBody from '../command-communications/MessageBody';
import type { UpdatesStatus } from './useDepartmentUpdates';
import type { PostedUpdate, UpdateOrigin } from './types';
import { HUB_TIMEZONE } from '../../../../shared/timezone.ts';

const HINT = 'text-[13px] leading-5 text-hub-n-600';
const BTN = 'hub-btn !min-h-11';

const day = (iso: string | null): string => {
  if (!iso) return '';
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('en-US', { timeZone: HUB_TIMEZONE, month: 'short', day: 'numeric' });
};

const when = (iso: string | null): string => {
  if (!iso) return '';
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('en-US', { timeZone: HUB_TIMEZONE, month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};

function UpdateRow({ origin, update, onChanged }: { origin: UpdateOrigin; update: PostedUpdate; onChanged: (update: PostedUpdate) => void }) {
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const live = update.status === 'published';

  const takeDown = async () => {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      onChanged(await takeDownDepartmentUpdate(origin, update.id));
      setConfirming(false);
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setError(failure instanceof Error ? failure.message : 'The update could not be taken down. Try again.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <li className={`rounded-hub-control border border-hub-border border-l-4 bg-hub-well p-3 sm:p-4 ${live ? 'border-l-hub-blue' : 'border-l-hub-n-400'}`} data-testid="department-update-row">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <h4 className="min-w-0 flex-1 break-words font-heading text-base font-bold text-hub-ink">{update.title}</h4>
        <Tag tone={live ? 'success' : 'neutral'}>{live ? 'Published' : 'Taken down'}</Tag>
      </div>
      <p className={HINT}>Posted by {update.author}{update.posted_at ? ` · ${when(update.posted_at)}` : ''}</p>
      <div className="mt-1 flex flex-wrap gap-1.5">
        {live && update.expires_at && <Tag tone="warning">Comes down {day(update.expires_at)}</Tag>}
        {live && !update.expires_at && <Tag tone="neutral">No end date</Tag>}
        {update.has_image && <Tag tone="info">Picture</Tag>}
        {update.has_attachment && <Tag tone="info">File attached</Tag>}
      </div>
      {update.excerpt && <MessageBody text={update.excerpt} className="mt-2" testId="department-update-excerpt" />}
      {live && update.url && (
        <a href={update.url} className="mt-1 inline-flex min-h-11 items-center text-sm font-semibold text-hub-blue underline underline-offset-4">
          View on the home page<span className="sr-only">: {update.title}</span>
        </a>
      )}
      {live && update.own && (confirming ? (
        <div className="mt-2 space-y-2" role="group" aria-label={`Take down ${update.title}`}>
          <p className="text-sm text-hub-ink">Take this off the home page? It stays in the archive as history.</p>
          {error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{error}</p>}
          <div className="flex flex-wrap gap-2">
            <button type="button" className={`${BTN} hub-btn--destructive`} disabled={busy} aria-busy={busy} onClick={() => void takeDown()}>{busy ? 'Taking down…' : 'Yes, take it down'}</button>
            <button type="button" className={`${BTN} hub-btn--secondary`} disabled={busy} onClick={() => { setConfirming(false); setError(null); }}>Keep it up</button>
          </div>
        </div>
      ) : (
        <button type="button" className={`${BTN} hub-btn--secondary mt-2`} onClick={() => setConfirming(true)}>
          Take down<span className="sr-only"> {update.title}</span>
        </button>
      ))}
    </li>
  );
}

interface Props {
  origin: UpdateOrigin;
  updates: PostedUpdate[];
  status: UpdatesStatus;
  error: string | null;
  onRetry: () => void;
  onChanged: (update: PostedUpdate) => void;
}

/** What was posted from this page, with a Take down for the member's own live posts. */
export default function RecentUpdates({ origin, updates, status, error, onRetry, onChanged }: Props) {
  return (
    <section className="space-y-3" aria-labelledby={`${origin}-recent-updates`} data-testid="department-updates-recent">
      <h3 id={`${origin}-recent-updates`} className="font-heading text-base font-bold text-hub-ink">Recent posts from this page</h3>
      {status === 'loading' && <SkeletonRows rows={2} className="!h-20" />}
      {status === 'error' && <LoadError title="Recent posts could not be loaded" message={error} onRetry={onRetry} />}
      {status === 'ready' && error && <p role="status" className="hub-callout hub-callout--warning p-3 text-sm">Recent posts could not be refreshed: {error}</p>}
      {status === 'ready' && updates.length === 0 && (
        <EmptyState icon={<Megaphone className="hub-empty__icon" aria-hidden="true" />} title="Nothing posted from this page yet" body="Updates you post here are listed with a way to take them down." />
      )}
      {updates.length > 0 && (
        <ul className="space-y-2" aria-label="Recent Department Updates">
          {updates.map((update) => <UpdateRow key={update.id} origin={origin} update={update} onChanged={onChanged} />)}
        </ul>
      )}
    </section>
  );
}
