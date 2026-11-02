import { useEffect, useRef, type ReactNode } from 'react';
import { LoadError, SkeletonRows } from '../../../ui/StateViews';
import { useEmsLoad } from '../shared';
import { BackButton } from './ui.tsx';

interface Props<T> {
  /** Names what is open, for the loading and error words ("This supply", "This unit"). */
  noun: string;
  /** Changes whenever the answer would be for something else (the item, unit or period). */
  loadKey: string;
  load: () => Promise<T>;
  backLabel: string;
  onBack: () => void;
  onForbidden: () => void;
  children: (data: T) => ReactNode;
}

/**
 * The frame around a drill-down: an explicit Back button, a skeleton while loading, and an error with Retry.
 * Focus moves to the heading marked `data-detail-heading` once the answer arrives, so keyboard and screen reader users start there.
 */
export default function DetailShell<T>({ noun, loadKey, load, backLabel, onBack, onForbidden, children }: Props<T>) {
  const { data: loaded, error, loading, reload } = useEmsLoad(async () => ({ key: loadKey, value: await load() }), loadKey, { onForbidden });
  const data = loaded !== null && loaded.key === loadKey ? loaded.value : null;
  const ref = useRef<HTMLDivElement>(null);
  const focused = useRef<string | null>(null);

  useEffect(() => {
    if (data === null || focused.current === loadKey) return;
    focused.current = loadKey;
    ref.current?.querySelector<HTMLElement>('[data-detail-heading]')?.focus({ preventScroll: false });
  }, [data, loadKey]);

  return (
    <div ref={ref} className="space-y-4" data-testid="supply-data-detail">
      <BackButton onClick={onBack} label={backLabel} />
      {error && !data && (
        <LoadError title={`${noun} could not be loaded`} message={error} onRetry={() => void reload(false)} />
      )}
      {!error && !data && loading && <SkeletonRows rows={4} />}
      {error && data && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">It could not be refreshed: {error} What you see may be out of date.</p>}
      {data !== null && children(data)}
    </div>
  );
}
