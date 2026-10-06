<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Bid;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BidAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const WRITER = 'test-only-dedicated-bid-writer';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.bid.writer_token', self::WRITER);
        config()->set('services.bid.reader_token', 'test-only-reader');
        config()->set('services.bid.federation_token', 'test-only-federation');
    }

    public function test_missing_config_and_secret_reuse_fail_closed(): void
    {
        foreach ([null, '', 'test-only-reader', 'test-only-federation'] as $token) {
            config()->set('services.bid.writer_token', $token);
            $this->postJson($this->url(), $this->payload())->assertStatus(503);
        }
        $this->assertDatabaseCount('employee_bid_assignments', 0);
    }

    public function test_only_the_dedicated_writer_authenticates(): void
    {
        $this->postJson($this->url(), $this->payload())->assertUnauthorized();
        foreach (['wrong', 'test-only-reader', 'test-only-federation'] as $token) {
            $this->withToken($token)->postJson($this->url(), $this->payload())->assertUnauthorized();
        }
        $this->linkedEmployee();
        $this->publish($this->payload())->assertOk();
    }

    public function test_exact_identity_required_without_name_or_email_fallback(): void
    {
        $this->publish($this->payload())->assertUnprocessable();
        $employee = $this->linkedEmployee();
        $payload = $this->payload();
        $payload['employee_id'] = 'TEST-OTHER';
        $this->publish($payload)->assertUnprocessable();
        $this->withToken(self::WRITER)->postJson($this->url('TEST-UNKNOWN'), $this->payload())
            ->assertUnprocessable();
        $this->publish($this->payload())->assertOk();
        $this->assertDatabaseHas('employee_bid_assignments', ['employee_profile_id' => $employee->id]);
    }

    public function test_unlinked_or_mismatched_account_is_rejected(): void
    {
        $employee = $this->employee();
        $user = User::factory()->create(['employee_id' => $employee->employee_id]);
        $this->publish($this->payload())->assertUnprocessable();
        $user->forceFill(['employee_profile_id' => $employee->id])->save();
        DB::table('users')->where('id', $user->id)->update(['employee_id' => 'TEST-WRONG']);
        $this->publish($this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('employee_bid_assignments', 0);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_permanently_rejected(string $field, mixed $value): void
    {
        $this->linkedEmployee();
        $payload = $this->payload();
        $payload[$field] = $value;
        $this->publish($payload)->assertUnprocessable();
        $this->assertDatabaseCount('employee_bid_assignments', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            ['payload_version', 3], ['bid_year', '2026'], ['bid_year', 1999],
            ['term_label', '2025–2026'], ['bid_session_id', 'bad session'], ['position_id', 'A7187'],
            ['bid_selection_label', '  '], ['position_label', str_repeat('x', 201)],
            ['assignment_source', 'unknown'], ['assignment_type', 'generic'], ['a_day_code', 'G5'],
            ['a_day_code', ['G1']], ['a_day_label', 'Monday'], ['picked_at', '2026-10-05'],
            ['picked_at', null], ['is_forced', 'false'], ['source_sequence', '3'],
            ['source_sequence', -1], ['source_result_hash', 'unknown'], ['source_workbook_sha256', 'unknown'],
            ['extra_field', 'unexpected'], ['admin_actor_employee_id', 'bad id'],
        ];
    }

    public function test_retry_is_idempotent_and_changed_key_payload_is_not_success(): void
    {
        $this->linkedEmployee();
        $payload = $this->payload();
        $this->publish($payload)->assertOk()->assertJsonPath('status', 'accepted');
        $this->publish(array_reverse($payload, true))->assertStatus(409)->assertJsonPath('status', 'already_recorded')
            ->assertJsonPath('code', 'already_recorded');
        $payload['position_label'] = 'Different seat';
        $this->publish($payload)->assertUnprocessable();
        $this->assertDatabaseCount('employee_bid_assignments', 1);
        $this->assertDatabaseCount('employee_profile_events', 1);
    }

    public function test_correction_preserves_history_and_stale_delivery_cannot_replace_it(): void
    {
        $employee = $this->linkedEmployee();
        $initial = $this->payload();
        $this->publish($initial)->assertOk();
        $correction = array_replace($initial, ['idempotency_key' => 'final-correction', 'source_sequence' => 4,
            'bid_selection_label' => 'Captain 5', 'position_label' => 'Captain', 'rank_label' => 'Captain']);
        $this->publish($correction)->assertOk();
        $this->publish($initial)->assertStatus(409);
        $stale = array_replace($initial, ['idempotency_key' => 'delayed-new-key']);
        $this->publish($stale)->assertUnprocessable();
        $sameSequence = array_replace($correction, ['idempotency_key' => 'equal-sequence', 'position_label' => 'Conflict']);
        $this->publish($sameSequence)->assertUnprocessable();
        $otherSession = array_replace($correction, ['idempotency_key' => 'other-session', 'source_sequence' => 5, 'bid_session_id' => 'different-session']);
        $this->publish($otherSession)->assertUnprocessable();
        $this->assertSame(2, $employee->bidAssignments()->count());
        $this->assertSame(1, $employee->bidAssignments()->whereNull('superseded_at')->count());
        $this->assertDatabaseHas('employee_bid_assignments', ['idempotency_key' => 'final-correction', 'bid_selection_label' => 'Captain 5']);
        $this->assertNotNull($employee->bidAssignments()->where('idempotency_key', 'final-test')->firstOrFail()->superseded_at);
        $this->assertSame('Firefighter', $employee->fresh()->rank);
        $this->assertSame('Firefighter', $employee->user()->firstOrFail()->rank);
    }

    public function test_retained_assignment_has_no_pick_or_forced_provenance(): void
    {
        $this->linkedEmployee();
        $payload = array_replace($this->payload(), ['assignment_source' => 'retained_nonbiddable', 'picked_at' => null]);
        $this->publish($payload)->assertOk();
        $this->assertDatabaseHas('employee_bid_assignments', ['assignment_source' => 'retained_nonbiddable', 'picked_at' => null]);
        $payload['idempotency_key'] = 'retained-fake-pick';
        $payload['picked_at'] = '2026-10-05T12:00:00Z';
        $this->publish($payload)->assertUnprocessable();
    }

    public function test_legacy_payload_is_accepted_initially_and_cannot_overwrite_v2(): void
    {
        $this->linkedEmployee();
        $legacy = array_intersect_key($this->payload(), array_flip(['bid_year', 'bid_session_id', 'rank_label',
            'station_label', 'shift_label', 'unit_label', 'a_day_label', 'position_id', 'picked_at',
            'idempotency_key', 'is_forced', 'admin_actor_employee_id']));
        $this->publish($legacy)->assertOk();
        $v2 = array_replace($this->payload(), ['idempotency_key' => 'upgrade-v2']);
        $this->publish($v2)->assertOk();
        $legacy['idempotency_key'] = 'late-legacy';
        $this->publish($legacy)->assertUnprocessable();
        $this->assertDatabaseCount('employee_bid_assignments', 2);
    }

    public function test_database_enforces_one_current_assignment_per_employee_and_year(): void
    {
        $this->linkedEmployee();
        $this->publish($this->payload())->assertOk();
        $attributes = EmployeeBidAssignment::query()->firstOrFail()->getAttributes();
        unset($attributes['id']);
        $attributes['idempotency_key'] = 'bypass-attempt';
        $this->expectException(QueryException::class);
        DB::table('employee_bid_assignments')->insert($attributes);
    }

    public function test_history_fields_cannot_be_rewritten_or_deleted(): void
    {
        $this->linkedEmployee();
        $this->publish($this->payload())->assertOk();
        $assignment = EmployeeBidAssignment::query()->firstOrFail();
        try {
            $assignment->update(['position_label' => 'Rewritten']);
            self::fail('Historical source fields were rewritten.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\LogicException::class);
        $assignment->delete();
    }

    private function employee(): Employee
    {
        return Employee::query()->create(['employee_id' => 'TEST-BID-001', 'name' => 'Synthetic Bid Fixture',
            'rank' => 'Firefighter', 'must_change_password' => false]);
    }

    private function linkedEmployee(): Employee
    {
        $employee = $this->employee();
        User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);

        return $employee;
    }

    private function url(string $employeeId = 'TEST-BID-001'): string
    {
        return '/api/v2/members/'.$employeeId.'/bid-assignment';
    }

    private function publish(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withToken(self::WRITER)->postJson($this->url(), $payload);
    }

    private function payload(): array
    {
        return ['payload_version' => 2, 'bid_year' => 2026, 'term_label' => '2026–2027', 'bid_session_id' => 'TEST-REAL-2026',
            'employee_id' => 'TEST-BID-001', 'rank_label' => 'Firefighter', 'shift_label' => 'A Shift',
            'station_label' => 'Station #1', 'division_label' => 'Operations', 'unit_label' => 'Combat Float',
            'position_id' => 'A101', 'position_label' => 'Firefighter DE #2', 'bid_selection_label' => 'Combat Float',
            'assignment_type' => 'Floating', 'assignment_source' => 'bid_award', 'a_day_code' => 'G3', 'a_day_label' => 'Group 3',
            'picked_at' => '2026-10-05T12:00:00Z', 'idempotency_key' => 'final-test', 'is_forced' => false,
            'admin_actor_employee_id' => null, 'source_sequence' => 3, 'source_result_hash' => str_repeat('a', 64),
            'source_workbook_sha256' => str_repeat('b', 64)];
    }
}
