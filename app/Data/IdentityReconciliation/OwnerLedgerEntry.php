<?php

declare(strict_types=1);

namespace App\Data\IdentityReconciliation;

final readonly class OwnerLedgerEntry
{
    public function __construct(
        public ?int $userId,
        public ?string $employeeId,
        public string $decision,
        public string $approvedBy,
        public string $approvedAt,
        public string $approvalReference,
        public ?string $notes,
        public ?string $credentialAction = null,
    ) {}

    /** @return array<string, int|string|null> */
    public function safeApproval(): array
    {
        return [
            'user_id' => $this->userId,
            'employee_id' => $this->employeeId,
            'decision' => $this->decision,
            'approved_by' => $this->approvedBy,
            'approved_at' => $this->approvedAt,
            'approval_reference' => $this->approvalReference,
            'credential_action' => $this->credentialAction,
        ];
    }
}
