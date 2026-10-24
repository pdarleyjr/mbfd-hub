<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Services\Fleet\VehicleIdentificationPlanner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Fleet\Concerns\BuildsMasterLinkFixtures;
use Tests\Feature\Fleet\Concerns\BuildsVehicleSwapFixtures;
use Tests\TestCase;

/**
 * E2 (checkout unit, linked to the master record the sync created from its vehicle number V-202
 * and VIN-202) plus the helpers every identification test needs. Notifications and queues are faked.
 */
abstract class IdentificationTestCase extends TestCase
{
    use BuildsMasterLinkFixtures;
    use BuildsVehicleSwapFixtures;
    use RefreshDatabase;

    protected const VIN_A = '1FTFW1ET5DFC10312';

    protected const VIN_B = '2C3CDXCT9EH123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** The master vehicle record E2 is linked to. */
    protected function unitMaster(): UnitMasterVehicle
    {
        $master = $this->unit->fresh()->masterVehicle;
        $this->assertNotNull($master, 'The fixture unit should be linked to a master record.');

        return $master;
    }

    /**
     * Make a linked unit's master record complete except for the given columns.
     *
     * @param  list<string>  $blank
     */
    protected function completeMasterExcept(Apparatus $unit, array $blank = []): UnitMasterVehicle
    {
        $master = $unit->fresh()->masterVehicle;
        $values = ['year' => '2022', 'make' => 'Pierce', 'model' => 'Enforcer', 'tag_number' => 'XE1234', 'serial_number' => self::VIN_A];
        foreach ($blank as $column) {
            $values[$column] = null;
        }
        $master->forceFill($values)->save();

        return $master->fresh();
    }

    /** Cut a unit loose from its master record the way a never-matched production unit is. */
    protected function unlink(Apparatus $unit, ?string $vehicleNumber = null): Apparatus
    {
        Apparatus::query()->whereKey($unit->getKey())->toBase()->update([
            'unit_master_vehicle_id' => null,
            'master_link_status' => Apparatus::MASTER_LINK_NEEDS_REVIEW,
            'master_link_note' => 'Test: not linked.',
            'vehicle_number' => $vehicleNumber ?? $unit->vehicle_number,
            'vin' => null,
        ]);

        return $unit->fresh();
    }

    /**
     * The Captain 5 unit as production has it: an administrative unit linked to the Ford F-250 record, plate XD1904.
     *
     * @param  list<string>  $blank  master columns to leave empty
     */
    protected function captainFive(?string $proposedPlate = null, array $blank = []): Apparatus
    {
        $unit = Apparatus::query()->create([
            'unit_id' => 'Captain 5', 'name' => 'Captain 5', 'designation' => 'Captain 5', 'type' => 'SUV', 'vehicle_number' => '22530',
            'status' => 'In Service', 'daily_checkout_requirement' => 'administrative', 'daily_checkout_template' => 'pending',
        ]);
        $values = ['year' => '2022', 'make' => 'Ford', 'model' => 'F-250', 'tag_number' => 'XD1904', 'serial_number' => 'TESTVIN000000530'];
        foreach ($blank as $column) {
            $values[$column] = null;
        }
        $master = $unit->fresh()->masterVehicle;
        $master->forceFill($values)->save();
        if ($proposedPlate !== null) {
            UnitMasterVehicleChange::query()->create([
                'unit_master_vehicle_id' => $master->id, 'field' => 'tag_number', 'old_value' => 'XD1904', 'new_value' => $proposedPlate,
                'status' => UnitMasterVehicleChange::STATUS_PROPOSED, 'source' => UnitMasterVehicleChange::SOURCE_FY27,
            ]);
        }

        return $unit->fresh();
    }

    /** @return array<string, mixed> the block a crew sees for a unit */
    protected function block(Apparatus $unit): array
    {
        return app(VehicleIdentificationPlanner::class)->block($unit->fresh());
    }

    /** @return list<string> */
    protected function openFields(Apparatus $unit): array
    {
        return array_column($this->block($unit)['questions'], 'field');
    }

    /**
     * A full Daily Checkout payload for E2 carrying identification answers.
     *
     * @param  list<array<string, mixed>>  $answers
     * @return array<string, mixed>
     */
    protected function checkout(array $answers, ?string $clientSubmissionId = null): array
    {
        $payload = $this->payload();
        $payload['client_submission_id'] = $clientSubmissionId ?? (string) Str::uuid();
        $payload['vehicle_identification'] = [
            'questions_version' => $this->block($this->unit)['questions_version'],
            'answers' => $answers,
        ];

        return $payload;
    }

    /** @param list<array<string, mixed>> $answers */
    protected function submitCheckout(array $answers, ?string $clientSubmissionId = null): TestResponse
    {
        return $this->postJson($this->submissionUrl(), $this->checkout($answers, $clientSubmissionId));
    }

    /** @return array{field: string, value: string} */
    protected function answer(string $field, string $value): array
    {
        return ['field' => $field, 'value' => $value];
    }

    /** @return array{field: string, unavailable_reason: string, note: ?string} */
    protected function unavailable(string $field, string $reason, ?string $note = null): array
    {
        return ['field' => $field, 'unavailable_reason' => $reason, 'note' => $note];
    }
}
