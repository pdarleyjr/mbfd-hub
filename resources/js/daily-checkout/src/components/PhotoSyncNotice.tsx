import { useOffline } from '../hooks/useOffline';
import { usePhotoUploadSummary } from '../hooks/usePhotoUploads';
import { photoSummaryLine } from '../utils/photoUploadQueue';

/** "3 of 4 photos sent. The other is sending now…" for one checkout. Renders nothing once every photo has arrived. */
export default function PhotoSyncNotice({ ids, className = '' }: { ids: string[]; className?: string }) {
  const summary = usePhotoUploadSummary(ids);
  const offline = useOffline();
  const line = photoSummaryLine(summary, !offline);
  if (!line) return null;

  return <p className={`text-hub-ink-secondary ${className}`} role="status" aria-live="polite" data-photo-sync>{line}</p>;
}
