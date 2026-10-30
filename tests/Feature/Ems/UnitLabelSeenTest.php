<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Apparatus;
use App\Models\Ems\InventoryRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * The unit a member picked is checked against the unit the server saves: the client also sends the label it
 * showed (target.label_seen). A mismatch is refused, never saved; an old page that sends no label still works.
 */
final class UnitLabelSeenTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private Apparatus $r22;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->rescue2->update(['name' => 'RESCUE R2', 'designation' => 'R 2']);
        $this->r22 = Apparatus::query()->create([
            'station_id' => $this->rescue2->station_id, 'vin' => 'VIN-R22', 'assignment' => 'Station 2', 'unit_id' => 'R22', 'designation' => 'R 22',
            'name' => 'RESCUE R22', 'type' => 'Rescue', 'vehicle_number' => 'V-R22', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'rescue',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function body(Apparatus $unit, ?string $labelSeen, bool $withLabel = true): array
    {
        $target = ['kind' => 'rescue_unit', 'apparatus_id' => $unit->id];
        if ($withLabel) {
            $target['label_seen'] = $labelSeen;
        }

        return [
            'client_submission_id' => (string) Str::uuid(),
            'target' => $target,
            'lines' => [['form' => 'airway-bag', 'item_key' => 'airway.top.gloves', 'quantity' => 1]],
        ];
    }

    public function test_a_label_that_matches_the_unit_is_accepted_and_the_unit_is_saved_by_id(): void
    {
        $this->actingAs($this->emsMember());

        $this->postJson('/employee/forms/api/ems/requests', $this->body($this->rescue2, 'RESCUE R2'))->assertSuccessful();
        $this->postJson('/employee/forms/api/ems/requests', $this->body($this->r22, 'RESCUE R22'))->assertSuccessful();

        $saved = InventoryRequest::query()->orderBy('id')->get(['apparatus_id', 'target_label']);
        $this->assertSame([[$this->rescue2->id, 'RESCUE R2'], [$this->r22->id, 'RESCUE R22']], $saved->map(fn ($r): array => [$r->apparatus_id, $r->target_label])->all());
    }

    public function test_a_label_that_differs_from_the_unit_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAs($this->emsMember());

        foreach ([[$this->r22, 'RESCUE R2'], [$this->rescue2, 'RESCUE R22'], [$this->rescue2, 'ENGINE 1']] as [$unit, $seen]) {
            $this->postJson('/employee/forms/api/ems/requests', $this->body($unit, $seen))
                ->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('error.code', 'unit_mismatch');
        }
        $this->assertSame(0, InventoryRequest::query()->count());
    }

    public function test_the_refusal_asks_the_member_to_choose_the_unit_again(): void
    {
        $this->actingAs($this->emsMember());

        $message = $this->postJson('/employee/forms/api/ems/requests', $this->body($this->r22, 'RESCUE R2'))->json('error.message');

        $this->assertStringContainsString('choose the unit again', (string) $message);
    }

    public function test_a_request_without_a_label_still_works_for_old_pages_and_queued_requests(): void
    {
        $this->actingAs($this->emsMember());

        $this->postJson('/employee/forms/api/ems/requests', $this->body($this->r22, null, false))->assertSuccessful();
        $this->postJson('/employee/forms/api/ems/requests', $this->body($this->rescue2, null))->assertSuccessful(); // an explicit null is the same as absent

        $this->assertSame([$this->r22->id, $this->rescue2->id], InventoryRequest::query()->orderBy('id')->pluck('apparatus_id')->all());
    }

    public function test_case_and_spacing_do_not_cause_a_false_refusal(): void
    {
        $this->actingAs($this->emsMember());

        $this->postJson('/employee/forms/api/ems/requests', $this->body($this->rescue2, '  rescue r2 '))->assertSuccessful();
    }

    public function test_a_detail_request_ignores_the_label(): void
    {
        $this->actingAs($this->emsMember());

        $this->postJson('/employee/forms/api/ems/requests', [
            'client_submission_id' => (string) Str::uuid(),
            'target' => ['kind' => 'detail_event', 'label_seen' => 'anything'],
            'lines' => [['form' => 'airway-bag', 'item_key' => 'airway.top.gloves', 'quantity' => 1]],
        ])->assertSuccessful();
    }
}
