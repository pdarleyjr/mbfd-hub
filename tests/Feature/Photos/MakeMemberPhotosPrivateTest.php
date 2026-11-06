<?php

declare(strict_types=1);

namespace Tests\Feature\Photos;

use App\Services\Photos\PhotoNormalizer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * photos:make-private moves member photos and signatures off the public disk at the same
 * relative path, strips camera metadata on request, and never loses a file.
 */
final class MakeMemberPhotosPrivateTest extends TestCase
{
    private Filesystem $public;

    private Filesystem $private;

    protected function setUp(): void
    {
        parent::setUp();
        $this->public = Storage::fake('public');
        $this->private = Storage::fake('local');
    }

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        $this->public->put('defects/a.jpg', $this->jpeg(orientation: 6));
        $this->public->put('signatures/s.png', $this->png());

        $this->artisan('photos:make-private', ['--dry-run' => true, '--strip-metadata' => true])
            ->expectsOutputToContain('Dry run: nothing was written or deleted.')
            ->assertSuccessful();

        $this->public->assertExists(['defects/a.jpg', 'signatures/s.png']);
        $this->assertSame([], $this->private->allFiles());
    }

    public function test_files_move_byte_for_byte_at_the_same_path_and_a_summary_is_saved(): void
    {
        $photo = $this->jpeg();
        $signature = $this->png();
        $this->public->put('defects/uploads/ab/abcdef.jpg', $photo);
        $this->public->put('signatures/s.png', $signature);
        $this->public->put('station-requests/signatures/m.png', $signature);
        $this->public->put('training-todo-attachments/staff.jpg', $photo); // not a member image directory

        $this->artisan('photos:make-private')->assertSuccessful();

        $this->assertSame($photo, $this->private->get('defects/uploads/ab/abcdef.jpg'));
        $this->assertSame($signature, $this->private->get('signatures/s.png'));
        $this->assertSame($signature, $this->private->get('station-requests/signatures/m.png'));
        $this->public->assertMissing(['defects/uploads/ab/abcdef.jpg', 'signatures/s.png', 'station-requests/signatures/m.png']);
        $this->public->assertExists('training-todo-attachments/staff.jpg');
        $this->private->assertMissing('training-todo-attachments/staff.jpg');

        $summaries = $this->private->files('photo-privacy');
        $this->assertCount(1, $summaries);
        $summary = json_decode((string) $this->private->get($summaries[0]), true);
        $this->assertSame(3, $summary['moved']);
        $this->assertSame(strlen($photo) + 2 * strlen($signature), $summary['moved_bytes']);
        $this->assertSame(0, $summary['errors']);
        $this->assertSame([], array_filter($this->private->allFiles(), static fn (string $f): bool => str_ends_with($f, '.part')));
    }

    public function test_strip_metadata_reencodes_only_photos_that_carry_it_and_keeps_orientation(): void
    {
        $tagged = $this->jpeg(width: 80, height: 40, orientation: 6);
        $clean = $this->jpeg(width: 80, height: 40);
        $this->public->put('defects/tagged.jpg', $tagged);
        $this->public->put('defects/clean.jpg', $clean);
        $this->public->put('signatures/s.png', $this->png());

        $this->artisan('photos:make-private', ['--strip-metadata' => true])->assertSuccessful();

        $stripped = (string) $this->private->get('defects/tagged.jpg');
        $normalizer = app(PhotoNormalizer::class);
        $this->assertFalse($normalizer->hasCameraMetadata($stripped));
        $this->assertStringNotContainsString('GPSLatitudeRef', $stripped);
        // Orientation 6 (rotate 90 degrees) is applied to the pixels (PHP's exif extension reads it; production has it), never enlarged.
        $this->assertSame(function_exists('exif_read_data') ? [40, 80] : [80, 40], $normalizer->dimensions($stripped));
        $this->assertSame($clean, $this->private->get('defects/clean.jpg'), 'A photo without metadata is moved untouched');
        $this->public->assertMissing(['defects/tagged.jpg', 'defects/clean.jpg', 'signatures/s.png']);
    }

    public function test_a_second_run_finds_nothing_left_to_do(): void
    {
        $this->public->put('defects/tagged.jpg', $this->jpeg(orientation: 6));
        $this->public->put('signatures/s.png', $this->png());
        $this->artisan('photos:make-private', ['--strip-metadata' => true])->assertSuccessful();
        $after = $this->private->get('defects/tagged.jpg');

        $this->travel(2)->seconds();
        $this->artisan('photos:make-private', ['--strip-metadata' => true])->assertSuccessful();

        $this->assertSame($after, $this->private->get('defects/tagged.jpg'));
        $summaries = $this->private->files('photo-privacy');
        sort($summaries);
        $this->assertCount(2, $summaries);
        $second = json_decode((string) $this->private->get(end($summaries)), true);
        $this->assertSame(0, $second['moved'] + $second['stripped'] + $second['errors'] + $second['scanned']);
    }

    public function test_an_interrupted_run_resumes_and_a_complete_private_copy_wins(): void
    {
        $photo = $this->jpeg();
        // A previous run copied this file and stopped before deleting the public copy.
        $this->public->put('defects/copied.jpg', $photo);
        $this->private->put('defects/copied.jpg', $photo);
        // ... and stopped while writing this one.
        $this->public->put('defects/half.jpg', $photo);
        $this->private->put('defects/half.jpg.part', substr($photo, 0, 10));

        $this->artisan('photos:make-private')->assertSuccessful();

        $this->public->assertMissing(['defects/copied.jpg', 'defects/half.jpg']);
        $this->assertSame($photo, $this->private->get('defects/copied.jpg'));
        $this->assertSame($photo, $this->private->get('defects/half.jpg'));
    }

    public function test_a_private_copy_that_does_not_decode_is_replaced_from_the_public_original(): void
    {
        $photo = $this->jpeg();
        $this->public->put('defects/a.jpg', $photo);
        $this->private->put('defects/a.jpg', 'truncated');

        $this->artisan('photos:make-private')->assertSuccessful();

        $this->assertSame($photo, $this->private->get('defects/a.jpg'));
        $this->public->assertMissing('defects/a.jpg');
    }

    public function test_a_different_private_file_at_the_same_path_keeps_both_and_is_reported(): void
    {
        $public = $this->jpeg(width: 40);
        $other = $this->jpeg(width: 60);
        $this->public->put('defects/a.jpg', $public);
        $this->private->put('defects/a.jpg', $other);

        $this->artisan('photos:make-private')
            ->expectsOutputToContain('A different private file already exists')
            ->assertFailed();

        $this->assertSame($public, $this->public->get('defects/a.jpg'));
        $this->assertSame($other, $this->private->get('defects/a.jpg'));
    }

    public function test_a_public_file_that_does_not_decode_stays_where_it_is(): void
    {
        $this->public->put('defects/broken.jpg', "\xFF\xD8\xFFnot really a photo");
        $this->public->put('defects/good.jpg', $this->jpeg());

        $this->artisan('photos:make-private')
            ->expectsOutputToContain('defects/broken.jpg')
            ->assertFailed();

        $this->public->assertExists('defects/broken.jpg');
        $this->private->assertMissing('defects/broken.jpg');
        $this->private->assertExists('defects/good.jpg');
    }

    public function test_when_reencoding_fails_the_original_is_kept_and_reported(): void
    {
        // Already private, carries camera metadata, and its image data cannot be decoded for re-encoding.
        $tagged = substr($this->jpeg(orientation: 6), 0, 200);
        $this->assertTrue(app(PhotoNormalizer::class)->hasCameraMetadata($tagged));
        $this->private->put('defects/tagged.jpg', $tagged);

        $this->artisan('photos:make-private', ['--strip-metadata' => true])
            ->expectsOutputToContain('Metadata not removed, original kept')
            ->assertFailed();

        $this->assertSame($tagged, $this->private->get('defects/tagged.jpg'), 'Original kept as it was');
        $this->assertSame(['defects/tagged.jpg'], $this->private->allFiles('defects'));
    }

    public function test_only_member_image_directories_are_accepted(): void
    {
        $this->artisan('photos:make-private', ['--directory' => ['narcotics-checks']])->assertExitCode(2);
        $this->public->put('defects/a.jpg', $this->jpeg());
        $this->public->put('signatures/s.png', $this->png());

        $this->artisan('photos:make-private', ['--directory' => ['signatures']])->assertSuccessful();

        $this->public->assertExists('defects/a.jpg');
        $this->private->assertExists('signatures/s.png');
    }

    private function jpeg(int $width = 40, int $height = 30, int $orientation = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, 0xE02020);
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        if ($orientation === 1) {
            return $bytes;
        }
        $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0).pack('V', 0);
        $payload = "Exif\0\0".$tiff.'GPSLatitudeRef=N';

        return substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($bytes, 2);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(20, 10);
        imagefill($image, 0, 0, 0x202020);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
