<?php

declare(strict_types=1);

namespace App\Services\ResourceLibrary;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use Throwable;

/** Reads page count and searchable text from a local PDF. A failure yields nulls, never invented values. */
final class ResourcePdfInspector
{
    /** Keeps one document from filling the table; the largest manual today is well under 1 MB of text. */
    private const MAX_TEXT_CHARACTERS = 3_000_000;

    /** @return array{text: string|null, pages: int|null} */
    public function inspect(string $localPdfPath): array
    {
        try {
            $document = (new Parser)->parseFile($localPdfPath);
            $pages = count($document->getPages());
            $text = $this->clean($document->getText());

            return ['text' => $text === '' ? null : $text, 'pages' => $pages > 0 ? $pages : null];
        } catch (Throwable $exception) {
            Log::warning('Resource library PDF inspection failed.', ['file' => basename($localPdfPath), 'error' => $exception->getMessage()]);

            return ['text' => null, 'pages' => null];
        }
    }

    private function clean(string $text): string
    {
        // PostgreSQL text rejects NUL bytes; PDFs also carry stray control characters and invalid UTF-8.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, self::MAX_TEXT_CHARACTERS);
    }
}
