<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Apparatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** A unit typed in the Rescue Inventory search means that unit: "R2" must never also bring up "R22". */
final class EmsUnitSearchTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $staff;

    private Apparatus $rescue22;

    /** @var array<string, string> unit => request uuid */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->rescue22 = Apparatus::query()->create([
            'station_id' => $this->rescue2->station_id, 'vin' => 'VIN-R22', 'assignment' => 'Station 2', 'unit_id' => 'R22', 'designation' => 'R 22',
            'name' => 'RESCUE R22', 'type' => 'Rescue', 'vehicle_number' => 'V-R22', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'rescue',
        ]);
        $this->rescue2->forceFill(['designation' => 'R 2', 'name' => 'RESCUE R2'])->save();
        $this->forgetEmsMemo();

        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->staff = $this->emsCaptain('Cap Tain');
        $line = [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 1]];
        foreach (['R2' => $this->rescue2, 'R22' => $this->rescue22] as $unit => $apparatus) {
            $this->requests[$unit] = $this->submitRequest($member, $line, ['target' => ['kind' => 'rescue_unit', 'apparatus_id' => $apparatus->id]])['uuid'];
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @return list<string> */
    private function found(string $term): array
    {
        return collect($this->actingAs($this->staff)->getJson('/ems/api/requests?q='.rawurlencode($term))->assertOk()->json('data.*.id'))->sort()->values()->all();
    }

    public function test_searching_for_r2_finds_the_r2_request_only(): void
    {
        $this->assertSame([$this->requests['R2']], $this->found('R2'));
        $this->assertSame([$this->requests['R2']], $this->found('r2'));
        $this->assertSame([$this->requests['R2']], $this->found(' R 2 '), 'spaces and case do not change which unit is meant');
    }

    public function test_searching_for_r22_finds_the_r22_request_only(): void
    {
        $this->assertSame([$this->requests['R22']], $this->found('R22'));
    }

    public function test_other_text_still_searches_by_substring(): void
    {
        $this->assertSame(collect($this->requests)->sort()->values()->all(), $this->found('rescue'));
        $this->assertSame(collect($this->requests)->sort()->values()->all(), $this->found('medic'));
        $this->assertSame([], $this->found('R3'));
    }

    public function test_the_unit_filter_is_by_unit_id_and_exact(): void
    {
        $byUnit = fn (Apparatus $apparatus): array => $this->actingAs($this->staff)->getJson('/ems/api/requests?unit='.$apparatus->id)->assertOk()->json('data.*.id');

        $this->assertSame([$this->requests['R2']], $byUnit($this->rescue2));
        $this->assertSame([$this->requests['R22']], $byUnit($this->rescue22));
    }
}
