<?php

declare(strict_types=1);

namespace App\Services\ResourceLibrary;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Private-disk layout for the resource library: one original and at most one PDF preview per document,
 * under resource-library/{uuid}/. Nothing here is reachable by a public or static URL.
 */
final class ResourceLibraryStorage
{
    public const ROOT = 'resource-library';

    public const INCOMING_SUFFIX = '.incoming';

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.private', 'local'));
    }

    public function directory(string $uuid): string
    {
        return self::ROOT.'/'.$uuid;
    }

    public function originalPath(string $uuid, string $extension): string
    {
        return $this->directory($uuid).'/original.'.strtolower($extension);
    }

    public function previewPath(string $uuid): string
    {
        return $this->directory($uuid).'/preview.pdf';
    }

    /** Where the original of a replacement lives. Never an earlier version's path, so nothing is overwritten. */
    public function versionedOriginalPath(string $uuid, int $version, string $extension): string
    {
        return $this->directory($uuid).'/v'.$version.'/original.'.strtolower($extension);
    }

    public function versionedPreviewPath(string $uuid, int $version): string
    {
        return $this->directory($uuid).'/v'.$version.'/preview.pdf';
    }

    /** Copy a local file onto the private disk as a stream (documents can be tens of megabytes). */
    public function putFromLocal(string $diskPath, string $localPath): void
    {
        $source = fopen($localPath, 'rb');
        if ($source === false) {
            throw new RuntimeException('The source file could not be opened for reading.');
        }
        try {
            if (! $this->disk()->writeStream($diskPath, $source)) {
                throw new RuntimeException('The private disk refused the write for '.$diskPath.'.');
            }
        } finally {
            fclose($source);
        }
    }

    /** SHA-256 of the bytes as stored, read back from the private disk. */
    public function sha256(string $diskPath): string
    {
        $stream = $this->disk()->readStream($diskPath);
        if (! is_resource($stream)) {
            throw new RuntimeException('The stored file could not be read back: '.$diskPath);
        }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /** Copy staged bytes over the final path, prove the result, then drop the staged copy. */
    public function promote(string $stagedPath, string $finalPath, string $expectedSha256): void
    {
        $disk = $this->disk();
        if (! $disk->copy($stagedPath, $finalPath)) {
            throw new RuntimeException('Could not move '.$stagedPath.' into place. The staged copy is kept for recovery.');
        }
        if ($this->sha256($finalPath) !== $expectedSha256) {
            throw new RuntimeException('Checksum mismatch after writing '.$finalPath.'. The staged copy is kept for recovery.');
        }
        $disk->delete($stagedPath);
    }

    public function delete(string $diskPath): void
    {
        $this->disk()->delete($diskPath);
    }

    public function deleteDirectory(string $uuid): void
    {
        $this->disk()->deleteDirectory($this->directory($uuid));
    }
}
