'use client';

import { useRouter } from 'next/navigation';
import * as React from 'react';
import { toast } from 'sonner';
import type { ColumnMapping, WorkCodeDecision } from '@mbfd-vacation/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { api } from '@/lib/api';
import { ColumnMapper } from './column-mapper';
import { PreviewStream, type PreviewReady } from './preview-stream';
import { UnknownCodesResolver } from './unknown-codes-resolver';
import { UploadZone } from './upload-zone';

export default function ImportPage(): React.JSX.Element {
  const router = useRouter();

  const [runId, setRunId] = React.useState<string | null>(null);
  const [preview, setPreview] = React.useState<PreviewReady | null>(null);
  const [mapping, setMapping] = React.useState<ColumnMapping | null>(null);
  const [decisions, setDecisions] = React.useState<WorkCodeDecision[]>([]);
  const [committing, setCommitting] = React.useState(false);

  const onReady = React.useCallback((p: PreviewReady) => {
    setPreview(p);
    setMapping(p.suggestedMapping);
  }, []);

  const commit = async (): Promise<void> => {
    if (!runId || !mapping) return;
    setCommitting(true);
    try {
      await api.commitImport(runId, {
        columnMapping: mapping,
        workCodeDecisions: decisions,
      });
      toast.success('Commit queued — the board will update shortly.');
      router.push(`/import/runs/${runId}`);
    } catch (err) {
      toast.error(`Commit failed: ${(err as Error).message}`);
    } finally {
      setCommitting(false);
    }
  };

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader>
          <CardTitle>1. Upload</CardTitle>
          <CardDescription>Pick a Telestaff CSV or XLSX export. Large files are fine.</CardDescription>
        </CardHeader>
        <CardContent>
          {!runId ? (
            <UploadZone onUploaded={(id) => setRunId(id)} />
          ) : (
            <p className="text-sm text-stone-700">
              Uploaded — run id <code className="font-mono text-xs">{runId}</code>.
              Watching the preview stream below…
            </p>
          )}
        </CardContent>
      </Card>

      {runId && (
        <Card>
          <CardHeader>
            <CardTitle>2. Preview</CardTitle>
            <CardDescription>
              The worker is reading the file, suggesting column mappings, and finding unknown work codes.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <PreviewStream runId={runId} onReady={onReady} />
          </CardContent>
        </Card>
      )}

      {preview && mapping && (
        <Card>
          <CardHeader>
            <CardTitle>3. Map columns</CardTitle>
            <CardDescription>
              We've guessed most of these from the column headers. Confirm or change as needed.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ColumnMapper value={mapping} onChange={setMapping} />
          </CardContent>
        </Card>
      )}

      {preview && (
        <Card>
          <CardHeader>
            <CardTitle>4. Resolve unknown codes</CardTitle>
            <CardDescription>
              {preview.unknownDescriptions.length === 0
                ? "All Telestaff work codes in this file are already known."
                : `${preview.unknownDescriptions.length} description(s) need a decision before we can commit.`}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <UnknownCodesResolver
              descriptions={preview.unknownDescriptions}
              onChange={setDecisions}
            />
          </CardContent>
        </Card>
      )}

      {preview && mapping && (
        <Card>
          <CardHeader>
            <CardTitle>5. Commit</CardTitle>
            <CardDescription>
              The import runs in the background. You can leave this page; progress is on the run detail.
            </CardDescription>
          </CardHeader>
          <CardContent className="flex items-center gap-3">
            <Button disabled={committing} onClick={commit}>
              {committing ? 'Committing…' : 'Commit import'}
            </Button>
            <Button variant="ghost" onClick={() => router.push('/import/runs')}>
              Cancel
            </Button>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
