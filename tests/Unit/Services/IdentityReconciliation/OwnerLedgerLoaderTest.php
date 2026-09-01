<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IdentityReconciliation;

use App\Services\IdentityReconciliation\InvalidOwnerLedger;
use App\Services\IdentityReconciliation\OwnerLedgerLoader;
use PHPUnit\Framework\TestCase;

final class OwnerLedgerLoaderTest extends TestCase
{
    public function test_it_loads_a_strict_machine_validated_owner_ledger(): void
    {
        $entries = (new OwnerLedgerLoader)->load($this->fixture('owner-ledger.valid.json'));

        $this->assertCount(1, $entries);
        $this->assertSame(10, $entries[0]->userId);
        $this->assertSame('10010', $entries[0]->employeeId);
        $this->assertSame('LINK', $entries[0]->decision);
        $this->assertSame('TEST-APPROVAL-001', $entries[0]->approvalReference);
    }

    public function test_it_rejects_malformed_or_incomplete_approvals(): void
    {
        $this->expectException(InvalidOwnerLedger::class);
        $this->expectExceptionMessage('entries.0');

        (new OwnerLedgerLoader)->load($this->fixture('owner-ledger.malformed.json'));
    }

    public function test_it_loads_an_explicit_credential_transition_action_without_a_hash_value(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'identity-ledger-');
        self::assertNotFalse($path);
        $entry = $this->entry('TEST-CREDENTIAL-ACTION');
        $entry['credential_action'] = 'COPY_COMPATIBLE_LEGACY_HASH';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'entries' => [$entry],
        ], JSON_THROW_ON_ERROR));

        try {
            $entries = (new OwnerLedgerLoader)->load($path);

            $this->assertSame('COPY_COMPATIBLE_LEGACY_HASH', $entries[0]->credentialAction);
            $this->assertArrayNotHasKey('password', $entries[0]->safeApproval());
            $this->assertArrayNotHasKey('password_hash', $entries[0]->safeApproval());
        } finally {
            @unlink($path);
        }
    }

    public function test_it_rejects_duplicate_user_or_employee_decisions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'identity-ledger-');
        self::assertNotFalse($path);

        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'entries' => [
                $this->entry('TEST-1'),
                $this->entry('TEST-2'),
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(InvalidOwnerLedger::class);
            $this->expectExceptionMessage('duplicate user_id');
            (new OwnerLedgerLoader)->load($path);
        } finally {
            @unlink($path);
        }
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__, 3).'/Fixtures/IdentityReconciliation/'.$name;
    }

    /** @return array<string, int|string|null> */
    private function entry(string $reference): array
    {
        return [
            'user_id' => 10,
            'employee_id' => '10010',
            'decision' => 'LINK',
            'approved_by' => 'Synthetic Identity Owner',
            'approved_at' => '2026-08-31T12:00:00-04:00',
            'approval_reference' => $reference,
            'notes' => null,
        ];
    }
}
