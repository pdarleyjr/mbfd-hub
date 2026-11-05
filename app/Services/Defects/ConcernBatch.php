<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\ApparatusInspectionConcern;

/**
 * What InspectionConcernRecorder::begin() leaves for the findings pass of one inspection.
 */
final readonly class ConcernBatch
{
    /**
     * @param  list<array<string, mixed>>  $compartments  pseudo compartments, one per new issue, for recordFindings()
     * @param  list<ApparatusInspectionConcern>  $newIssues  issues that become (or join) a defect through recordFindings()
     * @param  list<array{row: ApparatusInspectionConcern, defect_id: int, matched: bool}>  $confirmations  issues that add to a defect already on record
     *                                                                                                      (matched: found by its names, not picked by the member)
     */
    public function __construct(
        public array $compartments = [],
        public array $newIssues = [],
        public array $confirmations = [],
    ) {}
}
