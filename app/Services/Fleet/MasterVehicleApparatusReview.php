<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fleet's answer for a Master Vehicle Inventory record that has no apparatus row: "this is a
 * support or staff vehicle, not an apparatus". The record then leaves the Apparatus review
 * worklist (and the master-only list of fleet:link-master-vehicles). Reversible, and every
 * decision is a row in the vehicle's change history.
 */
final class MasterVehicleApparatusReview
{
    public const MAX_NOTE = 500;

    public const EVENT_MARKED = 'not an apparatus';

    public const EVENT_RESTORED = 'back in apparatus review';

    public function __construct(private readonly UnitMasterVehicleChangeRecorder $recorder) {}

    /**
     * @throws AuthorizationException when the person may not update the vehicle record
     * @throws ValidationException when the record is already an apparatus
     */
    public function markNotApparatus(UnitMasterVehicle $vehicle, User $actor, ?string $note = null): void
    {
        $this->authorize($vehicle, $actor);
        $note = $this->cleanNote($note);

        DB::transaction(function () use ($vehicle, $actor, $note): void {
            $locked = UnitMasterVehicle::query()->lockForUpdate()->findOrFail($vehicle->getKey());
            if ($locked->isMarkedNotApparatus()) {
                return; // Already decided: a second click changes nothing.
            }
            $apparatus = Apparatus::query()->where('unit_master_vehicle_id', $locked->getKey())->first();
            if ($apparatus instanceof Apparatus) {
                throw ValidationException::withMessages([
                    'record' => 'This vehicle is linked to apparatus '.($apparatus->designation ?: $apparatus->name ?: '#'.$apparatus->getKey()).', so it cannot be marked as not an apparatus.',
                ]);
            }

            $locked->forceFill([
                'not_apparatus_at' => now(),
                'not_apparatus_by_user_id' => $actor->getKey(),
                'not_apparatus_note' => $note,
            ])->save();
            $this->recorder->recordEvent($locked, self::EVENT_MARKED, UnitMasterVehicleChange::SOURCE_MASTER_INVENTORY, [
                'user' => $actor,
                'note' => $note ?? 'Marked as a support or staff vehicle, not an apparatus.',
            ]);
        });
    }

    /** @throws AuthorizationException when the person may not update the vehicle record */
    public function undo(UnitMasterVehicle $vehicle, User $actor): void
    {
        $this->authorize($vehicle, $actor);

        DB::transaction(function () use ($vehicle, $actor): void {
            $locked = UnitMasterVehicle::query()->lockForUpdate()->findOrFail($vehicle->getKey());
            if (! $locked->isMarkedNotApparatus()) {
                return;
            }

            $locked->forceFill([
                'not_apparatus_at' => null,
                'not_apparatus_by_user_id' => null,
                'not_apparatus_note' => null,
            ])->save();
            $this->recorder->recordEvent($locked, self::EVENT_RESTORED, UnitMasterVehicleChange::SOURCE_MASTER_INVENTORY, [
                'user' => $actor,
                'note' => 'Undo "not an apparatus": the record is back in the Apparatus review list.',
            ]);
        });
    }

    private function authorize(UnitMasterVehicle $vehicle, User $actor): void
    {
        if (! $actor->can('update', $vehicle)) {
            throw new AuthorizationException('Updating the vehicle record is required to decide whether it is an apparatus.');
        }
    }

    private function cleanNote(?string $note): ?string
    {
        $clean = Str::squish((string) $note);

        return $clean === '' ? null : mb_substr($clean, 0, self::MAX_NOTE);
    }
}
