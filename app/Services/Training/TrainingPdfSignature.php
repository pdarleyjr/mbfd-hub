<?php

declare(strict_types=1);

namespace App\Services\Training;

use RuntimeException;

final class TrainingPdfSignature
{
    /** @return array{url: string, width: float, height: float} */
    public function fit(string $png, float $maxWidth, float $maxHeight): array
    {
        $image = imagecreatefromstring($png);
        if ($image === false) {
            throw new RuntimeException('The roster signature could not be rendered.');
        }

        // Crop only the display copy. The stored ink and its evidence hash stay intact.
        imagepalettetotruecolor($image);
        $width = imagesx($image);
        $height = imagesy($image);
        $left = $width;
        $top = $height;
        $right = $bottom = -1;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $darkness = 255 - min(($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF);
                if ($darkness > 0 && $alpha < 127) {
                    $left = min($left, $x);
                    $right = max($right, $x);
                    $top = min($top, $y);
                    $bottom = max($bottom, $y);
                }
            }
        }

        if ($right >= $left) {
            // Keep a small edge around antialiased strokes, including ink at canvas edges.
            $left = max(0, $left - 2);
            $top = max(0, $top - 2);
            $right = min($width - 1, $right + 2);
            $bottom = min($height - 1, $bottom + 2);
            $cropped = imagecrop($image, ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1]);
            if ($cropped === false) {
                throw new RuntimeException('The roster signature could not be prepared.');
            }
            $image = $cropped;
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        $scale = min($maxWidth / imagesx($image), $maxHeight / imagesy($image));

        return [
            'url' => 'data:image/png;base64,'.base64_encode($bytes),
            'width' => imagesx($image) * $scale,
            'height' => imagesy($image) * $scale,
        ];
    }
}
