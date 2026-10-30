<?php

declare(strict_types=1);

namespace Tests\Unit\Narcotics;

use App\Services\Narcotics\ExifOrientation;
use PHPUnit\Framework\TestCase;

final class ExifOrientationTest extends TestCase
{
    private function jpegWithTiff(string $tiff): string
    {
        $payload = "Exif\0\0".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($payload) + 2).$payload."\xFF\xDA\x00\x02";
    }

    private function littleEndian(int $orientation): string
    {
        return 'II'.pack('v', 42).pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0).pack('V', 0);
    }

    private function bigEndian(int $orientation): string
    {
        return 'MM'.pack('n', 42).pack('N', 8).pack('n', 1).pack('n', 0x0112).pack('n', 3).pack('N', 1).pack('n', $orientation).pack('n', 0).pack('N', 0);
    }

    public function test_it_reads_both_byte_orders(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $value) {
            $this->assertSame($value, ExifOrientation::fromJpeg($this->jpegWithTiff($this->littleEndian($value))));
            $this->assertSame($value, ExifOrientation::fromJpeg($this->jpegWithTiff($this->bigEndian($value))));
        }
    }

    public function test_anything_unreadable_is_upright(): void
    {
        $this->assertSame(1, ExifOrientation::fromJpeg(''));
        $this->assertSame(1, ExifOrientation::fromJpeg('not a jpeg'));
        $this->assertSame(1, ExifOrientation::fromJpeg("\xFF\xD8\xFF\xDA\x00\x02"));
        $this->assertSame(1, ExifOrientation::fromJpeg($this->jpegWithTiff($this->littleEndian(9))), 'out-of-range value');
        $this->assertSame(1, ExifOrientation::fromJpeg($this->jpegWithTiff('XX'.str_repeat("\0", 20))), 'bad byte order');
        $this->assertSame(1, ExifOrientation::fromJpeg($this->jpegWithTiff(substr($this->littleEndian(6), 0, 12))), 'truncated directory');
        $this->assertSame(1, ExifOrientation::fromJpeg("\xFF\xD8\xFF\xE1\xFF\xFF".str_repeat("\xFF", 20)), 'absurd segment length');
    }
}
