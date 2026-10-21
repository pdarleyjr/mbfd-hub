<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Services\Fleet\TakeHomeInspectionPhotoStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes photos that were uploaded for a Take Home Vehicle Inspection that was
 * never submitted (abandoned drafts). Attached photos are never touched.
 */
final class PruneTakeHomeInspectionUploads extends Command
{
    protected $signature = 'fleet:prune-take-home-uploads {--hours=96 : Minimum age of unattached uploads to remove}';

    protected $description = 'Delete unsubmitted Take Home Vehicle Inspection photo uploads older than the given age.';

    public function handle(): int
    {
        $hours = max(24, (int) $this->option('hours'));
        $disk = Storage::disk(TakeHomeInspectionPhotoStore::disk());
        $removed = 0;

        TakeHomeVehicleInspectionPhoto::query()
            ->whereNull('take_home_vehicle_inspection_id')
            ->where('created_at', '<', now()->subHours($hours))
            ->orderBy('id')
            ->chunkById(200, function ($photos) use ($disk, &$removed): void {
                foreach ($photos as $photo) {
                    $disk->delete($photo->path);
                    $photo->delete();
                    $removed++;
                }
            });

        $this->info("Removed {$removed} unsubmitted take-home inspection upload(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}
