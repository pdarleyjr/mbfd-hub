<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SnipeIdentity;

use App\Services\SnipeIdentity\SnipeIdentityPreview;
use PHPUnit\Framework\TestCase;

final class SnipeIdentityPreviewTest extends TestCase
{
    public function test_exact_employee_number_preserves_the_existing_snipe_numeric_id(): void
    {
        $report = (new SnipeIdentityPreview)->build(
            [['id' => 20, 'employee_id' => '10010', 'name' => 'Synthetic Employee', 'rank' => 'Firefighter']],
            [['id' => 10, 'employee_id' => '10010', 'name' => 'Synthetic User', 'email' => 'synthetic@example.test']],
            [['id' => 42, 'employee_num' => '10010', 'username' => 'synthetic.user', 'email' => 'synthetic@example.test', 'name' => 'Different Display Name']],
        );

        $row = $report['rows'][0];

        $this->assertSame('EXACT_EMPLOYEE_NUM', $row['classification']);
        $this->assertSame(42, $row['current_snipe_numeric_id']);
        $this->assertSame('PRESERVE_EXISTING_SNIPE_ID_AND_CAPTURE_MAPPING_FOR_OWNER_APPROVAL', $row['proposed_action']);
        $this->assertSame('LOCAL_MAPPING_MISSING', $row['local_mapping_status']);
    }

    public function test_name_and_email_matches_never_auto_merge(): void
    {
        $preview = new SnipeIdentityPreview;
        $employees = [['id' => 20, 'employee_id' => '10010', 'name' => 'Synthetic Employee', 'rank' => null]];
        $users = [['id' => 10, 'employee_id' => '10010', 'name' => 'Synthetic User', 'email' => 'synthetic@example.test']];

        $nameOnly = $preview->build($employees, $users, [
            ['id' => 42, 'employee_num' => null, 'username' => 'another.user', 'email' => 'another@example.test', 'name' => 'Synthetic Employee'],
        ]);
        $emailOnly = $preview->build($employees, $users, [
            ['id' => 43, 'employee_num' => null, 'username' => 'synthetic.user', 'email' => 'synthetic@example.test', 'name' => 'Another Person'],
        ]);

        $this->assertSame('NAME_ONLY_REVIEW', $nameOnly['rows'][0]['classification']);
        $this->assertSame('OWNER_REVIEW_REQUIRED', $nameOnly['rows'][0]['proposed_action']);
        $this->assertNull($nameOnly['rows'][0]['current_snipe_numeric_id']);
        $this->assertSame('EMAIL_ONLY_REVIEW', $emailOnly['rows'][0]['classification']);
        $this->assertSame('OWNER_REVIEW_REQUIRED', $emailOnly['rows'][0]['proposed_action']);
        $this->assertNull($emailOnly['rows'][0]['current_snipe_numeric_id']);
    }

    public function test_duplicates_and_missing_users_are_explicit_and_deterministic(): void
    {
        $preview = new SnipeIdentityPreview;
        $employees = [['id' => 20, 'employee_id' => '10010', 'name' => 'Synthetic Employee', 'rank' => null]];
        $users = [['id' => 10, 'employee_id' => '10010', 'name' => 'Synthetic User', 'email' => 'synthetic@example.test']];

        $snipeUsers = [
            ['id' => 43, 'employee_num' => '10010', 'username' => 'second', 'email' => 'second@example.test', 'name' => 'Second'],
            ['id' => 42, 'employee_num' => '10010', 'username' => 'first', 'email' => 'first@example.test', 'name' => 'First'],
        ];
        $duplicate = $preview->build($employees, $users, $snipeUsers);
        $missing = $preview->build($employees, $users, []);

        $this->assertSame('MULTIPLE_SNIPE_USERS', $duplicate['rows'][0]['classification']);
        $this->assertSame([42, 43], $duplicate['rows'][0]['candidate_snipe_numeric_ids']);
        $this->assertSame('NO_SNIPE_MATCH', $missing['rows'][0]['classification']);
        $this->assertSame('OWNER_REVIEW_REQUIRED', $missing['rows'][0]['proposed_action']);
        $this->assertSame(
            json_encode($duplicate, JSON_THROW_ON_ERROR),
            json_encode($preview->build($employees, $users, array_reverse($snipeUsers)), JSON_THROW_ON_ERROR),
        );
    }
}
