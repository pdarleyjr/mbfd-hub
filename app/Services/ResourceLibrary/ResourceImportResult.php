<?php

declare(strict_types=1);

namespace App\Services\ResourceLibrary;

use App\Models\ResourceDocument;

final readonly class ResourceImportResult
{
    public const CREATED = 'created';

    public const REPLACED = 'replaced';

    public const UNCHANGED = 'unchanged';

    /** @param  list<string>  $warnings */
    public function __construct(
        public string $outcome,
        public ResourceDocument $document,
        public array $warnings = [],
    ) {}
}
