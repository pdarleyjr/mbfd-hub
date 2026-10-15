<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SignatureImageService
{
    /** @return array{disk: string, path: string, mime: string, sha256: string} */
    public function store(string $dataUrl, string $namespace = 'personnel-requests'): array
    {
        if (! in_array($namespace, ['personnel-requests', 'training-rosters'], true)) {
            throw new \InvalidArgumentException('Unknown signature namespace.');
        }
        if (strlen($dataUrl) > 1_400_000) {
            throw ValidationException::withMessages(['signature' => 'The signature image is too large.']);
        }
        if (! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $matches)) {
            throw ValidationException::withMessages(['signature' => 'Please provide a valid signature.']);
        }

        $bytes = base64_decode($matches[1], true);
        if ($bytes === false || strlen($bytes) > 1_000_000) {
            throw ValidationException::withMessages(['signature' => 'The signature image is invalid or too large.']);
        }

        $dimensions = @getimagesizefromstring($bytes);
        if (
            $dimensions === false
            || $dimensions[2] !== IMAGETYPE_PNG
            || $dimensions[0] < 2
            || $dimensions[1] < 2
            || $dimensions[0] > 1600
            || $dimensions[1] > 800
        ) {
            throw ValidationException::withMessages(['signature' => 'The signature image dimensions are invalid.']);
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages(['signature' => 'The signature image is invalid.']);
        }

        if ($this->isBlank($image)) {
            imagedestroy($image);
            throw ValidationException::withMessages(['signature' => 'Signature is required. Please sign before submitting.']);
        }
        imagedestroy($image);

        $disk = (string) config('filesystems.private', 'private');
        $filename = Str::ulid().'.png';
        $path = $namespace.'/signatures/'.now()->format('Y/m').'/'.$filename;
        if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private'])) {
            throw ValidationException::withMessages(['signature' => 'The signature could not be stored. Please try again.']);
        }

        if ($namespace === 'training-rosters') {
            $stored = Storage::disk($disk)->get($path);
            if (! is_string($stored) || ! hash_equals(hash('sha256', $bytes), hash('sha256', $stored))) {
                throw ValidationException::withMessages(['signature' => 'The stored signature could not be verified. Please try again.']);
            }
        }

        return ['disk' => $disk, 'path' => $path, 'mime' => 'image/png', 'sha256' => hash('sha256', $bytes)];
    }

    /** @param resource|\GdImage $image */
    private function isBlank($image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ink = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24;
                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;
                if ($alpha < 120 && ($red + $green + $blue) < 690) {
                    $ink++;
                }
            }
        }

        return $ink < max(1, (int) ceil(($width * $height) * 0.005));
    }
}
