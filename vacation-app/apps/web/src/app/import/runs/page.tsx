'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { api } from '@/lib/api';
import { formatBytes, formatRelative } from '@/lib/utils';

const STATUS_TONE: Record<string, 'neutral' | 'brand' | 'success' | 'warning' | 'admin'> = {
  uploaded: 'neutral',
  parsing: 'admin',
  preview_ready: 'warning',
  committing: 'admin',
  committed: 'success',
  failed: 'brand',
  rolled_back: 'neutral',
};

export default function RunsPage(): React.JSX.Element {
  const query = useQuery({
    queryKey: ['runs'],
    queryFn: () => api.listRuns(50, 0),
    refetchInterval: 5_000,
  });

  return (
    <Card>
      <CardHeader>
        <CardTitle>Import history</CardTitle>
        <CardDescription>Every Telestaff file you've uploaded. Click into a run to roll back.</CardDescription>
      </CardHeader>
      <CardContent>
        {query.isLoading && <p className="text-sm text-stone-600">Loading…</p>}
        {query.isError && (
          <p className="text-sm text-red-700">Failed to load: {(query.error as Error).message}</p>
        )}
        {query.data && (
          <ul className="divide-y divide-stone-100">
            {query.data.runs.map((r) => (
              <li key={r.id}>
                <Link
                  href={`/import/runs/${r.id}`}
                  className="grid grid-cols-1 items-center gap-2 px-1 py-3 sm:grid-cols-[1fr_auto_auto_auto] hover:bg-stone-50"
                >
                  <div>
                    <p className="truncate font-mono text-sm">{r.fileName}</p>
                    <p className="text-xs text-stone-600">
                      {formatBytes(r.fileSize)} · {formatRelative(r.uploadedAt)}
                    </p>
                  </div>
                  <Badge tone={STATUS_TONE[r.status] ?? 'neutral'}>{r.status}</Badge>
                  <span className="text-xs font-mono text-stone-400 hidden sm:inline">
                    {r.fileSha256.slice(0, 12)}
                  </span>
                  <span className="text-xs text-stone-400">→</span>
                </Link>
              </li>
            ))}
            {query.data.runs.length === 0 && (
              <li className="py-8 text-center text-sm text-stone-600">No imports yet.</li>
            )}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
