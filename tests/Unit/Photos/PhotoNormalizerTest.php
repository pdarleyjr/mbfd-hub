<?php

declare(strict_types=1);

namespace Tests\Unit\Photos;

use App\Services\Photos\PhotoNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Multi-frame (animated) originals are converted from their first frame only.
 */
final class PhotoNormalizerTest extends TestCase
{
    public function test_an_animated_gif_becomes_its_first_frame_through_gd(): void
    {
        $gif = $this->twoFrameGif(320, 240);
        $this->assertSame('gif', (new PhotoNormalizer)->detectType($gif));

        $jpeg = (new PhotoNormalizer)->toJpeg($gif);

        $this->assertSame([320, 240], [$jpeg['width'], $jpeg['height']]);
        $this->assertFirstFrameColour($jpeg['bytes']);
    }

    public function test_an_animated_gif_becomes_its_first_frame_through_imagick(): void
    {
        if (! class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick is not installed here (the production image has it).');
        }

        // A threshold of one pixel sends every non-HEIC original down the Imagick path.
        $jpeg = (new PhotoNormalizer(gdMaxPixels: 1))->toJpeg($this->twoFrameGif(320, 240));

        $this->assertSame([320, 240], [$jpeg['width'], $jpeg['height']]);
        $this->assertFirstFrameColour($jpeg['bytes']);
    }

    private function assertFirstFrameColour(string $jpeg): void
    {
        $image = imagecreatefromstring($jpeg);
        $this->assertInstanceOf(\GdImage::class, $image);
        $rgb = imagecolorat($image, 160, 120);
        [$red, $blue] = [($rgb >> 16) & 0xFF, $rgb & 0xFF];
        $this->assertGreaterThan(200, $red, 'The first (red) frame is kept.');
        $this->assertLessThan(60, $blue, 'The second (blue) frame is never used.');
    }

    /**
     * A red frame followed by a blue frame, built from two GD single-frame GIFs: the second
     * frame's global colour table becomes its local colour table.
     */
    private function twoFrameGif(int $width, int $height): string
    {
        $frames = [];
        foreach ([[255, 0, 0], [0, 0, 255]] as [$red, $green, $blue]) {
            $image = imagecreate($width, $height);
            imagecolorallocate($image, $red, $green, $blue);
            ob_start();
            imagegif($image);
            $frames[] = (string) ob_get_clean();
        }

        [$firstHeader, $firstTable, $firstBlocks] = $this->splitGif($frames[0]);
        [, $secondTable, $secondBlocks] = $this->splitGif($frames[1]);
        $this->assertSame("\x2C", $secondBlocks[0], 'GD writes the image descriptor first.');
        $descriptor = substr($secondBlocks, 0, 9).chr(0x80 | (ord($secondBlocks[9]) & 0x40) | (ord($frames[1][10]) & 0x07));
        $loop = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        $delay = "\x21\xF9\x04\x00\x0A\x00\x00\x00";

        return 'GIF89a'.substr($firstHeader, 6).$firstTable.$loop
            .$delay.substr($firstBlocks, 0, -1)
            .$delay.$descriptor.$secondTable.substr($secondBlocks, 10, -1)
            ."\x3B";
    }

    /** @return array{0: string, 1: string, 2: string} header (13 bytes), global colour table, remaining blocks */
    private function splitGif(string $gif): array
    {
        $packed = ord($gif[10]);
        $table = ($packed & 0x80) !== 0 ? 3 * (2 ** (($packed & 0x07) + 1)) : 0;

        return [substr($gif, 0, 13), substr($gif, 13, $table), substr($gif, 13 + $table)];
    }
}
