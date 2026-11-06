import { useOffline } from '../hooks/useOffline';
import { useQueuedPhoto, type QueuedPhotoView } from '../hooks/usePhotoUploads';

interface Props {
  /** The photo id the checkout carries (photos queued on this device). */
  photoId?: string;
  /** A photo held inline by a draft saved before photos were queued separately. */
  legacySrc?: string;
  alt: string;
  removeLabel: string;
  onRemove: () => void;
}

/** What the member is told about one photo. Pure. */
export function queuedPhotoStatus(view: QueuedPhotoView, offline: boolean): string {
  if (view.status === 'sent') return 'Photo sent.';
  if (view.status === 'rejected') return view.lastError ?? 'The Hub could not use this photo. Take it again.';
  if (view.status === 'pending') {
    if (view.progress !== undefined) return `Sending photo… ${view.progress}%`;
    if (offline) return 'Saved on this device. It sends when you are back online; you can keep going.';

    return view.lastError
      ? `${view.lastError} It stays on this device and will be sent again; you can keep going.`
      : 'Saved on this device. Sending…';
  }

  return 'Photo saved.';
}

/** Preview of an attached photo with its sending state. Never blocks the checkout. */
export default function QueuedPhotoPreview({ photoId, legacySrc, alt, removeLabel, onRemove }: Props) {
  const view = useQueuedPhoto(legacySrc ? undefined : photoId);
  const offline = useOffline();
  const src = legacySrc ?? view.url;

  return <div className="inspection-photo-preview">
    {src && <img src={src} alt={alt} className="inspection-photo" />}
    {!legacySrc && <p className={`inspection-muted ${view.status === 'rejected' ? 'is-invalid' : ''}`} role="status" aria-live="polite" data-photo-status={view.status}>{queuedPhotoStatus(view, offline)}</p>}
    <button type="button" className="hub-btn hub-btn--destructive" aria-label={removeLabel} onClick={onRemove}>Remove photo</button>
  </div>;
}
