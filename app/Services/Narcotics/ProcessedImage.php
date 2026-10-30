<?php

declare(strict_types=1);

namespace App\Services\Narcotics;

/**
 * A validated, upright, metadata-free photo in three sizes, all in one format. Built by
 * NarcoticsImageProcessor; the three byte strings contain only freshly encoded pixels.
 */
final readonly class ProcessedImage
{
    public function __construct(
        public string $mime,
        public string $extension,
        public string $full,
        public int $width,
        public int $height,
        public string $preview,
        public string $thumb,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->full);
    }

    public function bytes(): int
    {
        return strlen($this->full);
    }
}
