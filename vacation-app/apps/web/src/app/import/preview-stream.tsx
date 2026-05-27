'use client';

import * as React from 'react';
import { Loader2 } from 'lucide-react';
import type {
  ColumnMapping,
  ParseStats,
  PreviewEvent,
} from '@mbfd-vacation/shared';

export type PreviewReady = Extract<PreviewEvent, { type: 'preview_ready' }>;

type State =
  | { kind: 'progress'; rowsProcessed: number; bytesProcessed: number }
  | { kind: 'ready'; payload: PreviewReady }
  | { kind: 'failed'; errorMessage: string }
  | { kind: 'idle' };

export function PreviewStream({
  runId,
  onReady,
}: {
  runId: string;
  onReady: (payload: PreviewReady) => void;
}): React.JSX.Element {
  const [state, setState] = React.useState<State>({ kind: 'idle' });

  React.useEffect(() => {
    const url = `/api/imports/${runId}/preview`;
    const src = new EventSource(url, { withCredentials: false });

    const handle = (event: MessageEvent): void => {
      try {
        const data = JSON.parse(event.data) as PreviewEvent;
        if (data.type === 'progress') {
          setState({
            kind: 'progress',
            rowsProcessed: data.rowsProcessed,
            bytesProcessed: data.bytesProcessed,
          });
        } else if (data.type === 'preview_ready') {
          setState({ kind: 'ready', payload: data });
          onReady(data);
          src.close();
        } else if (data.type === 'failed') {
          setState({ kind: 'failed', errorMessage: data.errorMessage });
          src.close();
        }
      } catch {
        /* ignore malformed */
      }
    };

    src.addEventListener('progress', handle);
    src.addEventListener('preview_ready', handle);
    src.addEventListener('failed', handle);
    src.addEventListener('snapshot', handle);
    src.onerror = () => {
      // EventSource will auto-retry; no toast needed
    };

    return () => {
      src.close();
    };
  }, [runId, onReady]);

  if (state.kind === 'idle') {
    return (
      <div className="flex items-center gap-2 text-sm text-stone-600">
        <Loader2 className="h-4 w-4 animate-spin" /> Connecting to preview stream…
      </div>
    );
  }
  if (state.kind === 'progress') {
    return (
      <div className="flex items-center gap-2 text-sm text-stone-700">
        <Loader2 className="h-4 w-4 animate-spin" />
        Parsed <strong className="tabular">{state.rowsProcessed.toLocaleString()}</strong> rows so far…
      </div>
    );
  }
  if (state.kind === 'failed') {
    return (
      <div className="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700">
        Preview failed: {state.errorMessage}
      </div>
    );
  }
  return (
    <div className="text-sm text-green-700">
      Preview ready · {state.payload.columns.length} columns ·{' '}
      {state.payload.unknownDescriptions.length} unknown codes
    </div>
  );
}

export type { ColumnMapping, ParseStats };
