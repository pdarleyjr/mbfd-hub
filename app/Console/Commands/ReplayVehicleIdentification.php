<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\User;
use App\Services\Fleet\VehicleIdentificationService;
use App\Support\Database\TransientDatabaseFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records the vehicle identification answers of checkouts whose answers were saved with the
 * checkout but not recorded (the recording step failed and was rolled back on its own).
 *
 * Safe to run any number of times: a checkout that already has identification events is skipped,
 * and recording is idempotent on the checkout's client submission id.
 */
final class ReplayVehicleIdentification extends Command
{
    protected $signature = 'fleet:replay-vehicle-identification
                            {--dry-run : List the checkouts that would be replayed and write nothing}
                            {--days=30 : Only look at checkouts completed in the last this many days}';

    protected $description = 'Record vehicle identification answers that were saved with a checkout but never recorded';

    public function handle(VehicleIdentificationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $recorded = 0;
        $failed = 0;
        $pending = 0;

        ApparatusInspection::query()
            ->whereNotNull('vehicle_identification')
            ->whereNotNull('client_submission_id')
            ->where('completed_at', '>=', $since)
            ->orderBy('id')
            ->each(function (ApparatusInspection $inspection) use ($service, $dryRun, &$recorded, &$failed, &$pending): void {
                $csid = (string) $inspection->client_submission_id;
                $section = $inspection->vehicle_identification;
                if (! is_array($section) || ! is_array($section['answers'] ?? null)
                    || ApparatusIdentificationEvent::query()->where('client_submission_id', $csid)->exists()) {
                    return;
                }
                $pending++;
                $this->line("Inspection {$inspection->id} ({$inspection->inspection_reference})".($dryRun ? ' would be replayed.' : ''));
                if ($dryRun) {
                    return;
                }

                try {
                    DB::transaction(function () use ($service, $inspection, $section, $csid): void {
                        $apparatus = Apparatus::query()->lockForUpdate()->findOrFail($inspection->apparatus_id);
                        $actor = $inspection->actor_user_id === null ? null : User::query()->find($inspection->actor_user_id);
                        // A different physical vehicle was reported at this checkout: the answers describe that vehicle.
                        // A unit that had no vehicle number then has nothing to differ from.
                        $held = ApparatusVehicleSwapRequest::query()
                            ->where('apparatus_inspection_id', $inspection->getKey())
                            ->whereNotNull('assigned_vehicle_number')
                            ->exists();
                        $service->record($apparatus, $section, $actor, $csid, (int) $inspection->getKey(), $held);
                    });
                    $recorded++;
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error("Inspection {$inspection->id}: ".($exception->getMessage()));
                    if (TransientDatabaseFailure::isTransient($exception)) {
                        $this->warn('That looks temporary; run the command again.');
                    }
                }
            });

        $this->info($dryRun ? "{$pending} checkout(s) would be replayed." : "{$recorded} replayed, {$failed} failed, {$pending} found.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
