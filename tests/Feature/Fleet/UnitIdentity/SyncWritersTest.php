<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\UnitIdentity;

use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Fleet\Concerns\BuildsMasterLinkFixtures;
use Tests\TestCase;

/**
 * Writers that copy data instead of taking it from a person: the identity guard still refuses a collision, but the
 * refused row is set aside for Fleet and reported, and the rest of the run carries on. Nothing bypasses the guard.
 *
 * The collision is the Nov 5 mistake left in the data: an old row whose unit ID is a truck's vehicle number.
 */
final class SyncWritersTest extends TestCase
{
    use BuildsMasterLinkFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('google_sheets.apparatus_sync_enabled', false);
    }

    public function test_a_master_number_edit_that_names_another_unit_saves_the_master_and_flags_the_truck(): void
    {
        Log::spy();
        $master = $this->master('20503');
        $truck = $this->liveApparatus('E1', '20503');
        $this->legacyApparatus('20504', null, ['type' => 'Rescue']);
        self::assertSame($master->id, $truck->fresh()->unit_master_vehicle_id);

        $master->update(['veh_number' => '20504']);

        self::assertSame('20504', $master->fresh()->veh_number, 'The master edit (or import row) is not refused.');
        $fresh = $truck->fresh();
        self::assertSame('20503', $fresh->vehicle_number, 'The truck never takes a number that is another unit.');
        self::assertNull($fresh->unit_master_vehicle_id);
        self::assertSame(Apparatus::MASTER_LINK_NEEDS_REVIEW, $fresh->master_link_status);
        self::assertStringStartsWith('Not synced from the master vehicle record:', (string) $fresh->master_link_note);
        self::assertStringContainsString('20504', (string) $fresh->master_link_note);
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => $message === 'fleet.master_link.identity_conflict'
            && $context['apparatus_id'] === $truck->id)->once();
    }

    public function test_the_link_command_reports_a_colliding_row_and_links_the_rest(): void
    {
        $this->master('V-20504');
        $this->master('24509', ['make' => 'PIERCE']);
        $colliding = $this->legacyApparatus('E5', 'v-20504');
        $this->legacyApparatus('V-20504', null, ['type' => 'Rescue']);
        $clean = $this->legacyApparatus('E2', '24509');

        $path = (string) tempnam(sys_get_temp_dir(), 'link-report-');
        $exit = Artisan::call('fleet:link-master-vehicles', ['--json' => $path]);
        $output = Artisan::output();
        $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        @unlink($path);

        self::assertSame(0, $exit, $output);
        self::assertStringNotContainsString('Linking failed', $output);
        $rows = collect($report['apparatuses'])->keyBy('unit');
        self::assertSame('needs review', $rows['E5']['outcome']);
        self::assertStringContainsString('Not synced from the master vehicle record', (string) $rows['E5']['reason']);
        self::assertSame('linked', $rows['E2']['outcome']);

        self::assertSame('v-20504', $colliding->fresh()->vehicle_number);
        self::assertNull($colliding->fresh()->unit_master_vehicle_id);
        self::assertSame(Apparatus::MASTER_LINK_NEEDS_REVIEW, $colliding->fresh()->master_link_status);
        self::assertSame(UnitMasterVehicle::query()->where('veh_number', '24509')->value('id'), $clean->fresh()->unit_master_vehicle_id);
        self::assertSame('PIERCE', $clean->fresh()->make);
    }
}
