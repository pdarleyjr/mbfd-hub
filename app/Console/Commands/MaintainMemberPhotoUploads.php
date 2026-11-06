<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Photos\ProcessInspectionPhotoUpload;
use App\Jobs\Photos\ProcessTakeHomeInspectionPhoto;
use App\Models\InspectionPhotoUpload;
use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Services\Inspections\InspectionPhotoUploads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps member photo storage lean:
 *  - Daily Checkout photos no checkout ever named are deleted after 30 days (file and row);
 *  - a conversion that never finished (worker restart) is queued again;
 *  - Livewire temporary uploads older than a day are removed (Livewire only sweeps them
 *    when somebody uploads again).
 * Never touches a photo a checkout or inspection uses.
 */
final class MaintainMemberPhotoUploads extends Command
{
    protected $signature = 'photos:maintain {--dry-run : Report only}';

    protected $description = 'Remove abandoned member photo uploads, re-queue stalled conversions and sweep old Livewire temporary files.';

    private const STALLED_MINUTES = 15;

    /** A stalled conversion is re-queued for this long only, never forever (the original stays for a manual retry). */
    private const REQUEUE_WINDOW_HOURS = 24;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $removed = 0;
        $requeued = 0;
        $public = Storage::disk(InspectionPhotoUploads::DISK);
        $private = Storage::disk(InspectionPhotoUploads::privateDisk());

        InspectionPhotoUpload::query()
            ->whereNull('apparatus_inspection_id')
            ->where('created_at', '<', now()->subDays(InspectionPhotoUploads::UNATTACHED_RETENTION_DAYS))
            ->orderBy('id')
            ->chunkById(200, function ($uploads) use ($public, $private, $dryRun, &$removed): void {
                foreach ($uploads as $upload) {
                    $removed++;
                    if ($dryRun) {
                        continue;
                    }
                    // Another row may point at the same file only through resolve()'s duplicate mapping, which
                    // always keeps the attached row's own path; an unattached row's file is its own.
                    $public->delete($upload->path);
                    if (is_string($upload->source_path)) {
                        $private->delete($upload->source_path);
                    }
                    $upload->delete();
                }
            });

        $stalled = now()->subMinutes(self::STALLED_MINUTES);
        $window = now()->subHours(self::REQUEUE_WINDOW_HOURS);
        InspectionPhotoUpload::query()
            ->where('status', InspectionPhotoUpload::STATUS_PROCESSING)
            ->where('updated_at', '<', $stalled)
            ->where('updated_at', '>', $window)
            ->pluck('uuid')
            ->each(function (string $uuid) use ($dryRun, &$requeued): void {
                $requeued++;
                if (! $dryRun) {
                    ProcessInspectionPhotoUpload::dispatch($uuid);
                }
            });
        TakeHomeVehicleInspectionPhoto::query()
            ->whereNotNull('source_path')
            ->where('updated_at', '<', $stalled)
            ->where('updated_at', '>', $window)
            ->pluck('uuid')
            ->each(function (string $uuid) use ($dryRun, &$requeued): void {
                $requeued++;
                if (! $dryRun) {
                    ProcessTakeHomeInspectionPhoto::dispatch($uuid);
                }
            });

        $swept = $this->sweepLivewireTemporaryUploads($dryRun);

        $this->info(sprintf(
            '%s%d abandoned Daily Checkout photo upload(s) removed, %d stalled conversion(s) re-queued, %d Livewire temporary file(s) removed.',
            $dryRun ? '[dry run] ' : '',
            $removed,
            $requeued,
            $swept,
        ));

        return self::SUCCESS;
    }

    private function sweepLivewireTemporaryUploads(bool $dryRun): int
    {
        $diskName = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');
        $directory = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';
        $disk = Storage::disk(is_string($diskName) ? $diskName : 'local');
        $cutoff = now()->subDay()->getTimestamp();
        $count = 0;
        foreach ($disk->files((string) $directory) as $file) {
            if ($disk->lastModified($file) >= $cutoff) {
                continue;
            }
            $count++;
            if (! $dryRun) {
                $disk->delete($file);
            }
        }

        return $count;
    }
}
