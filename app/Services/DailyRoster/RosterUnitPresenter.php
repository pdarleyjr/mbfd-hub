<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

/**
 * Picks the image for a roster section from its TeleStaff unit label, exactly. Anything not named
 * here (Air Truck 810, Rescue Float, float pools, a unit added next year) gets the neutral
 * pictogram, because showing the wrong vehicle is worse than showing none.
 */
final class RosterUnitPresenter
{
    private const DIRECTORY = 'images/apparatus/roster';

    /** Base name => [kind, 1x width, 2x width, 1x height] */
    private const IMAGES = [
        'engine' => [RosterUnitImage::CUTOUT, 96, 192, 33],
        'ladder-1' => [RosterUnitImage::CUTOUT, 96, 192, 28],
        'ladder-3' => [RosterUnitImage::CUTOUT, 96, 192, 28],
        'rescue' => [RosterUnitImage::CUTOUT, 96, 192, 37],
        'fireboat' => [RosterUnitImage::CUTOUT, 96, 192, 34],
        'command-300' => [RosterUnitImage::PHOTO, 120, 240, 80],
        'captain-5' => [RosterUnitImage::PHOTO, 120, 240, 80],
    ];

    /** @var array<string, bool> Whether a file exists, remembered for the life of the process */
    private static array $files = [];

    private readonly string $publicPath;

    public function __construct(?string $publicPath = null)
    {
        $this->publicPath = rtrim($publicPath ?? public_path(), '/\\');
    }

    public function present(string $unitLabel): RosterUnitImage
    {
        $name = $this->imageName(trim($unitLabel));
        if ($name === null) {
            return new RosterUnitImage(RosterUnitImage::PICTOGRAM);
        }

        [$kind, $width, $doubleWidth, $height] = self::IMAGES[$name];
        $single = sprintf('%s/%s-%d.webp', self::DIRECTORY, $name, $width);
        $double = sprintf('%s/%s-%d.webp', self::DIRECTORY, $name, $doubleWidth);
        if (! $this->exists($single) || ! $this->exists($double)) {
            return new RosterUnitImage(RosterUnitImage::PICTOGRAM);
        }

        return new RosterUnitImage($kind, '/'.$single, sprintf('/%s 1x, /%s 2x', $single, $double), $width, $height);
    }

    private function imageName(string $unitLabel): ?string
    {
        return match (true) {
            preg_match('/\AEngine \d+\z/', $unitLabel) === 1 => 'engine',
            $unitLabel === 'Ladder 1' => 'ladder-1',
            $unitLabel === 'Ladder 3' => 'ladder-3',
            preg_match('/\ARescue \d+\z/', $unitLabel) === 1 => 'rescue',
            preg_match('/\AFire Boat \d+\z/', $unitLabel) === 1 => 'fireboat',
            $unitLabel === 'Division Chief 300' => 'command-300',
            $unitLabel === 'Captain 5' => 'captain-5',
            default => null,
        };
    }

    private function exists(string $relativePath): bool
    {
        $path = $this->publicPath.'/'.$relativePath;

        return self::$files[$path] ??= is_file($path);
    }
}
