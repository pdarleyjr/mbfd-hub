<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Display;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sensitive fields must never appear in any display response: apparatus VIN,
 * Snipe-IT ids, internal notes, and current_location; defect free-text notes;
 * personnel identity on inspections.
 */
class DisplayRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $token = Str::random(48);
        config(['services.display_api.token' => $token]);
        $this->withHeader('X-Display-Token', $token);
    }

    private const SECRET_VIN = '***REMOVED_SECRET***';

    private const SECRET_NOTES = 'CONFIDENTIAL-INTERNAL-NOTE';

    private const SECRET_LOCATION = 'SECRET-BAY-LOCATION';

    private const SECRET_DEFECT_NOTES = 'DEFECT-PRIVATE-NOTE';

    private function seedFleet(): Station
    {
        $station = Station::create([
            'station_number' => 4,
            'name' => 'Station 4',
            'address' => '6880 Indian Creek Dr',
            'is_active' => true,
        ]);

        $apparatus = Apparatus::create([
            'unit_id' => 'L4',
            'station_id' => $station->id,
            'designation' => 'Ladder 4',
            'make' => 'Pierce',
            'model' => 'Ascendant',
            'year' => 2021,
            'status' => 'In Service',
            'vin' => self::SECRET_VIN,
            'notes' => self::SECRET_NOTES,
            'current_location' => self::SECRET_LOCATION,
        ]);

        ApparatusDefect::create([
            'apparatus_id' => $apparatus->id,
            'compartment' => 'Rear',
            'item' => 'SCBA Mask',
            'status' => 'Missing',
            'notes' => self::SECRET_DEFECT_NOTES,
            'resolved' => false,
        ]);

        return $station;
    }

    public function test_station_apparatus_response_redacts_sensitive_fields(): void
    {
        $station = $this->seedFleet();

        $response = $this->getJson("/api/display/stations/{$station->id}/apparatus");
        $response->assertStatus(200);

        $apparatus = $response->json('apparatus.0');
        $this->assertArrayNotHasKey('vin', $apparatus);
        $this->assertArrayNotHasKey('snipeit_asset_id', $apparatus);
        $this->assertArrayNotHasKey('snipeit_asset_tag', $apparatus);
        $this->assertArrayNotHasKey('notes', $apparatus);
        $this->assertArrayNotHasKey('current_location', $apparatus);

        $body = $response->getContent();
        $this->assertStringNotContainsString(self::SECRET_VIN, $body);
        $this->assertStringNotContainsString(self::SECRET_NOTES, $body);
        $this->assertStringNotContainsString(self::SECRET_LOCATION, $body);
    }

    public function test_critical_items_response_redacts_defect_notes(): void
    {
        $this->seedFleet();

        $response = $this->getJson('/api/display/critical-items');
        $response->assertStatus(200);

        $defect = $response->json('critical_defects.0');
        $this->assertNotNull($defect);
        $this->assertArrayNotHasKey('notes', $defect);
        $this->assertArrayNotHasKey('resolution_notes', $defect);

        $body = $response->getContent();
        $this->assertStringNotContainsString(self::SECRET_DEFECT_NOTES, $body);
        $this->assertStringNotContainsString(self::SECRET_VIN, $body);
    }

    public function test_overview_snapshot_does_not_leak_sensitive_fields(): void
    {
        $this->seedFleet();

        $body = $this->getJson('/api/display/snapshot')->getContent();

        $this->assertStringNotContainsString(self::SECRET_VIN, $body);
        $this->assertStringNotContainsString(self::SECRET_NOTES, $body);
        $this->assertStringNotContainsString(self::SECRET_LOCATION, $body);
        $this->assertStringNotContainsString(self::SECRET_DEFECT_NOTES, $body);
    }

    public function test_station_submissions_redacts_operator_identity(): void
    {
        $station = $this->seedFleet();

        $body = $this->getJson("/api/display/stations/{$station->id}/submissions")->getContent();

        // Operator name / rank are personnel identity and must not appear on
        // inspection submissions.
        $this->assertStringNotContainsString('operator_name', $body);
    }

    public function test_station_submission_history_does_not_substitute_created_at_for_canonical_checkout_completion(): void
    {
        $station = $this->seedFleet();
        $apparatus = $station->apparatuses()->sole();
        $apparatus->update(['daily_checkout_requirement' => 'required']);

        ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'History Only',
            'rank' => 'Firefighter',
            'shift' => 'A-Day',
            'review_status' => 'pending_review',
            'completed_at' => null,
        ]);

        $response = $this->getJson("/api/display/stations/{$station->id}/submissions");

        $response->assertOk()
            ->assertJsonPath('apparatus_inspection_history_only', true)
            ->assertJsonPath('daily_checkout.required_total', 1)
            ->assertJsonPath('daily_checkout.completed', 0)
            ->assertJsonPath('daily_checkout.not_checked', 1)
            ->assertJsonPath('apparatus_inspections.0.completed_at', null);
        $this->assertNotEmpty($response->json('apparatus_inspections.0.submitted_at'));
    }
}
