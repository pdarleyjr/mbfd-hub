<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\Station;
use Carbon\CarbonImmutable;

final class ChecklistBlockTest extends IdentificationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_checklist_carries_the_block_outside_the_hashed_checklist(): void
    {
        $this->completeMasterExcept($this->unit, ['tag_number']);

        $response = $this->getJson("/api/public/apparatuses/{$this->unit->id}/checklist")->assertOk();

        $response->assertJsonPath('vehicle_identification.required', true)
            ->assertJsonPath('vehicle_identification.kind', 'vehicle')
            ->assertJsonPath('vehicle_identification.questions.0.field', 'plate')
            ->assertJsonPath('vehicle_identification.questions.0.label', 'License plate')
            ->assertJsonPath('vehicle_identification.questions.0.confirm', false)
            ->assertJsonPath('vehicle_identification.questions.0.current_value', null)
            ->assertJsonPath('vehicle_identification.questions.0.max', 32);
        $this->assertSame(
            $this->block($this->unit)['questions_version'],
            $response->json('vehicle_identification.questions_version'),
        );
        $this->assertStringNotContainsString('vehicle_identification', json_encode($response->json('checklist')));
    }

    public function test_the_checklist_version_does_not_change_when_questions_are_answered_or_closed(): void
    {
        $before = $this->getJson("/api/public/apparatuses/{$this->unit->id}/checklist")->assertOk();
        $this->assertTrue($before->json('vehicle_identification.required'));

        $this->submitCheckout([
            $this->answer('plate', 'XE1234'),
            $this->unavailable('year', 'not_applicable'),
            $this->unavailable('make', 'other', 'Painted over'),
            $this->unavailable('model', 'not_accessible_now'),
        ])->assertCreated();
        $after = $this->getJson("/api/public/apparatuses/{$this->unit->id}/checklist")->assertOk();

        $this->assertSame($before->json('checklist_version'), $after->json('checklist_version'));
        $this->assertSame($before->json('checklist_base_version'), $after->json('checklist_base_version'));
        $this->assertSame($before->json('checklist'), $after->json('checklist'));
        // Plate answered and year closed for good; make and model gave only temporary reasons.
        $this->assertSame(['make', 'model'], array_column($after->json('vehicle_identification.questions'), 'field'));
    }

    public function test_nothing_open_gives_an_empty_not_required_block(): void
    {
        $this->completeMasterExcept($this->unit);

        $this->getJson("/api/public/apparatuses/{$this->unit->id}/checklist")
            ->assertOk()
            ->assertJsonPath('vehicle_identification.required', false)
            ->assertJsonPath('vehicle_identification.questions', [])
            ->assertJsonPath('vehicle_identification.kind', 'vehicle')
            ->assertJsonCount(5, 'vehicle_identification.reasons');
    }

    public function test_an_unlinked_boat_gets_boat_questions_on_the_checklist_and_the_session_start(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-31 09:00:00', 'America/New_York'));
        $this->actingAsCanonicalUser($this->member);
        $station = Station::query()->create(['station_number' => 6, 'name' => 'Station 6', 'address' => '123 Marine Drive', 'is_active' => true]);
        $boat = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'FB6', 'name' => 'Fire Boat 6', 'designation' => 'Fire Boat 6',
            'type' => 'Fire Boat', 'slug' => 'fb6', 'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]);

        $checklist = $this->getJson("/api/public/apparatuses/{$boat->id}/checklist")->assertOk();
        $session = $this->postJson("/api/public/apparatuses/{$boat->id}/inspection-sessions")->assertCreated();

        foreach ([$checklist, $session] as $response) {
            $response->assertJsonPath('vehicle_identification.required', true)
                ->assertJsonPath('vehicle_identification.kind', 'boat')
                ->assertJsonPath('vehicle_identification.questions.0.field', 'vin')
                ->assertJsonPath('vehicle_identification.questions.0.label', 'Hull ID (HIN, transom, starboard side)');
        }
        $this->assertSame($checklist->json('vehicle_identification'), $session->json('vehicle_identification'));
        $this->assertStringNotContainsString('vehicle_identification', json_encode($session->json('checklist')), 'not in the frozen snapshot');
        $this->assertSame($session->json('inspection_session.checklist_hash'), $session->json('checklist_version'));
    }

    public function test_a_unit_that_is_not_a_daily_checkout_unit_still_gets_no_checklist(): void
    {
        $administrative = $this->legacyApparatus('A 9', null, ['daily_checkout_requirement' => 'administrative']);

        $this->getJson("/api/public/apparatuses/{$administrative->id}/checklist")
            ->assertStatus(409)
            ->assertJsonMissingPath('vehicle_identification');
    }
}
