<?php

declare(strict_types=1);

namespace App\Services\Narcotics;

use App\Models\Narcotics\NarcoticsCheck;
use App\Services\Ems\EmsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Private file storage for narcotics check photos. Files live on the private disk under a random path
 * (never derived from a person or a name) and are only ever returned through the authorized image
 * route; there is no public disk, symlink or URL for them.
 */
final class NarcoticsImageStore
{
    public const VARIANTS = ['thumb', 'preview', 'full'];

    public static function disk(): string
    {
        return (string) config('filesystems.private', 'local');
    }

    /**
     * Writes the three sizes. All or nothing: a failed write removes what was already written.
     *
     * @return array{disk: string, image_path: string, preview_path: string, thumb_path: string}
     */
    public function put(ProcessedImage $image): array
    {
        $disk = self::disk();
        $base = 'narcotics-checks/'.now()->format('Y/m').'/'.(string) Str::uuid();
        $paths = [
            'image_path' => "{$base}/full.{$image->extension}",
            'preview_path' => "{$base}/preview.{$image->extension}",
            'thumb_path' => "{$base}/thumb.{$image->extension}",
        ];
        $contents = ['image_path' => $image->full, 'preview_path' => $image->preview, 'thumb_path' => $image->thumb];

        $written = [];
        try {
            foreach ($paths as $column => $path) {
                if (! Storage::disk($disk)->put($path, $contents[$column], ['visibility' => 'private'])) {
                    throw new \RuntimeException('The private disk refused a narcotics check photo write.');
                }
                $written[] = $path;
            }
        } catch (Throwable $exception) {
            $this->deletePaths($disk, $written);
            Log::error('Narcotics check photo could not be stored', ['exception' => $exception->getMessage()]);

            throw new EmsException('The photo could not be saved, so nothing was sent. Please try again.', 'photo_store_failed', 500);
        }

        return ['disk' => $disk] + $paths;
    }

    /** @param array{disk: string, image_path: string, preview_path: string, thumb_path: string} $stored */
    public function discard(array $stored): void
    {
        $this->deletePaths($stored['disk'], [$stored['image_path'], $stored['preview_path'], $stored['thumb_path']]);
    }

    /** Removes the files of a check (retention). Returns how many files were removed. */
    public function purge(NarcoticsCheck $check): int
    {
        $paths = [$check->image_path, $check->preview_path, $check->thumb_path];
        $this->deletePaths($check->image_disk, $paths);

        return count($paths);
    }

    /** @return array{bytes: string, mime: string, extension: string}|null null when the file is gone */
    public function read(NarcoticsCheck $check, string $variant): ?array
    {
        if ($check->images_purged_at !== null) {
            return null;
        }
        $path = match ($variant) {
            'thumb' => $check->thumb_path,
            'preview' => $check->preview_path,
            'full' => $check->image_path,
            default => null,
        };
        if ($path === null) {
            return null;
        }
        try {
            $disk = Storage::disk($check->image_disk);
            if (! $disk->exists($path)) {
                return null;
            }
            $bytes = $disk->get($path);
        } catch (Throwable $exception) {
            Log::error('Narcotics check photo could not be read', ['check' => $check->uuid, 'variant' => $variant, 'exception' => $exception->getMessage()]);

            return null;
        }
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => $check->image_mime, 'extension' => $check->image_mime === 'image/webp' ? 'webp' : 'jpg'];
    }

    /** @param list<string> $paths */
    private function deletePaths(string $disk, array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable $exception) {
                Log::warning('Narcotics check photo file could not be deleted', ['path' => $path, 'exception' => $exception->getMessage()]);
            }
        }
    }
}
