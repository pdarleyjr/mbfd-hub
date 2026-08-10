<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\FireEquipmentRequest;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicFireEquipmentRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeStation(): Station
    {
        return Station::create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1051 Jefferson Avenue',
            'is_active' => true,
        ]);
    }

    public function test_public_equipment_request_is_saved_for_admin_review_without_losing_items(): void
    {
        Storage::fake('public');
        $station = $this->makeStation();

        $response = $this->postJson('/api/public/fire_equipment_request', [
            'station' => 'Station 1',
            'date' => '2026-08-10',
            'requested_by' => 'Firefighter Test',
            'items' => [
                [
                    'description' => 'Akron nozzle',
                    'quantity' => 2,
                    'reason' => 'Damaged/Broken',
                    'pd_case_number' => null,
                    'photo' => null,
                ],
                [
                    'description' => 'Portable radio',
                    'quantity' => 1,
                    'reason' => 'Stolen',
                    'pd_case_number' => 'MBPD-2026-1001',
                    'photo' => null,
                ],
            ],
            'explanation' => 'Replacement is required before the next shift.',
            'member_signature' => null,
            'officer_signature' => null,
            'submitted_at' => '2026-08-10T16:00:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('station_id', $station->id)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('priority', 'high');

        $request = FireEquipmentRequest::sole();
        $this->assertSame('Firefighter Test', $request->requested_by_name);
        $this->assertSame('MBPD-2026-1001', $request->pd_case_number);
        $this->assertCount(2, $request->form_data['items']);
        $this->assertSame('Portable radio', $request->form_data['items'][1]['description']);
    }

    public function test_stolen_item_requires_a_police_case_number(): void
    {
        $this->makeStation();

        $this->postJson('/api/public/fire_equipment_request', [
            'station' => 'Station 1',
            'date' => '2026-08-10',
            'requested_by' => 'Firefighter Test',
            'items' => [[
                'description' => 'Portable radio',
                'quantity' => 1,
                'reason' => 'Stolen',
                'pd_case_number' => null,
                'photo' => null,
            ]],
            'explanation' => 'Radio could not be located.',
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.pd_case_number');

        $this->assertDatabaseCount('fire_equipment_requests', 0);
    }
}
