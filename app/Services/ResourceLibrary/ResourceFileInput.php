<?php

declare(strict_types=1);

namespace App\Services\ResourceLibrary;

/** A local file offered to the library, with the checksum the caller expects it to have (when known). */
final readonly class ResourceFileInput
{
    public function __construct(
        public string $localPath,
        public string $filename,
        public ?string $expectedSha256 = null,
    ) {}
}
