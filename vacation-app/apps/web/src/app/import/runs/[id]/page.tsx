'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams, useRouter } from 'next/navigation';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { api } from '@/lib/api';
import { formatBytes, formatRelative } from '@/lib/utils';

export default function RunDetailPage(): React.JSX.Element {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const qc = useQueryClient();
  const [confirming, setConfirming] = useState(false);

  const id = params.id;
  const query = useQuery({
    queryKey: ['run', id],
    queryFn: () => api.getRun(id),
    refetchInterval: (q) => {
      const s = (q.state.data as { status?: string } | undefined)?.status;
      return s === 'committed' || s === 'failed' || s === 'rolled_back' ? false : 2_000;
    },
  });

  const rollback = useMutation({
    mutationFn: () => api.rollbackImport(id),
    onSuccess: (result) => {
      toast.success(`Rolled back. Restored ${result.restoredCount} prior entries.`);
      qc.invalidateQueries({ queryKey: ['run', id] });
      qc.invalidateQueries({ queryKey: ['runs'] });
    },
    onError: (err: Error) => toast.error(`Rollback failed: ${err.message}`),
  });

  if (query.isLoading || !query.data) return <p className="text-sm text-stone-600">Loading…</p>;
  if (query.isError) return <p className="text-sm text-red-700">{(query.error as Error).message}</p>;

  const r = query.data;
  const stats = (r.parseStats ?? {}) as Record<string, unknown>;

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <CardHeader>
          <div className="flex items-start justify-between gap-3">
            <div>
              <CardTitle>{r.fileName}</CardTitle>
              <CardDescription>
                {formatBytes(r.fileSize)} · uploaded {formatRelative(r.uploadedAt)} ·{' '}
                <code className="font-mono text-xs">{r.fileSha256.slice(0, 16)}…</code>
              </CardDescription>
            </div>
            <Badge tone={r.status === 'committed' ? 'success' : r.status === 'failed' ? 'brand' : 'admin'}>
              {r.status}
            </Badge>
          </div>
        </CardHeader>
        <CardContent>
          {r.errorMessage && (
            <div className="mb-3 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700">
              {r.errorMessage}
            </div>
          )}
          <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            <Stat label="Total rows" value={stats.totalRows} />
            <Stat label="Parsed" value={stats.parsedRows} />
            <Stat label="Skipped" value={stats.skippedRows} />
            <Stat label="Errors" value={stats.errorRows} />
            <Stat label="Unique employees" value={stats.uniqueEmployees} />
            <Stat label="New leave codes" value={stats.newLeaveCodesInserted} />
            <Stat label="Entries inserted" value={stats.leaveEntriesInserted} />
            <Stat label="Entries superseded" value={stats.leaveEntriesSuperseded} />
          </dl>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Roll back this import</CardTitle>
          <CardDescription>
            Reverses every leave entry from this run. Prior entries that were superseded are restored.
            Safe and reversible (you can re-commit the run later).
          </CardDescription>
        </CardHeader>
        <CardContent className="flex items-center gap-3">
          {!confirming ? (
            <Button
              variant="destructive"
              onClick={() => setConfirming(true)}
              disabled={r.status === 'rolled_back' || r.status !== 'committed'}
            >
              Roll back…
            </Button>
          ) : (
            <>
              <Button
                variant="destructive"
                disabled={rollback.isPending}
                onClick={() => rollback.mutate()}
              >
                {rollback.isPending ? 'Rolling back…' : 'Confirm rollback'}
              </Button>
              <Button variant="ghost" onClick={() => setConfirming(false)}>
                Cancel
              </Button>
            </>
          )}
          <Button variant="ghost" onClick={() => router.push('/import/runs')}>
            All runs
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: unknown }): React.JSX.Element {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-stone-600">{label}</dt>
      <dd className="font-display text-lg font-semibold tabular">
        {value == null ? '—' : String(value)}
      </dd>
    </div>
  );
}
