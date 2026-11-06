<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Photos\PhotoNormalizer;
use App\Support\Media\MemberMedia;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Moves member photos and signatures (MemberMedia::DIRECTORIES) from the public disk to
 * the private disk at the same relative path, so no database row changes and every
 * viewer (which reads private first, then public) keeps working throughout.
 *
 * Safe to stop and run again at any point:
 *  - a file is written to "<path>.part", read back and compared byte for byte, checked to
 *    decode as an image, and only then renamed into place; the public original is deleted
 *    only after that, never before;
 *  - a byte-identical private copy (an interrupted earlier run) is kept and its public copy
 *    removed; a different private file at the same path leaves both in place and is reported;
 *  - with --strip-metadata, a JPEG on the private disk that still carries camera metadata
 *    (EXIF/GPS/XMP, capture time, a rotation flag) is re-encoded the way member uploads are
 *    (oriented, long edge at most PhotoNormalizer::MAX_EDGE, never enlarged); if that fails
 *    the original is kept and the failure logged.
 */
final class MakeMemberPhotosPrivate extends Command
{
    protected $signature = 'photos:make-private
        {--dry-run : Report what would change; write and delete nothing}
        {--strip-metadata : Re-encode JPEGs that still carry camera metadata (EXIF/GPS), keeping orientation}
        {--directory=* : Only these member image directories (default: all of them)}';

    protected $description = 'Move member photos and signatures from the public disk to the private disk and optionally strip camera metadata';

    private const SUMMARY_DIRECTORY = 'photo-privacy';

    private const PART_SUFFIX = '.part';

    private const MAX_ERROR_SAMPLES = 20;

    /** @var array<string, mixed> */
    private array $stats = [];

    public function handle(PhotoNormalizer $photos): int
    {
        $directories = $this->directories();
        if ($directories === null) {
            return self::INVALID;
        }
        $dryRun = (bool) $this->option('dry-run');
        $strip = (bool) $this->option('strip-metadata');
        $this->stats = [
            'started_at' => now()->utc()->toIso8601ZuluString(),
            'dry_run' => $dryRun, 'strip_metadata' => $strip, 'directories' => $directories,
            'private_disk' => MemberMedia::disk(),
            'scanned' => 0, 'moved' => 0, 'moved_bytes' => 0, 'already_private' => 0,
            'stripped' => 0, 'stripped_bytes_before' => 0, 'stripped_bytes_after' => 0,
            'skipped' => 0, 'strip_failed' => 0, 'errors' => 0, 'error_samples' => [],
        ];

        $public = Storage::disk(MemberMedia::PUBLIC_DISK);
        $private = MemberMedia::privateStorage();
        foreach ($directories as $directory) {
            foreach ($this->files($public, $directory) as $path) {
                $this->moveOne($public, $private, $path, $dryRun);
            }
        }
        if ($strip) {
            // A dry run moved nothing, so it also inspects the public files it would have moved.
            $disks = $dryRun ? [$private, $public] : [$private];
            foreach ($directories as $directory) {
                foreach ($disks as $disk) {
                    foreach ($this->files($disk, $directory) as $path) {
                        $this->stripOne($photos, $disk, $path, $dryRun);
                    }
                }
            }
        }

        $this->report($private, $dryRun);

        return $this->stats['errors'] > 0 || $this->stats['strip_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string>|null */
    private function directories(): ?array
    {
        $requested = array_values(array_filter((array) $this->option('directory'), 'is_string'));
        if ($requested === []) {
            return MemberMedia::DIRECTORIES;
        }
        $unknown = array_diff($requested, MemberMedia::DIRECTORIES);
        if ($unknown !== []) {
            $this->error('Not a member image directory: '.implode(', ', $unknown).'. Use: '.implode(', ', MemberMedia::DIRECTORIES).'.');

            return null;
        }

        return array_values(array_unique($requested));
    }

    /** @return list<string> sorted, so an interrupted run resumes in the same order */
    private function files(Filesystem $disk, string $directory): array
    {
        $files = array_values(array_filter(
            $disk->allFiles($directory),
            static fn (string $path): bool => ! str_ends_with($path, self::PART_SUFFIX),
        ));
        sort($files);

        return $files;
    }

    private function moveOne(Filesystem $public, Filesystem $private, string $path, bool $dryRun): void
    {
        $this->stats['scanned']++;
        if (MemberMedia::safePath($path) === null) {
            $this->stats['skipped']++; // a name no member upload ever has (dotfile, odd characters)

            return;
        }
        $bytes = $public->get($path);
        if (! is_string($bytes) || $bytes === '') {
            $this->leftInPlace($path, 'The public file is empty or could not be read; it was left in place.');

            return;
        }
        $existing = $private->exists($path) ? (string) $private->get($path) : '';
        if ($existing !== '' && $this->isUsable($path, $existing)) {
            if (! hash_equals(hash('sha256', $existing), hash('sha256', $bytes))) {
                // Not the copy an earlier run made: keep both and let a person decide.
                $this->leftInPlace($path, 'A different private file already exists at this path; both were kept.');

                return;
            }
            $this->stats['already_private']++;
            if (! $dryRun) {
                $public->delete($path); // an earlier run's identical, complete copy
            }

            return;
        }
        if (! $this->isUsable($path, $bytes)) {
            $this->leftInPlace($path, 'The public file does not decode as an image; it was left in place.');

            return;
        }
        if (! $dryRun && ! $this->writeVerified($private, $path, $bytes)) {
            $this->leftInPlace($path, 'The private copy could not be written and verified; the public file was left in place.');

            return;
        }
        if (! $dryRun) {
            $public->delete($path);
        }
        $this->stats['moved']++;
        $this->stats['moved_bytes'] += strlen($bytes);
    }

    private function stripOne(PhotoNormalizer $photos, Filesystem $disk, string $path, bool $dryRun): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg'], true) || MemberMedia::safePath($path) === null) {
            return;
        }
        $bytes = $disk->get($path);
        if (! is_string($bytes) || $bytes === '' || ! $photos->hasCameraMetadata($bytes)) {
            return;
        }
        if ($dryRun) {
            $this->stats['stripped']++;
            $this->stats['stripped_bytes_before'] += strlen($bytes);

            return;
        }

        try {
            $clean = $photos->toJpeg($bytes)['bytes'];
        } catch (Throwable $exception) {
            $this->stripFailed($path, $exception->getMessage());

            return;
        }
        if ($photos->hasCameraMetadata($clean) || ! $this->isUsable($path, $clean) || ! $this->writeVerified($disk, $path, $clean)) {
            $this->stripFailed($path, 'The re-encoded photo could not be verified.');

            return;
        }
        $this->stats['stripped']++;
        $this->stats['stripped_bytes_before'] += strlen($bytes);
        $this->stats['stripped_bytes_after'] += strlen($clean);
    }

    /**
     * Writes "<path>.part", reads it back and compares it, then renames it over the path
     * (an atomic replace on a local disk, so the path always holds a whole file).
     */
    private function writeVerified(Filesystem $disk, string $path, string $bytes): bool
    {
        $part = $path.self::PART_SUFFIX;
        try {
            if (! $disk->put($part, $bytes, ['visibility' => 'private'])) {
                return false;
            }
            $written = $disk->get($part);
            if (! is_string($written) || ! hash_equals(hash('sha256', $bytes), hash('sha256', $written)) || ! $disk->move($part, $path)) {
                $disk->delete($part);

                return false;
            }

            return $disk->size($path) === strlen($bytes);
        } catch (Throwable $exception) {
            Log::warning('photos:make-private could not write a verified copy.', ['path' => $path, 'error' => $exception->getMessage()]);
            $disk->delete($part);

            return false;
        }
    }

    /** Non-empty, and for an image: decodes. */
    private function isUsable(string $path, string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }
        if (! MemberMedia::isImagePath($path)) {
            return true;
        }
        if (@getimagesizefromstring($bytes) === false) {
            return false;
        }
        if (! function_exists('imagecreatefromstring')) {
            return true;
        }
        try {
            $image = @imagecreatefromstring($bytes);
        } catch (Throwable) {
            return false;
        }
        if ($image === false) {
            return false;
        }
        imagedestroy($image);

        return true;
    }

    private function leftInPlace(string $path, string $reason): void
    {
        $this->stats['errors']++;
        $this->sample($path, $reason);
        Log::warning('photos:make-private left a file in place.', ['path' => $path, 'reason' => $reason]);
    }

    private function stripFailed(string $path, string $reason): void
    {
        $this->stats['strip_failed']++;
        $this->sample($path, 'Metadata not removed, original kept: '.$reason);
        Log::warning('photos:make-private kept an original it could not re-encode.', ['path' => $path, 'reason' => $reason]);
    }

    private function sample(string $path, string $reason): void
    {
        if (count($this->stats['error_samples']) < self::MAX_ERROR_SAMPLES) {
            $this->stats['error_samples'][] = ['path' => $path, 'reason' => mb_substr($reason, 0, 255)];
        }
    }

    private function report(Filesystem $private, bool $dryRun): void
    {
        $this->stats['finished_at'] = now()->utc()->toIso8601ZuluString();
        $verb = $dryRun ? 'Would' : 'Did';
        $this->table(['Result', 'Count'], [
            ['Files scanned on the public disk', $this->stats['scanned']],
            ["{$verb} move to the private disk", $this->stats['moved'].' ('.number_format($this->stats['moved_bytes']).' bytes)'],
            ['Already private (public copy '.($dryRun ? 'would be ' : '').'removed)', $this->stats['already_private']],
            ["{$verb} strip camera metadata", $this->stats['stripped'].' ('.number_format($this->stats['stripped_bytes_before']).' -> '.number_format($this->stats['stripped_bytes_after']).' bytes)'],
            ['Skipped (not a member upload name)', $this->stats['skipped']],
            ['Metadata strip failed (original kept)', $this->stats['strip_failed']],
            ['Errors (public file left in place)', $this->stats['errors']],
        ]);
        foreach ($this->stats['error_samples'] as $sample) {
            $this->warn($sample['path'].': '.$sample['reason']);
        }
        Log::info('photos:make-private finished.', array_diff_key($this->stats, ['error_samples' => true]));
        if ($dryRun) {
            $this->info('Dry run: nothing was written or deleted.');

            return;
        }
        $file = self::SUMMARY_DIRECTORY.'/make-private-'.now()->utc()->format('Ymd\THis\Z').'.json';
        $private->put($file, (string) json_encode($this->stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ['visibility' => 'private']);
        $this->info("Summary saved on the private disk at {$file}.");
    }
}
