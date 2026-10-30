<?php

declare(strict_types=1);

namespace App\Services\Narcotics;

/**
 * Reads the EXIF Orientation tag (1-8) from a JPEG without ext-exif, so a phone's sideways original is
 * stood upright on every server. Only the first segments are scanned; anything unreadable is 1 (upright).
 */
final class ExifOrientation
{
    private const SCAN_LIMIT = 131072;

    public static function fromJpeg(string $bytes): int
    {
        $length = min(strlen($bytes), self::SCAN_LIMIT);
        if ($length < 4 || ! str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }

        $offset = 2;
        while ($offset + 4 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                return 1;
            }
            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xFF) { // fill byte
                $offset++;

                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) { // start of scan / end of image: no more metadata
                return 1;
            }
            $size = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);
            if ($size < 2) {
                return 1;
            }
            if ($marker === 0xE1 && substr($bytes, $offset + 4, 6) === "Exif\0\0") {
                return self::fromTiff(substr($bytes, $offset + 10, $size - 8));
            }
            $offset += 2 + $size;
        }

        return 1;
    }

    private static function fromTiff(string $tiff): int
    {
        if (strlen($tiff) < 14) {
            return 1;
        }
        $little = match (substr($tiff, 0, 2)) {
            'II' => true,
            'MM' => false,
            default => null,
        };
        if ($little === null) {
            return 1;
        }
        $short = static fn (int $at): int => $little
            ? ord($tiff[$at]) | (ord($tiff[$at + 1]) << 8)
            : (ord($tiff[$at]) << 8) | ord($tiff[$at + 1]);
        $long = static fn (int $at): int => $little
            ? ord($tiff[$at]) | (ord($tiff[$at + 1]) << 8) | (ord($tiff[$at + 2]) << 16) | (ord($tiff[$at + 3]) << 24)
            : (ord($tiff[$at]) << 24) | (ord($tiff[$at + 1]) << 16) | (ord($tiff[$at + 2]) << 8) | ord($tiff[$at + 3]);

        if ($short(2) !== 42) {
            return 1;
        }
        $ifd = $long(4);
        if ($ifd < 8 || $ifd + 2 > strlen($tiff)) {
            return 1;
        }
        $count = $short($ifd);
        for ($index = 0; $index < $count; $index++) {
            $entry = $ifd + 2 + ($index * 12);
            if ($entry + 12 > strlen($tiff)) {
                return 1;
            }
            if ($short($entry) === 0x0112) {
                $value = $short($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
