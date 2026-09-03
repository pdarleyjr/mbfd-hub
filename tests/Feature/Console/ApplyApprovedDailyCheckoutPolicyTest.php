<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Apparatus;
use App\Models\Station;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ApplyApprovedDailyCheckoutPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_policy_restores_thirteen_rows_creates_fb6_and_is_idempotent(): void
    {
        $stations = $this->stations();
        $policy = [
            1 => ['E1' => 'engine', 'L1' => 'ladder1', 'R1' => 'rescue', 'R11' => 'rescue'],
            2 => ['E2' => 'engine2', 'R2' => 'rescue', 'R22' => 'rescue'],
            3 => ['E3' => 'engine', 'L3' => 'ladder3', 'R3' => 'rescue'],
            4 => ['E4' => 'engine', 'R4' => 'rescue', 'R44' => 'rescue'],
        ];

        foreach ($policy as $stationNumber => $units) {
            foreach ($units as $unitId => $template) {
                Apparatus::query()->create([
                    'station_id' => $stations[$stationNumber]->id,
                    'unit_id' => $unitId,
                    'designation' => $unitId,
                    'name' => $unitId,
                    'type' => str_starts_with($unitId, 'E') ? 'Engine' : (str_starts_with($unitId, 'L') ? 'Ladder' : 'Rescue'),
                    'slug' => strtolower($unitId),
                    'status' => 'In Service',
                    'daily_checkout_requirement' => 'unknown',
                    'daily_checkout_template' => 'pending',
                ]);
            }
        }

        $support = Apparatus::query()->create([
            'station_id' => $stations[2]->id,
            'unit_id' => 'A1',
            'designation' => 'A1',
            'name' => 'Air Truck A1',
            'type' => 'Air Truck',
            'slug' => 'a1',
            'status' => 'In Service',
            'daily_checkout_requirement' => 'unknown',
            'daily_checkout_template' => 'pending',
        ]);

        $dryRun = Artisan::call('daily-checkout:apply-approved-policy', ['--dry-run' => true]);
        $this->assertSame(Command::SUCCESS, $dryRun, Artisan::output());
        $this->assertSame(0, Apparatus::query()->where('daily_checkout_requirement', 'required')->count());
        $this->assertFalse(Apparatus::query()->where('unit_id', 'FB6')->exists());

        $first = Artisan::call('daily-checkout:apply-approved-policy', [
            '--confirm' => 'APPLY_APPROVED_FRONTLINE_DAILY_POLICY',
        ]);
        $firstResult = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(Command::SUCCESS, $first, Artisan::output());
        $this->assertSame(13, $firstResult['updated']);
        $this->assertSame(1, $firstResult['created']);
        $this->assertSame(14, Apparatus::query()->where('daily_checkout_requirement', 'required')->count());

        foreach ([...$policy[1], ...$policy[2], ...$policy[3], ...$policy[4], 'FB6' => 'fireboat6'] as $unitId => $template) {
            $apparatus = Apparatus::query()->where('unit_id', $unitId)->sole();
            $this->assertSame('required', $apparatus->daily_checkout_requirement->value);
            $this->assertSame($template, $apparatus->daily_checkout_template->value);
        }

        $fb6 = Apparatus::query()->where('unit_id', 'FB6')->sole();
        $this->assertSame($stations[6]->id, $fb6->station_id);
        $this->assertSame('Fire Boat 6', $fb6->name);
        $this->assertSame('Fire Boat', $fb6->type);
        $this->assertNull($fb6->make);
        $this->assertNull($fb6->model);
        $this->assertNull($fb6->vin);
        $this->assertSame('unknown', $support->fresh()->daily_checkout_requirement->value);

        $second = Artisan::call('daily-checkout:apply-approved-policy', [
            '--confirm' => 'APPLY_APPROVED_FRONTLINE_DAILY_POLICY',
        ]);
        $secondResult = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(Command::SUCCESS, $second, Artisan::output());
        $this->assertSame(0, $secondResult['updated']);
        $this->assertSame(0, $secondResult['created']);
        $this->assertSame(14, $secondResult['already_configured']);
        $this->assertSame(1, Apparatus::query()->where('unit_id', 'FB6')->count());
    }

    public function test_fb6_alias_collision_fails_closed_without_mutating_any_policy(): void
    {
        $stations = $this->stations();
        Apparatus::query()->create([
            'station_id' => $stations[6]->id,
            'unit_id' => 'FB 6',
            'designation' => 'Marine Unit',
            'name' => 'Existing Fireboat 6',
            'type' => 'Marine',
            'slug' => 'existing-fireboat-6',
            'status' => 'In Service',
            'daily_checkout_requirement' => 'unknown',
            'daily_checkout_template' => 'pending',
        ]);

        $status = Artisan::call('daily-checkout:apply-approved-policy', [
            '--confirm' => 'APPLY_APPROVED_FRONTLINE_DAILY_POLICY',
        ]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame(0, Apparatus::query()->where('daily_checkout_requirement', 'required')->count());
        $this->assertSame(1, Apparatus::query()->count());
    }

    /** @return array<int, Station> */
    private function stations(): array
    {
        $stations = [];
        foreach ([1, 2, 3, 4, 6] as $number) {
            $stations[$number] = Station::query()->create([
                'station_number' => $number,
                'name' => "Station {$number}",
                'address' => "{$number} Main St",
                'city' => 'Miami Beach',
                'state' => 'FL',
                'zip_code' => '33139',
                'is_active' => true,
            ]);
        }

        return $stations;
    }
}
