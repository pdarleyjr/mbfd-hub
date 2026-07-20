<?php

declare(strict_types=1);

namespace App\Services\OperationalForms;

/**
 * Result of a bounded ZIP read: only the extracted text plus non-sensitive
 * accounting metadata. No raw source content is retained beyond the returned
 * text, which is transient and never stored on the form record.
 */
final class FrocZipReadResult
{
    public function __construct(
        public readonly string $text,
        public readonly int $totalEntries,
        public readonly int $textEntries,
        public readonly int $extractedBytes,
        public readonly int $mediaEntriesIgnored,
    ) {}
}
