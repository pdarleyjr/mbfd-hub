<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IdentityReconciliation;

use App\Data\IdentityReconciliation\OwnerLedgerEntry;
use App\Services\IdentityReconciliation\CredentialInspector;
use App\Services\IdentityReconciliation\InvalidOwnerLedger;
use App\Services\IdentityReconciliation\ReconciliationEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\IdentityReconciliation\IdentityFixtures;

final class ReconciliationEngineTest extends TestCase
{
    private ReconciliationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new ReconciliationEngine(new CredentialInspector('synthetic-test-fingerprint-key'));
    }

    public function test_exact_employee_id_and_same_bcrypt_hash_produce_a_link_preview(): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user()],
            [IdentityFixtures::employee()],
        );

        $this->assertSame(1, $report['summary']['exact_matches']);
        $this->assertSame('EXACT_EMPLOYEE_ID_MATCH', $report['rows'][0]['classification']);
        $this->assertSame('LINK', $report['rows'][0]['proposed_action']);
        $this->assertSame('SAME_HASH', $report['rows'][0]['credential_comparison']);
        $this->assertNull($report['rows'][0]['blocked_reason']);
    }

    public function test_employee_without_user_is_accounted_for_but_requires_owner_approval(): void
    {
        $report = $this->engine->reconcile([], [IdentityFixtures::employee()]);

        $this->assertSame(1, $report['summary']['unmatched_employees']);
        $this->assertSame('EMPLOYEE_WITHOUT_USER', $report['rows'][0]['classification']);
        $this->assertSame('CREATE_USER', $report['rows'][0]['proposed_action']);
        $this->assertSame('OWNER_APPROVAL_REQUIRED', $report['rows'][0]['blocked_reason']);
    }

    public function test_user_without_employee_identifier_is_fail_closed(): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user(['legacyEmployeeId' => null])],
            [IdentityFixtures::employee()],
        );

        $this->assertSame('USER_MISSING_EMPLOYEE_ID', $report['rows'][0]['classification']);
        $this->assertSame('BLOCKED', $report['rows'][0]['proposed_action']);
        $this->assertSame('OWNER_MAPPING_REQUIRED', $report['rows'][0]['blocked_reason']);
    }

    public function test_unknown_employee_identifier_is_not_matched_by_name(): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user(['legacyEmployeeId' => '99999', 'name' => 'Same Person'])],
            [IdentityFixtures::employee(['employeeId' => '10010', 'name' => 'Same Person'])],
        );

        $this->assertSame('NO_MATCH', $report['rows'][0]['classification']);
        $this->assertSame('EMPLOYEE_ID_NOT_FOUND', $report['rows'][0]['blocked_reason']);
        $this->assertFalse($report['controls']['name_auto_match']);
    }

    public function test_duplicate_employee_ids_block_all_affected_rows(): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user()],
            [
                IdentityFixtures::employee(),
                IdentityFixtures::employee(['id' => 21]),
            ],
        );

        $this->assertSame(3, $report['summary']['collisions']);
        $this->assertSame(1, $report['summary']['duplicate_ids']);
        $this->assertSame(['MULTIPLE_MATCHES'], array_values(array_unique(array_column($report['rows'], 'classification'))));
    }

    public function test_multiple_users_for_one_employee_block_all_affected_rows(): void
    {
        $report = $this->engine->reconcile(
            [
                IdentityFixtures::user(),
                IdentityFixtures::user(['id' => 11, 'email' => 'second@example.test']),
            ],
            [IdentityFixtures::employee()],
        );

        $this->assertSame(3, $report['summary']['collisions']);
        $this->assertSame('MULTIPLE_MATCHES', $report['rows'][0]['classification']);
        $this->assertSame('MULTIPLE_USERS_FOR_EMPLOYEE_ID', $report['rows'][0]['blocked_reason']);
    }

    public function test_leading_zero_identifiers_remain_distinct_and_whitespace_is_invalid(): void
    {
        $report = $this->engine->reconcile(
            [
                IdentityFixtures::user(['id' => 10, 'legacyEmployeeId' => '00123']),
                IdentityFixtures::user(['id' => 11, 'legacyEmployeeId' => '123', 'email' => 'other@example.test']),
                IdentityFixtures::user(['id' => 12, 'legacyEmployeeId' => ' 123 ', 'email' => 'invalid@example.test']),
            ],
            [
                IdentityFixtures::employee(['id' => 20, 'employeeId' => '00123']),
                IdentityFixtures::employee(['id' => 21, 'employeeId' => '123']),
            ],
        );

        $this->assertSame(2, $report['summary']['exact_matches']);
        $this->assertSame('INVALID_DATA', $report['rows'][2]['classification']);
        $this->assertSame('EMPLOYEE_ID_HAS_SURROUNDING_WHITESPACE', $report['rows'][2]['blocked_reason']);
    }

    public function test_different_bcrypt_hashes_are_reported_without_disclosure_and_block_linking(): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user(['passwordHash' => IdentityFixtures::BCRYPT_TWO])],
            [IdentityFixtures::employee()],
        );

        $this->assertSame('CREDENTIAL_CONFLICT', $report['rows'][0]['classification']);
        $this->assertSame('DIFFERENT_HASH', $report['rows'][0]['credential_comparison']);
        $this->assertSame('BLOCKED', $report['rows'][0]['proposed_action']);
        $this->assertSame(1, $report['summary']['exact_matches']);
        $this->assertSame(0, $report['summary']['link_ready_matches']);
        $this->assertSame(1, $report['summary']['credential_conflicts']);
        $this->assertStringNotContainsString(IdentityFixtures::BCRYPT_ONE, json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(IdentityFixtures::BCRYPT_TWO, json_encode($report, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('missingCredentialProvider')]
    public function test_missing_credentials_are_explicit_conflicts(?string $userHash, ?string $employeeHash, string $expected): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user(['passwordHash' => $userHash])],
            [IdentityFixtures::employee(['passwordHash' => $employeeHash])],
        );

        $this->assertSame('CREDENTIAL_CONFLICT', $report['rows'][0]['classification']);
        $this->assertSame($expected, $report['rows'][0]['credential_comparison']);
    }

    public function test_malformed_or_unsupported_hashes_are_never_treated_as_bcrypt_compatible(): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user(['passwordHash' => '$2y$malformed-synthetic-value'])],
            [IdentityFixtures::employee()],
        );

        $this->assertSame('CREDENTIAL_CONFLICT', $report['rows'][0]['classification']);
        $this->assertSame('ALGORITHM_INCOMPATIBLE', $report['rows'][0]['credential_comparison']);
        $this->assertSame('UNSUPPORTED', $report['rows'][0]['user']['credential']['algorithm']);
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function missingCredentialProvider(): iterable
    {
        yield 'missing User hash' => [null, IdentityFixtures::BCRYPT_ONE, 'USER_HASH_MISSING'];
        yield 'missing Employee hash' => [IdentityFixtures::BCRYPT_ONE, null, 'EMPLOYEE_HASH_MISSING'];
    }

    public function test_roles_permissions_workgroups_training_and_admin_state_are_preserved(): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user([
                'roles' => ['super_admin', 'training_admin'],
                'directPermissions' => ['training.access'],
                'effectivePermissions' => ['training.access', 'users.view'],
                'workgroups' => [[
                    'id' => 7,
                    'name' => 'Synthetic Workgroup',
                    'role' => 'admin',
                    'active' => true,
                ]],
                'notificationRelationships' => ['database_notifications' => 2],
            ])],
            [IdentityFixtures::employee()],
        );

        $preservation = $report['rows'][0]['preservation'];
        $this->assertSame(['super_admin', 'training_admin'], $preservation['roles']);
        $this->assertSame(['training.access'], $preservation['direct_permissions']);
        $this->assertSame('admin', $preservation['workgroups'][0]['role']);
        $this->assertTrue($preservation['training_access']);
        $this->assertTrue($preservation['admin_access']);
        $this->assertTrue($preservation['super_admin']);
        $this->assertSame(['database_notifications' => 2], $preservation['notification_relationships']);
    }

    public function test_froc_style_test_identity_is_quarantined_without_guessing_an_owner(): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user(['legacyEmployeeId' => 'FROC-TEST-001'])],
            [IdentityFixtures::employee()],
        );

        $this->assertSame('TEST_OR_SERVICE_ACCOUNT', $report['rows'][0]['classification']);
        $this->assertSame('QUARANTINE', $report['rows'][0]['proposed_action']);
        $this->assertSame('OWNER_CLASSIFICATION_REQUIRED', $report['rows'][0]['blocked_reason']);
        $this->assertSame(1, $report['summary']['test_service_accounts']);
    }

    public function test_snipe_employee_number_disagreement_blocks_an_otherwise_exact_match(): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user([
                'externalMappings' => [[
                    'system' => 'snipe',
                    'numeric_id' => '42',
                    'username' => 'synthetic.user',
                    'employee_num' => '99999',
                ]],
            ])],
            [IdentityFixtures::employee()],
        );

        $this->assertSame('EXTERNAL_MAPPING_CONFLICT', $report['rows'][0]['classification']);
        $this->assertSame('SNIPE_EMPLOYEE_NUMBER_DISAGREEMENT', $report['rows'][0]['blocked_reason']);
        $this->assertSame(1, $report['summary']['external_conflicts']);
    }

    public function test_unapproved_ambiguous_mapping_remains_blocked_but_an_approved_exact_ledger_mapping_can_link(): void
    {
        $user = IdentityFixtures::user(['legacyEmployeeId' => null]);
        $employee = IdentityFixtures::employee();

        $unapproved = $this->engine->reconcile([$user], [$employee]);
        $approved = $this->engine->reconcile([$user], [$employee], [new OwnerLedgerEntry(
            userId: 10,
            employeeId: '10010',
            decision: 'LINK',
            approvedBy: 'Synthetic Identity Owner',
            approvedAt: '2026-08-31T12:00:00-04:00',
            approvalReference: 'TEST-APPROVAL-001',
            notes: null,
        )]);

        $this->assertSame('USER_MISSING_EMPLOYEE_ID', $unapproved['rows'][0]['classification']);
        $this->assertSame('EXACT_EMPLOYEE_ID_MATCH', $approved['rows'][0]['classification']);
        $this->assertSame('OWNER_APPROVED_LEDGER', $approved['rows'][0]['evidence']);
        $this->assertSame('LINK', $approved['rows'][0]['proposed_action']);
    }

    public function test_ledger_disagreement_with_a_users_existing_employee_id_is_a_conflict(): void
    {
        $report = $this->engine->reconcile(
            [IdentityFixtures::user(['legacyEmployeeId' => '10011'])],
            [IdentityFixtures::employee()],
            [new OwnerLedgerEntry(
                userId: 10,
                employeeId: '10010',
                decision: 'LINK',
                approvedBy: 'Synthetic Identity Owner',
                approvedAt: '2026-08-31T12:00:00-04:00',
                approvalReference: 'TEST-APPROVAL-002',
                notes: null,
            )],
        );

        $this->assertSame('CONFLICTING_EMPLOYEE_ID', $report['rows'][0]['classification']);
        $this->assertSame('LEDGER_DISAGREES_WITH_LEGACY_EMPLOYEE_ID', $report['rows'][0]['blocked_reason']);
    }

    public function test_owner_ledger_references_must_exist_in_the_snapshot(): void
    {
        $user = IdentityFixtures::user(['id' => 1, 'legacyEmployeeId' => null]);
        $employee = IdentityFixtures::employee(['id' => 10, 'employeeId' => '1001']);

        $this->expectException(InvalidOwnerLedger::class);
        $this->expectExceptionMessage('unknown user_id 999');

        $this->engine->reconcile([$user], [$employee], [new OwnerLedgerEntry(
            userId: 999,
            employeeId: '1001',
            decision: 'LINK',
            approvedBy: 'Synthetic Owner',
            approvedAt: '2026-08-31T12:00:00+00:00',
            approvalReference: 'TEST-B02-UNKNOWN-USER',
            notes: null,
        )]);
    }

    public function test_repeated_reconciliation_is_byte_stable(): void
    {
        $users = [
            IdentityFixtures::user(['id' => 11, 'email' => 'z@example.test']),
            IdentityFixtures::user(['id' => 10, 'email' => 'a@example.test']),
        ];
        $employees = [
            IdentityFixtures::employee(['id' => 21]),
            IdentityFixtures::employee(['id' => 20]),
        ];

        $first = json_encode($this->engine->reconcile($users, $employees), JSON_THROW_ON_ERROR);
        $second = json_encode($this->engine->reconcile($users, $employees), JSON_THROW_ON_ERROR);

        $this->assertSame($first, $second);
    }
}
