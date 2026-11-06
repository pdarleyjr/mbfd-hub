<?php

declare(strict_types=1);

namespace App\Services\Personnel\FuelIds;

use App\Models\FuelIdSource;

final readonly class FuelIdImportResult
{
    public function __construct(
        public FuelIdImportPlan $plan,
        public ?FuelIdSource $source,
        public bool $imported,
        public int $superseded = 0,
    ) {}
}
