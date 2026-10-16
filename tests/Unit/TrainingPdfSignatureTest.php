<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Training\TrainingPdfSignature;
use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrainingPdfSignatureTest extends TestCase
{
    public static function signatures(): array
    {
        return [
            'transparent wide signature' => [false, true, [100, 70, 300, 90]],
            'opaque white padding' => [false, false, [100, 70, 300, 90]],
            'tall signature' => [false, true, [170, 20, 190, 160]],
            'palette with transparency' => [true, true, [100, 70, 300, 90]],
            'palette with white padding' => [true, false, [100, 70, 300, 90]],
            'ink touches all canvas edges' => [false, true, [0, 0, 399, 179]],
        ];
    }

    #[DataProvider('signatures')]
    public function test_padding_is_removed_without_losing_ink_or_distorting_the_signature(bool $palette, bool $transparent, array $bounds): void
    {
        $source = $palette ? imagecreate(400, 180) : imagecreatetruecolor(400, 180);
        self::assertInstanceOf(GdImage::class, $source);
        if (! $palette) {
            imagealphablending($source, false);
            imagesavealpha($source, true);
        }
        $background = imagecolorallocatealpha($source, 255, 255, 255, $transparent ? 127 : 0);
        imagefill($source, 0, 0, $background);
        if ($palette && $transparent) {
            imagecolortransparent($source, $background);
        }
        $ink = imagecolorallocate($source, 20, 20, 20);
        // A deterministic synthetic mark, including extremes that must survive trimming.
        imagerectangle($source, $bounds[0], $bounds[1], $bounds[2], $bounds[3], $ink);
        imageline($source, $bounds[0], $bounds[1], $bounds[2], $bounds[3], $ink);
        $originalInkPixels = $this->inkPixelCount($source);
        ob_start();
        imagepng($source);
        $png = (string) ob_get_clean();
        imagedestroy($source);

        foreach ([[116.0, 18.0], [160.0, 32.0]] as [$maxWidth, $maxHeight]) {
            $fit = (new TrainingPdfSignature)->fit($png, $maxWidth, $maxHeight);
            self::assertStringStartsWith('data:image/png;base64,', $fit['url']);
            $croppedBytes = base64_decode(substr($fit['url'], strlen('data:image/png;base64,')), true);
            self::assertIsString($croppedBytes);
            $cropped = imagecreatefromstring($croppedBytes);
            self::assertInstanceOf(GdImage::class, $cropped);

            self::assertGreaterThan(0, $fit['width']);
            self::assertGreaterThan(0, $fit['height']);
            self::assertLessThanOrEqual($maxWidth, $fit['width']);
            self::assertLessThanOrEqual($maxHeight, $fit['height']);
            self::assertEqualsWithDelta(1.0, max($fit['width'] / $maxWidth, $fit['height'] / $maxHeight), 0.001);
            self::assertEqualsWithDelta(imagesx($cropped) / imagesy($cropped), $fit['width'] / $fit['height'], 0.001);
            self::assertSame($originalInkPixels, $this->inkPixelCount($cropped), 'Cropping must retain every original ink pixel.');
            if ($bounds[0] > 0) {
                self::assertLessThan(300, imagesx($cropped), 'Large horizontal canvas padding must be removed.');
                self::assertLessThan(170, imagesy($cropped), 'Large vertical canvas padding must be removed.');
            }
            imagedestroy($cropped);
        }
    }

    public function test_faint_peripheral_ink_survives_cropping_with_its_original_color_and_alpha(): void
    {
        $source = imagecreatetruecolor(400, 180);
        self::assertInstanceOf(GdImage::class, $source);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        imagefill($source, 0, 0, imagecolorallocatealpha($source, 255, 255, 255, 127));
        $dark = imagecolorallocatealpha($source, 20, 20, 20, 0);
        $nearWhite = imagecolorallocatealpha($source, 254, 254, 254, 0);
        $almostTransparent = imagecolorallocatealpha($source, 0, 0, 0, 126);
        imagefilledrectangle($source, 100, 80, 120, 90, $dark);
        imagesetpixel($source, 20, 30, $nearWhite);
        imagesetpixel($source, 340, 150, $almostTransparent);
        ob_start();
        imagepng($source);
        $png = (string) ob_get_clean();
        imagedestroy($source);

        $fit = (new TrainingPdfSignature)->fit($png, 180, 20);
        $bytes = base64_decode(substr($fit['url'], strlen('data:image/png;base64,')), true);
        self::assertIsString($bytes);
        $cropped = imagecreatefromstring($bytes);
        self::assertInstanceOf(GdImage::class, $cropped);
        $counts = [$dark => 0, $nearWhite => 0, $almostTransparent => 0];
        for ($y = 0; $y < imagesy($cropped); $y++) {
            for ($x = 0; $x < imagesx($cropped); $x++) {
                $color = imagecolorat($cropped, $x, $y);
                if (array_key_exists($color, $counts)) {
                    $counts[$color]++;
                }
            }
        }

        self::assertSame(231, $counts[$dark]);
        self::assertSame(1, $counts[$nearWhite], 'The distant near-white opaque pixel must survive.');
        self::assertSame(1, $counts[$almostTransparent], 'The distant low-alpha pixel must retain its alpha.');
        self::assertGreaterThanOrEqual(321, imagesx($cropped));
        self::assertGreaterThanOrEqual(121, imagesy($cropped));
        self::assertLessThan(400, imagesx($cropped));
        self::assertLessThan(180, imagesy($cropped));
        imagedestroy($cropped);
    }

    private function inkPixelCount(GdImage $image): int
    {
        $count = 0;
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($color['alpha'] < 127 && min($color['red'], $color['green'], $color['blue']) < 250) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
