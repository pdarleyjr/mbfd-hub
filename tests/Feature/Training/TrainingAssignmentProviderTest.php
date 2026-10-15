<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Models\Employee;
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
        Employee::query()->create(['employee_id' => '000123', 'name' => 'Synthetic Canonical Fixture', 'rank' => 'Captain', 'roster_status' => 'active']);
    }

    public function test_uses_effective_person_rank_and_raw_days_with_string_identifier(): void
    {
        Http::fake(['bid-api.example.test/*' => Http::response($this->payload())]);
        $candidates = app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
        self::assertCount(1, $candidates);
        self::assertSame('000123', $candidates[0]['employee_id']);
        self::assertSame('Firefighter', $candidates[0]['rank']);
        self::assertSame('Days', $candidates[0]['shift']);
        self::assertSame('Current assignment', $candidates[0]['term_label']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer synthetic-reader-token') && $request['as_of'] === '2026-10-15');
    }

    public function test_future_assignment_is_rejected_instead_of_substituting_latest_bid_year(): void
    {
        $payload = $this->payload();
        $payload['members'][0]['effective_from'] = '2026-11-01';
        Http::fake(['*' => Http::response($payload)]);
        $this->expectException(ValidationException::class);
        app(TrainingAssignmentProvider::class)->candidates('2026-10-15');
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

    public function test_missing_assignment_is_not_fabricated(): void
    {
        $payload = $this->payload();
        $payload['members'][0]['assignment_id'] = null;
        Http::fake(['*' => Http::response($payload)]);
        self::assertSame([], app(TrainingAssignmentProvider::class)->candidates('2026-10-15'));
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
                'assignment_id' => 'synthetic-current', 'assignment_source' => 'TELESTAFF_IMPORT', 'effective_from' => '2026-01-01', 'effective_to' => null, 'eligible' => true]]];
    }
}
