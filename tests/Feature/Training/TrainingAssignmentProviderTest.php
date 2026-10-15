<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Services\Training\TrainingAssignmentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TrainingAssignmentProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('training-roster.bid_api_url', 'https://bid-api.example.test');
        config()->set('services.bid.reader_token', 'synthetic-reader-token');
        $employee = Employee::query()->create(['employee_id' => '000123', 'name' => 'Synthetic Canonical Fixture', 'rank' => 'Captain', 'roster_status' => 'active']);
        $this->publish($employee);
    }

    public function test_uses_published_bid_shift_with_current_person_rank_and_string_identifier(): void
    {
        Http::fake(['bid-api.example.test/*' => Http::response($this->payload())]);
        $candidates = app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
        self::assertCount(1, $candidates);
        self::assertSame('000123', $candidates[0]['employee_id']);
        self::assertSame('Firefighter', $candidates[0]['rank']);
        self::assertSame('C Shift', $candidates[0]['shift']);
        self::assertSame('2026–2027', $candidates[0]['term_label']);
        self::assertSame('published_bid_selection', $candidates[0]['assignment_basis']);
        self::assertSame(237, $candidates[0]['source_sequence']);
        self::assertSame(str_repeat('a', 64), $candidates[0]['source_result_hash']);
        self::assertNull($candidates[0]['effective_from']);
        self::assertNull($candidates[0]['effective_to']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer synthetic-reader-token') && $request['as_of'] === '2026-10-15');
    }

    public function test_operational_assignment_effective_date_does_not_replace_published_bid_selection(): void
    {
        $payload = $this->payload();
        $payload['members'][0]['effective_from'] = '2026-11-01';
        Http::fake(['*' => Http::response($payload)]);
        $candidates = app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
        self::assertSame('C Shift', $candidates[0]['shift']);
        self::assertNull($candidates[0]['effective_from']);
    }

    public function test_duplicate_identifiers_fail_closed(): void
    {
        $payload = $this->payload();
        $payload['members'][] = $payload['members'][0];
        Http::fake(['*' => Http::response($payload)]);
        $this->expectException(ValidationException::class);
        app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
    }

    public function test_departed_personnel_are_not_kiosk_candidates(): void
    {
        Employee::query()->firstOrFail()->update(['roster_status' => 'departed']);
        Http::fake(['*' => Http::response($this->payload())]);
        self::assertSame([], app(TrainingAssignmentProvider::class)->candidates('2026-10-15'));
    }

    public function test_unknown_bid_status_requires_active_hub_identity_and_is_preserved_as_unknown(): void
    {
        $payload = $this->payload();
        $payload['members'][0]['employment_status'] = 'unknown';
        Http::fake(['*' => Http::response($payload)]);
        $candidates = app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
        self::assertCount(1, $candidates);
        self::assertSame('unknown', $candidates[0]['source_employment_status']);
        Employee::query()->firstOrFail()->update(['roster_status' => 'departed']);
        self::assertSame([], app(TrainingAssignmentProvider::class)->candidates('2026-10-15'));
    }

    public function test_explicit_inactive_bid_status_is_excluded_even_when_bridge_marks_available(): void
    {
        $payload = $this->payload();
        $payload['members'][0]['employment_status'] = 'inactive';
        Http::fake(['*' => Http::response($payload)]);
        self::assertSame([], app(TrainingAssignmentProvider::class)->candidates('2026-10-15'));
    }

    public function test_published_selection_remains_available_without_operational_placement(): void
    {
        $payload = $this->payload();
        $payload['members'][0]['assignment_id'] = null;
        $payload['members'][0]['shift'] = null;
        Http::fake(['*' => Http::response($payload)]);
        $candidates = app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
        self::assertCount(1, $candidates);
        self::assertSame('C Shift', $candidates[0]['shift']);
    }

    public function test_retained_nonbiddable_revision_preserves_its_actual_source_and_days(): void
    {
        EmployeeBidAssignment::query()->firstOrFail()->update(['superseded_at' => '2026-10-10 12:00:00']);
        $this->publish(Employee::query()->firstOrFail(), ['assignment_source' => 'retained_nonbiddable', 'shift_label' => 'Days', 'picked_at' => null, 'source_sequence' => 238, 'created_at' => '2026-10-10 12:00:00', 'idempotency_key' => 'synthetic-correction']);
        Http::fake(['*' => Http::response($this->payload())]);
        $candidates = app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
        self::assertCount(1, $candidates);
        self::assertSame('retained_nonbiddable', $candidates[0]['assignment_source']);
        self::assertSame('Days', $candidates[0]['shift']);
        self::assertSame(238, $candidates[0]['source_sequence']);
    }

    public function test_as_of_correction_uses_publication_revision_available_on_attendance_date(): void
    {
        EmployeeBidAssignment::query()->firstOrFail()->update(['superseded_at' => '2026-10-20 12:00:00']);
        $this->publish(Employee::query()->firstOrFail(), ['shift_label' => 'A Shift', 'source_sequence' => 238, 'created_at' => '2026-10-20 12:00:00', 'idempotency_key' => 'synthetic-later-correction']);
        Http::fake(['*' => Http::response($this->payload())]);
        $candidates = app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
        self::assertSame('C Shift', $candidates[0]['shift']);
        self::assertSame(237, $candidates[0]['source_sequence']);
    }

    public function test_missing_publication_fails_closed_instead_of_using_old_operational_shift(): void
    {
        EmployeeBidAssignment::query()->firstOrFail()->update(['superseded_at' => '2026-10-10 12:00:00']);
        Http::fake(['*' => Http::response($this->payload())]);
        $this->expectException(ValidationException::class);
        app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
    }

    public function test_future_publication_does_not_change_the_historical_bid_term(): void
    {
        $this->publish(Employee::query()->firstOrFail(), ['bid_year' => 2027, 'term_label' => '2027–2028', 'shift_label' => 'B Shift', 'created_at' => '2026-10-16 04:00:00', 'idempotency_key' => 'synthetic-next-term']);
        Http::fake(['*' => Http::response($this->payload())]);
        $candidates = app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
        self::assertSame('2026–2027', $candidates[0]['term_label']);
        self::assertSame('C Shift', $candidates[0]['shift']);
    }

    public function test_latest_publication_within_new_york_day_supplies_its_validated_term(): void
    {
        $this->publish(Employee::query()->firstOrFail(), ['bid_year' => 2027, 'term_label' => '2027–2028', 'shift_label' => 'B Shift', 'created_at' => '2026-10-16 03:59:59', 'idempotency_key' => 'synthetic-new-term']);
        Http::fake(['*' => Http::response($this->payload())]);
        $candidates = app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
        self::assertSame('2027–2028', $candidates[0]['term_label']);
        self::assertSame('B Shift', $candidates[0]['shift']);
    }

    public function test_invalid_published_provenance_fails_closed(): void
    {
        EmployeeBidAssignment::query()->firstOrFail()->update(['superseded_at' => '2026-10-10 12:00:00']);
        $this->publish(Employee::query()->firstOrFail(), ['source_result_hash' => null, 'idempotency_key' => 'synthetic-incomplete']);
        Http::fake(['*' => Http::response($this->payload())]);
        $this->expectException(ValidationException::class);
        app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
    }

    public function test_unavailable_service_is_not_an_empty_successful_roster(): void
    {
        Http::fake(['*' => Http::response(['error' => 'bridge_disabled'], 503)]);
        $this->expectException(ValidationException::class);
        app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
    }

    public function test_stale_response_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['generated_at'] = now()->subMinutes(5)->toIso8601String();
        Http::fake(['*' => Http::response($payload)]);
        $this->expectException(ValidationException::class);
        app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
    }

    private function payload(): array
    {
        return ['schema_version' => 1, 'as_of' => '2026-10-15', 'generated_at' => now()->toIso8601String(), 'source_updated_at' => 1, 'term_label' => null,
            'members' => [['employee_id' => '000123', 'first_name' => 'Synthetic', 'last_name' => 'Fixture', 'rank' => 'Firefighter', 'shift' => 'Days',
                'assignment_id' => 'synthetic-current', 'assignment_source' => 'TELESTAFF_IMPORT', 'effective_from' => '2026-01-01', 'effective_to' => null, 'eligible' => true, 'employment_status' => 'active']]];
    }

    private function publish(Employee $employee, array $overrides = []): EmployeeBidAssignment
    {
        return EmployeeBidAssignment::query()->forceCreate(array_replace([
            'employee_profile_id' => $employee->getKey(), 'payload_version' => 2, 'bid_year' => 2026,
            'term_label' => '2026–2027', 'bid_session_id' => 'synthetic-official-session', 'position_id' => 'C001',
            'rank_label' => 'Captain', 'shift_label' => 'C Shift', 'station_label' => 'Station 1',
            'unit_label' => 'Engine', 'bid_selection_label' => 'Synthetic assignment', 'assignment_source' => 'bid_award',
            'a_day_label' => 'Group 1', 'picked_at' => '2026-10-01T12:00:00Z', 'idempotency_key' => 'synthetic-publication',
            'payload_hash' => str_repeat('b', 64), 'is_forced' => false, 'source_sequence' => 237,
            'source_result_hash' => str_repeat('a', 64), 'source_workbook_sha256' => str_repeat('c', 64),
            'created_at' => '2026-10-01 12:00:00',
        ], $overrides));
    }
}
