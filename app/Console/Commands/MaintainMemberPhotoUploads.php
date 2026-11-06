<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Photos\ProcessInspectionPhotoUpload;
use App\Jobs\Photos\ProcessTakeHomeInspectionPhoto;
use App\Models\InspectionPhotoUpload;
use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Services\Fleet\TakeHomeInspectionPhotoStore;
use App\Services\Inspections\InspectionPhotoUploads;
use App\Support\Media\MemberMedia;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps member photo storage lean:
 *  - Daily Checkout photos no checkout ever named are deleted after 30 days (file and row);
 *  - a conversion that never finished (worker restart, backed-up queue) is queued again,
 *    up to MAX_CONVERSION_ATTEMPTS times; after that it is given up on and logged once;
 *  - the held original of a conversion that failed or was given up on is deleted after
 *    FAILED_ORIGINAL_RETENTION_DAYS, an original still recorded on a converted row is
 *    deleted at once, and an original no row holds at all is deleted after a day;
 *  - Livewire temporary uploads older than a day are removed (Livewire only sweeps them
 *    when somebody uploads again).
 * Originals are only ever deleted from the originals (incoming) directories, never a
 * converted photo's `path`, and a photo a checkout or inspection uses is never touched.
 */
final class MaintainMemberPhotoUploads extends Command
{
    protected $signature = 'photos:maintain {--dry-run : Report only}';

    protected $description = 'Remove abandoned member photo uploads, re-queue stalled conversions, delete spent originals and sweep old Livewire temporary files.';

    public const MAX_CONVERSION_ATTEMPTS = 5;

    public const FAILED_ORIGINAL_RETENTION_DAYS = 7;

    private const STALLED_MINUTES = 15;

    private const ORPHANED_ORIGINAL_GRACE_HOURS = 24;

    private bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $removed = $this->removeAbandonedDailyUploads();
        [$requeued, $gaveUp] = $this->requeueStalledDailyConversions();
        [$takeHomeRequeued, $takeHomeGaveUp] = $this->requeueStalledTakeHomeConversions();
        $originals = $this->deleteSpentOriginals() + $this->sweepOrphanedOriginals();
        $swept = $this->sweepLivewireTemporaryUploads();

        $this->info(sprintf(
            '%s%d abandoned Daily Checkout photo upload(s) removed, %d stalled conversion(s) re-queued, %d given up, %d spent original(s) deleted, %d Livewire temporary file(s) removed.',
            $this->dryRun ? '[dry run] ' : '',
            $removed,
            $requeued + $takeHomeRequeued,
            $gaveUp + $takeHomeGaveUp,
            $originals,
            $swept,
        ));

        return self::SUCCESS;
    }

    private function removeAbandonedDailyUploads(): int
    {
        $removed = 0;
        $private = $this->privateDisk();
        InspectionPhotoUpload::query()
            ->whereNull('apparatus_inspection_id')
            ->where('created_at', '<', now()->subDays(InspectionPhotoUploads::UNATTACHED_RETENTION_DAYS))
            ->orderBy('id')
            ->chunkById(200, function ($uploads) use ($private, &$removed): void {
                foreach ($uploads as $upload) {
                    $removed++;
                    if ($this->dryRun) {
                        continue;
                    }
                    // Another row may point at the same file only through resolve()'s duplicate mapping, which
                    // always keeps the attached row's own path; an unattached row's file is its own.
                    MemberMedia::delete($upload->path); // private disk, and a public copy not moved yet
                    $this->deleteOriginal($private, $upload->source_path, $upload->path);
                    $upload->delete();
                }
            });

        return $removed;
    }

    /** @return array{0: int, 1: int} re-queued, given up */
    private function requeueStalledDailyConversions(): array
    {
        $requeued = 0;
        $gaveUp = 0;
        $stalled = InspectionPhotoUpload::query()
            ->where('status', InspectionPhotoUpload::STATUS_PROCESSING)
            ->where('updated_at', '<', now()->subMinutes(self::STALLED_MINUTES))
            ->get(['id', 'uuid', 'conversion_attempts']);
        foreach ($stalled as $upload) {
            $attempts = (int) $upload->conversion_attempts;
            $exhausted = $attempts >= self::MAX_CONVERSION_ATTEMPTS;
            $exhausted ? $gaveUp++ : $requeued++;
            if ($this->dryRun) {
                continue;
            }
            if ($exhausted) {
                Log::warning('A Daily Checkout photo conversion was given up on after repeated attempts.', ['uuid' => $upload->uuid, 'attempts' => $attempts]);
                InspectionPhotoUpload::query()->whereKey($upload->id)->where('status', InspectionPhotoUpload::STATUS_PROCESSING)->update([
                    'status' => InspectionPhotoUpload::STATUS_FAILED,
                    'failure' => 'The conversion did not finish after '.$attempts.' attempts.',
                    'updated_at' => now(),
                ]);

                continue;
            }
            InspectionPhotoUpload::query()->whereKey($upload->id)->update(['conversion_attempts' => $attempts + 1, 'updated_at' => now()]);
            ProcessInspectionPhotoUpload::dispatch($upload->uuid);
        }

        return [$requeued, $gaveUp];
    }

    /**
     * Take Home photos have no status column: a pending conversion is a row with a source_path.
     * A row given up on is marked with one attempt over the limit, so it is logged only once.
     *
     * @return array{0: int, 1: int} re-queued, given up
     */
    private function requeueStalledTakeHomeConversions(): array
    {
        $requeued = 0;
        $gaveUp = 0;
        $stalled = TakeHomeVehicleInspectionPhoto::query()
            ->whereNotNull('source_path')
            ->where('conversion_attempts', '<=', self::MAX_CONVERSION_ATTEMPTS)
            ->where('updated_at', '<', now()->subMinutes(self::STALLED_MINUTES))
            ->get(['id', 'uuid', 'conversion_attempts']);
        foreach ($stalled as $photo) {
            $attempts = (int) $photo->conversion_attempts;
            $exhausted = $attempts >= self::MAX_CONVERSION_ATTEMPTS;
            $exhausted ? $gaveUp++ : $requeued++;
            if ($this->dryRun) {
                continue;
            }
            TakeHomeVehicleInspectionPhoto::query()->whereKey($photo->id)->update(['conversion_attempts' => $attempts + 1, 'updated_at' => now()]);
            if ($exhausted) {
                Log::warning('A take-home inspection photo conversion was given up on after repeated attempts.', ['uuid' => $photo->uuid, 'attempts' => $attempts]);

                continue;
            }
            ProcessTakeHomeInspectionPhoto::dispatch($photo->uuid);
        }

        return [$requeued, $gaveUp];
    }

    /** Originals of conversions that failed or were given up on (after the retention window), and of rows already converted. */
    private function deleteSpentOriginals(): int
    {
        $deleted = 0;
        $private = $this->privateDisk();
        $cutoff = now()->subDays(self::FAILED_ORIGINAL_RETENTION_DAYS);
        InspectionPhotoUpload::query()
            ->whereNotNull('source_path')
            ->where(fn ($query) => $query
                ->where('status', InspectionPhotoUpload::STATUS_STORED)
                ->orWhere(fn ($failed) => $failed->where('status', InspectionPhotoUpload::STATUS_FAILED)->where('updated_at', '<', $cutoff)))
            ->get(['id', 'path', 'source_path'])
            ->each(function (InspectionPhotoUpload $upload) use ($private, &$deleted): void {
                $deleted++;
                if (! $this->dryRun) {
                    $this->deleteOriginal($private, $upload->source_path, $upload->path);
                    InspectionPhotoUpload::query()->whereKey($upload->id)->update(['source_path' => null]);
                }
            });

        $takeHome = Storage::disk(TakeHomeInspectionPhotoStore::disk());
        TakeHomeVehicleInspectionPhoto::query()
            ->whereNotNull('source_path')
            ->where('conversion_attempts', '>', self::MAX_CONVERSION_ATTEMPTS)
            ->where('updated_at', '<', $cutoff)
            ->get(['id', 'path', 'source_path'])
            ->each(function (TakeHomeVehicleInspectionPhoto $photo) use ($takeHome, &$deleted): void {
                $deleted++;
                if (! $this->dryRun) {
                    $this->deleteOriginal($takeHome, $photo->source_path, $photo->path);
                    TakeHomeVehicleInspectionPhoto::query()->whereKey($photo->id)->update(['source_path' => null]);
                }
            });

        return $deleted;
    }

    /** Files in the originals directories that no row holds any more (left behind after a conversion or a lost race). */
    private function sweepOrphanedOriginals(): int
    {
        $swept = 0;
        $directories = [
            [$this->privateDisk(), InspectionPhotoUploads::INCOMING_DIRECTORY, InspectionPhotoUpload::class],
            [Storage::disk(TakeHomeInspectionPhotoStore::disk()), TakeHomeInspectionPhotoStore::INCOMING_DIRECTORY, TakeHomeVehicleInspectionPhoto::class],
        ];
        $cutoff = now()->subHours(self::ORPHANED_ORIGINAL_GRACE_HOURS)->getTimestamp();
        foreach ($directories as [$disk, $directory, $model]) {
            foreach ($disk->files($directory) as $file) {
                if ($disk->lastModified($file) >= $cutoff || $model::query()->where('source_path', $file)->exists()) {
                    continue;
                }
                $swept++;
                if (! $this->dryRun) {
                    $disk->delete($file);
                }
            }
        }

        return $swept;
    }

    /** Deletes a held original. Never the photo itself. */
    private function deleteOriginal(Filesystem $disk, ?string $source, string $path): void
    {
        if (! is_string($source) || $source === '' || $source === $path) {
            return;
        }
        $disk->delete($source);
    }

    private function privateDisk(): Filesystem
    {
        return Storage::disk(InspectionPhotoUploads::privateDisk());
    }

    private function sweepLivewireTemporaryUploads(): int
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
            if (! $this->dryRun) {
                $disk->delete($file);
            }
        }

        return $count;
    }
}
