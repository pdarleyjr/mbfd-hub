<?php

declare(strict_types=1);

namespace App\Jobs\Photos;

use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Services\Fleet\TakeHomeInspectionPhotoStore;
use App\Services\Photos\PhotoNormalizer;
use App\Services\Photos\PhotoUnreadable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Converts one Take Home inspection original (HEIC, PNG, WebP, large or EXIF JPEG)
 * into the stored JPEG, then deletes the original. Safe to run twice.
 */
final class ProcessTakeHomeInspectionPhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $uuid)
    {
        $this->onQueue('default');
    }

    public function handle(PhotoNormalizer $photos): void
    {
        $photo = TakeHomeVehicleInspectionPhoto::query()->where('uuid', $this->uuid)->first();
        if ($photo === null || ! $photo->isPendingConversion()) {
            return;
        }
        $disk = Storage::disk(TakeHomeInspectionPhotoStore::disk());
        $source = (string) $photo->source_path;
        $bytes = $disk->exists($source) ? $disk->get($source) : null;
        if (! is_string($bytes) || $bytes === '') {
            if ($disk->exists($photo->path)) {
                $photo->forceFill(['source_path' => null])->save();
            }

            return;
        }

        try {
            $jpeg = $photos->toJpeg($bytes);
        } catch (PhotoUnreadable $exception) {
            // Nothing more can be done with these bytes; the record shows the photo as unavailable.
            Log::warning('A take-home inspection photo could not be converted.', ['uuid' => $this->uuid, 'error' => $exception->getMessage()]);
            $photo->forceFill(['source_path' => null])->save();
            $disk->delete($source);

            return;
        }

        if (! $disk->put($photo->path, $jpeg['bytes'], ['visibility' => 'private'])) {
            throw new \RuntimeException('The converted take-home inspection photo could not be written.');
        }
        $photo->forceFill([
            'source_path' => null,
            'width' => $jpeg['width'],
            'height' => $jpeg['height'],
            'bytes' => strlen($jpeg['bytes']),
            'sha256' => hash('sha256', $jpeg['bytes']),
        ])->save();
        $disk->delete($source);
    }
}
