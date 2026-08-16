<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\StationRequest;

final readonly class StationRequestSubmissionResult
{
    public function __construct(
        public StationRequest $request,
        public bool $created,
    ) {}
}
