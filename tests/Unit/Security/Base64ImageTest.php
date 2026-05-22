<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Support\Security\Base64Image;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class Base64ImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_rejects_payload_without_image_magic_bytes(): void
    {
        $fakeText = base64_encode('this is not an image at all');
        $payload = 'data:image/jpeg;base64,' . $fakeText;

        self::assertNull(Base64Image::store($payload, 'defects', 'defect'));
    }

    public function test_rejects_html_disguised_as_jpeg(): void
    {
        $html = base64_encode('<html><script>alert(1)</script></html>');
        $payload = 'data:image/jpeg;base64,' . $html;

        self::assertNull(Base64Image::store($payload, 'defects', 'defect'));
    }

    public function test_rejects_payload_with_mismatched_declared_subtype(): void
    {
        $jpegBytes = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 32);
        $payload = 'data:image/png;base64,' . base64_encode($jpegBytes);

        self::assertNull(Base64Image::store($payload, 'defects', 'defect'));
    }

    public function test_accepts_valid_jpeg_magic_bytes(): void
    {
        $jpegBytes = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 32);
        $payload = 'data:image/jpeg;base64,' . base64_encode($jpegBytes);

        $path = Base64Image::store($payload, 'defects', 'defect');

        self::assertNotNull($path);
        self::assertStringStartsWith('defects/defect_', $path);
        self::assertStringEndsWith('.jpg', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_accepts_valid_png_magic_bytes(): void
    {
        $pngBytes = "\x89PNG\r\n\x1A\n" . str_repeat("\x00", 32);
        $payload = 'data:image/png;base64,' . base64_encode($pngBytes);

        $path = Base64Image::store($payload, 'signatures', 'signature');

        self::assertNotNull($path);
        self::assertStringEndsWith('.png', $path);
    }

    public function test_rejects_payload_exceeding_maxbytes(): void
    {
        $jpegBytes = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100_000);
        $payload = 'data:image/jpeg;base64,' . base64_encode($jpegBytes);

        self::assertNull(Base64Image::store($payload, 'defects', 'defect', maxBytes: 10_000));
    }

    public function test_rejects_data_uri_with_non_image_mime(): void
    {
        $payload = 'data:text/html;base64,' . base64_encode('<script>alert(1)</script>');

        self::assertNull(Base64Image::store($payload, 'defects', 'defect'));
    }
}
