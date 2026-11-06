import { useEffect, useMemo, useState } from 'react';
import type { QueuedPhoto } from '../lib/db';
import {
  getQueuedPhoto,
  onPhotoUploadsChanged,
  photoProgress,
  photoUploadSummary,
  queuedPhotoBlob,
  type PhotoUploadSummary,
} from '../utils/photoUploadQueue';

const EMPTY: PhotoUploadSummary = { total: 0, sent: 0, pending: 0, rejected: 0, failed: 0 };

/** Live counts for the photos one checkout names ("3 of 4 photos sent"). */
export function usePhotoUploadSummary(ids: string[]): PhotoUploadSummary {
  const key = useMemo(() => [...new Set(ids)].sort().join(','), [ids]);
  const [summary, setSummary] = useState<PhotoUploadSummary>(EMPTY);

  useEffect(() => {
    const unique = key === '' ? [] : key.split(',');
    let active = true;
    const load = () => {
      void photoUploadSummary(unique).then((next) => { if (active) setSummary(next); }).catch(() => undefined);
    };
    load();
    const unsubscribe = onPhotoUploadsChanged(load);

    return () => { active = false; unsubscribe(); };
  }, [key]);

  return summary;
}

export interface QueuedPhotoView {
  /** Object URL of the copy saved on this device, while it is still here. */
  url: string | null;
  status: QueuedPhoto['status'] | 'unknown';
  progress?: number;
  lastError?: string;
}

/** One queued photo for a preview: its local copy and live sending state. */
export function useQueuedPhoto(id: string | undefined): QueuedPhotoView {
  const [view, setView] = useState<QueuedPhotoView>({ url: null, status: 'unknown' });

  useEffect(() => {
    if (!id) {
      setView({ url: null, status: 'unknown' });
      return undefined;
    }
    let active = true;
    let url: string | null = null;
    const refresh = (record: QueuedPhoto | undefined) => {
      if (!active) return;
      if (record && url === null) url = URL.createObjectURL(queuedPhotoBlob(record));
      setView({ url, status: record?.status ?? 'unknown', progress: photoProgress(id), lastError: record?.lastError });
    };
    const load = () => { void getQueuedPhoto(id).then(refresh).catch(() => undefined); };
    load();
    const unsubscribe = onPhotoUploadsChanged(load);

    return () => {
      active = false;
      unsubscribe();
      if (url) URL.revokeObjectURL(url);
    };
  }, [id]);

  return view;
}
