<?php

namespace App\Jobs;

use App\Models\WorkgroupFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Automated vectorization job. Triggers when a file is uploaded to WorkgroupFiles.
 * Extracts text, chunks it, and sends to the Workgroup AI Worker for embedding.
 */
class VectorizeWorkgroupFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;

    public function __construct(
        protected int $fileId
    ) {}

    public function handle(): void
    {
        $file = WorkgroupFile::find($this->fileId);
        if (!$file) {
            Log::warning('VectorizeWorkgroupFileJob: File not found', ['file_id' => $this->fileId]);
            return;
        }

        $workerUrl = config('services.workgroup_ai.url');
        if (!$workerUrl) {
            Log::info('VectorizeWorkgroupFileJob: Worker URL not configured, skipping');
            return;
        }

        // Extract text from file
        $text = $this->extractText($file);
        if (empty($text)) {
            Log::info('VectorizeWorkgroupFileJob: No text extracted', ['file_id' => $this->fileId]);
            return;
        }

        // Chunk text (roughly 500 chars per chunk with overlap)
        $chunks = $this->chunkText($text, 500, 50);

        // Send to worker for vectorization
        try {
            $response = Http::timeout(30)->post($workerUrl . '/vectorize', [
                'chunks' => $chunks,
                'metadata' => [
                    'file_id' => $file->id,
                    'file_name' => $file->original_name ?? $file->name,
                    'session_id' => $file->workgroup_session_id ? (string) $file->workgroup_session_id : null,
                ],
            ]);

            if ($response->successful()) {
                Log::info('VectorizeWorkgroupFileJob: Success', [
                    'file_id' => $this->fileId,
                    'vectors_stored' => $response->json('vectors_stored'),
                ]);
            } else {
                Log::error('VectorizeWorkgroupFileJob: Worker error', [
                    'file_id' => $this->fileId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('VectorizeWorkgroupFileJob: Failed', [
                'file_id' => $this->fileId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function extractText(WorkgroupFile $file): string
    {
        $path = $file->file_path;
        if (!Storage::disk('local')->exists($path)) {
            return '';
        }

        $content = Storage::disk('local')->get($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['txt', 'md', 'csv'])) {
            return $content;
        }

        if ($extension === 'pdf') {
            // Basic text extraction from PDF (strip binary)
            $text = '';
            // Use regex to pull text between stream/endstream or BT/ET in PDF
            preg_match_all('/\(([^)]+)\)/', $content, $matches);
            if (!empty($matches[1])) {
                $text = implode(' ', $matches[1]);
            }
            return $text;
        }

        // For other file types, return empty (could add docx parsing later)
        return '';
    }

    protected function chunkText(string $text, int $chunkSize = 500, int $overlap = 50): array
    {
        $chunks = [];
        $length = strlen($text);
        $pos = 0;

        while ($pos < $length) {
            $chunk = substr($text, $pos, $chunkSize);
            if (!empty(trim($chunk))) {
                $chunks[] = trim($chunk);
            }
            $pos += $chunkSize - $overlap;
        }

        return $chunks;
    }
}
