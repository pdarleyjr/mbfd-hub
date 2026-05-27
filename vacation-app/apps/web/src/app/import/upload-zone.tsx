'use client';

import { UploadCloud } from 'lucide-react';
import * as React from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { formatBytes } from '@/lib/utils';

type Props = {
  onUploaded: (runId: string, wasDuplicate: boolean) => void;
};

const ACCEPTED = ['.csv', '.xlsx', '.xlsm'];

export function UploadZone({ onUploaded }: Props): React.JSX.Element {
  const [file, setFile] = React.useState<File | null>(null);
  const [uploading, setUploading] = React.useState(false);
  const [bytesUploaded, setBytesUploaded] = React.useState(0);
  const inputRef = React.useRef<HTMLInputElement>(null);

  const onDrop = (e: React.DragEvent): void => {
    e.preventDefault();
    e.stopPropagation();
    const f = e.dataTransfer?.files?.[0];
    if (f) setFile(f);
  };

  const start = async (): Promise<void> => {
    if (!file) return;
    setUploading(true);
    setBytesUploaded(0);
    try {
      const result = await api.uploadImport(file, (loaded) => setBytesUploaded(loaded));
      toast.success(result.wasDuplicate ? 'Duplicate file — reusing previous run' : 'Upload complete');
      onUploaded(result.runId, result.wasDuplicate);
    } catch (err) {
      toast.error(`Upload failed: ${(err as Error).message}`);
    } finally {
      setUploading(false);
    }
  };

  return (
    <div
      onDragOver={(e) => e.preventDefault()}
      onDrop={onDrop}
      className="rounded-lg border-2 border-dashed border-stone-200 bg-white p-8 text-center"
    >
      <UploadCloud className="mx-auto h-10 w-10 text-stone-400" aria-hidden />
      <p className="mt-3 font-display text-lg font-semibold">Drop a Telestaff export here</p>
      <p className="mt-1 text-sm text-stone-600">
        CSV or XLSX. Files up to 1 GB are supported.
      </p>
      <div className="mt-4 flex flex-col items-center gap-2">
        <input
          ref={inputRef}
          type="file"
          accept={ACCEPTED.join(',')}
          className="hidden"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        />
        <Button variant="outline" onClick={() => inputRef.current?.click()}>
          Choose a file…
        </Button>
        {file && (
          <p className="text-sm text-stone-700">
            <strong>{file.name}</strong> — {formatBytes(file.size)}
          </p>
        )}
      </div>
      <div className="mt-6 flex items-center justify-center gap-3">
        <Button disabled={!file || uploading} onClick={start}>
          {uploading ? `Uploading… ${formatBytes(bytesUploaded)}` : 'Upload'}
        </Button>
        {file && !uploading && (
          <Button variant="ghost" onClick={() => setFile(null)}>
            Reset
          </Button>
        )}
      </div>
    </div>
  );
}
