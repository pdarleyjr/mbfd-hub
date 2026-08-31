<?php

declare(strict_types=1);

namespace App\Services\IdentityReconciliation;

use App\Data\IdentityReconciliation\OwnerLedgerEntry;

final readonly class IdentityReconciliationPreview
{
    public function __construct(
        private IdentitySnapshotRepository $repository,
        private ReconciliationEngine $engine,
    ) {}

    /**
     * @param  list<OwnerLedgerEntry>  $ledger
     * @return array<string, mixed>
     */
    public function build(array $ledger): array
    {
        $snapshot = $this->repository->snapshot();

        return $this->engine->reconcile(
            $snapshot['users'],
            $snapshot['employees'],
            $ledger,
        );
    }
}
