<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RagDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Ingests admin-uploaded documents into the support-chatbot RAG knowledge base.
 *
 * Extracts text in the Hub (PDF via smalot/pdfparser, DOCX via ZipArchive,
 * plain text directly), then hands the text to the Cloudflare Worker's
 * secret-protected /ingest endpoint, which chunks + embeds (bge-large, the
 * same model the index was built with) and upserts into Vectorize
 * `mbfd-rag-index`. Deletion removes the document's vectors by id.
 *
 * The chatbot (mbfd-support-ai) queries that index, so uploaded docs become
 * answerable within seconds (Vectorize indexing is async).
 */
class KnowledgeBaseService
{
    /** Extensions we can extract text from. */
    public const SUPPORTED = ['pdf', 'docx', 'txt', 'md', 'csv'];

    private string $workerUrl;
    private ?string $secret;

    public function __construct()
    {
        $this->workerUrl = rtrim((string) config('cloudflare.worker_url'), '/');
        $this->secret = config('cloudflare.worker_api_secret');
    }

    public function isConfigured(): bool
    {
        return $this->workerUrl !== '' && ! empty($this->secret);
    }

    /**
     * Extract text, ingest into the RAG index, and record the document.
     * Re-uploading the same filename replaces the previous version.
     */
    public function ingest(UploadedFile $file, ?int $userId = null): RagDocument
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Knowledge base worker is not configured.');
        }

        $filename = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, self::SUPPORTED, true)) {
            throw new \RuntimeException("Unsupported file type: .{$ext}. Allowed: " . implode(', ', self::SUPPORTED) . '.');
        }

        $text = $this->extractText($file->getRealPath(), $ext);
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException("No extractable text found in \"{$filename}\". A scanned/image-only PDF can't be indexed — upload a text-based version.");
        }

        $sourceKey = $filename;

        // Replace an existing version: remove its old vectors first.
        $existing = RagDocument::where('source_key', $sourceKey)->first();
        if ($existing && ! empty($existing->chunk_ids)) {
            $this->callWorker('/delete', ['ids' => $existing->chunk_ids]);
        }

        $resp = $this->callWorker('/ingest', ['source' => $sourceKey, 'text' => $text]);
        if (! ($resp['success'] ?? false)) {
            throw new \RuntimeException('Ingest failed: ' . ($resp['error'] ?? 'unknown worker error'));
        }

        return RagDocument::updateOrCreate(
            ['source_key' => $sourceKey],
            [
                'filename' => $filename,
                'chunk_count' => (int) ($resp['chunks'] ?? 0),
                'chunk_ids' => $resp['ids'] ?? [],
                'size' => (int) $file->getSize(),
                'mime' => $file->getMimeType(),
                'status' => 'indexed',
                'uploaded_by' => $userId,
            ],
        );
    }

    /**
     * Remove a document's vectors from the index and delete the record.
     */
    public function delete(RagDocument $doc): void
    {
        if (! empty($doc->chunk_ids)) {
            $resp = $this->callWorker('/delete', ['ids' => $doc->chunk_ids]);
            if (! ($resp['success'] ?? false)) {
                Log::warning('[KnowledgeBase] Vector delete failed', ['source' => $doc->source_key, 'resp' => $resp]);
            }
        }
        $doc->delete();
    }

    // ── extraction ──────────────────────────────────────────────────────────

    private function extractText(string $path, string $ext): string
    {
        return match ($ext) {
            'txt', 'md', 'csv' => (string) file_get_contents($path),
            'docx' => $this->extractDocx($path),
            'pdf' => $this->extractPdf($path),
            default => '',
        };
    }

    /** DOCX is a zip; word/document.xml holds the body. Convert paragraph/line
     *  tags to whitespace, strip the rest. Reliable, no external dependency. */
    private function extractDocx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return '';
        }
        $xml = str_replace(['</w:p>', '<w:br/>', '<w:br />', '<w:tab/>'], ["\n", "\n", "\n", "\t"], $xml);
        $text = strip_tags($xml);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function extractPdf(string $path): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);

        return trim($pdf->getText());
    }

    // ── worker IO ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function callWorker(string $endpoint, array $payload): array
    {
        $resp = Http::withHeaders(['x-api-secret' => (string) $this->secret])
            ->timeout(180)
            ->acceptJson()
            ->post($this->workerUrl . $endpoint, $payload);

        return $resp->json() ?? ['success' => false, 'error' => "worker HTTP {$resp->status()}"];
    }
}
