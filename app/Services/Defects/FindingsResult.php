<?php

declare(strict_types=1);

namespace App\Services\Defects;

/** Outcome of recording one inspection's equipment findings. */
final readonly class FindingsResult
{
    /**
     * @param  list<int>  $newDefectIds  defects created by this inspection
     * @param  list<int>  $confirmedDefectIds  existing defects the member reported again
     * @param  list<int>  $appearsCorrectedDefectIds  existing defects the member reported Present
     * @param  list<int>  $significantNewOrEscalatedDefectIds  new significant damage or routine defects escalated to significant
     */
    public function __construct(
        public array $newDefectIds = [],
        public array $confirmedDefectIds = [],
        public array $appearsCorrectedDefectIds = [],
        public array $significantNewOrEscalatedDefectIds = [],
    ) {}
}
