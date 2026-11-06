<?php

declare(strict_types=1);

namespace App\Jobs\Photos;

use App\Models\InspectionPhotoUpload;
use App\Services\Inspections\InspectionPhotoUploads;
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
 * Converts one Daily Checkout original (HEIC, PNG, WebP, GIF, or a JPEG that is large
 * or still carries camera metadata) into the stored JPEG at its fixed path, then
 * deletes the original. Safe to run twice.
 */
final class ProcessInspectionPhotoUpload implements ShouldQueue
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
        $upload = InspectionPhotoUpload::query()->where('uuid', $this->uuid)->first();
        if ($upload === null || $upload->status !== InspectionPhotoUpload::STATUS_PROCESSING || ! is_string($upload->source_path)) {
            return;
        }
        $private = Storage::disk(InspectionPhotoUploads::privateDisk());
        $source = $upload->source_path;
        $bytes = $private->exists($source) ? $private->get($source) : null;
        if (! is_string($bytes) || $bytes === '') {
            $upload->forceFill(['status' => InspectionPhotoUpload::STATUS_FAILED, 'source_path' => null, 'failure' => 'The original was not found.'])->save();

            return;
        }

        try {
            $jpeg = $photos->toJpeg($bytes);
        } catch (PhotoUnreadable $exception) {
            Log::warning('A Daily Checkout photo could not be converted.', ['uuid' => $this->uuid, 'error' => $exception->getMessage()]);
            $upload->forceFill(['status' => InspectionPhotoUpload::STATUS_FAILED, 'source_path' => null, 'failure' => mb_substr($exception->getMessage(), 0, 255)])->save();
            $private->delete($source);

            return;
        }

        // Always the private member image disk, also for a row recorded before photos went private.
        $disk = InspectionPhotoUploads::disk();
        if (! Storage::disk($disk)->put($upload->path, $jpeg['bytes'], ['visibility' => 'private'])) {
            throw new \RuntimeException('The converted Daily Checkout photo could not be written.');
        }
        $upload->forceFill([
            'status' => InspectionPhotoUpload::STATUS_STORED,
            'disk' => $disk,
            'source_path' => null,
            'bytes' => strlen($jpeg['bytes']),
            'width' => $jpeg['width'],
            'height' => $jpeg['height'],
            'sha256' => hash('sha256', $jpeg['bytes']),
            'stored_at' => now(),
        ])->save();
        $private->delete($source);
    }

    /** Out of attempts: stop re-queueing. The original stays on the private disk for a manual retry until photos:maintain deletes it (7 days). */
    public function failed(?\Throwable $exception): void
    {
        InspectionPhotoUpload::query()
            ->where('uuid', $this->uuid)
            ->where('status', InspectionPhotoUpload::STATUS_PROCESSING)
            ->update(['status' => InspectionPhotoUpload::STATUS_FAILED, 'failure' => mb_substr('Conversion failed: '.($exception?->getMessage() ?? 'unknown'), 0, 255), 'updated_at' => now()]);
    }
}
