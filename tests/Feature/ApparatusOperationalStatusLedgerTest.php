<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Apparatus;
use App\Models\ApparatusOperationalStatusEvent;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ApparatusOperationalStatusLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_model_status_transition_records_the_prior_and_new_operational_status(): void
    {
        $apparatus = $this->makeApparatus();
        $changedAt = CarbonImmutable::parse('2026-08-25 12:00:00', 'UTC'); // 08:00 EDT

        $apparatus->timestamps = false;
        try {
            $apparatus->forceFill([
                'status' => 'Out of Service',
                'updated_at' => $changedAt,
            ])->save();
        } finally {
            $apparatus->timestamps = true;
        }

        $event = ApparatusOperationalStatusEvent::query()->sole();

        $this->assertSame($apparatus->id, $event->apparatus_id);
        $this->assertSame('In Service', $event->previous_status);
        $this->assertSame('Out of Service', $event->status);
        $this->assertSame($changedAt->toISOString(), $event->changed_at->toISOString());
    }

    public function test_an_arbitrary_model_save_does_not_create_a_status_event(): void
    {
        $apparatus = $this->makeApparatus();

        $apparatus->update(['notes' => 'Changed without an operational-status transition.']);

        $this->assertDatabaseCount('apparatus_operational_status_events', 0);
    }

    public function test_status_ledger_writes_roll_back_with_the_authoritative_status_transition(): void
    {
        $apparatus = $this->makeApparatus();

        try {
            DB::transaction(function () use ($apparatus): void {
                $apparatus->update(['status' => 'Out of Service']);

                $this->assertDatabaseCount('apparatus_operational_status_events', 1);

                throw new RuntimeException('Simulate an enclosing status-write failure.');
            });
        } catch (RuntimeException) {
            // The transaction is intentionally rolled back below.
        }

        $this->assertSame('In Service', $apparatus->fresh()->status);
        $this->assertDatabaseCount('apparatus_operational_status_events', 0);
    }

    private function makeApparatus(): Apparatus
    {
        $station = Station::query()->create([
            'station_number' => 91,
            'name' => 'Station 91',
            'address' => '91 Test Street',
            'is_active' => true,
        ]);

        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'E91',
            'designation' => 'E91',
            'name' => 'Engine 91',
            'type' => 'Engine',
            'make' => 'Test',
            'model' => 'Test',
            'year' => 2026,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
        ]);
    }
}
