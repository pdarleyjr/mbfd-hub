<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Support\Colors\Color;

final class HubPalette
{
    /** @return array<string, array<int, string>> */
    public static function colors(): array
    {
        // Filament needs RGB shade arrays. These are the PHP projection of the
        // semantic colors in mbfd-theme.css, shared by every Hub panel.
        return [
            'primary' => self::semantic('#1E4E8C'),
            'danger' => self::semantic('#B91C1C'),
            'info' => self::semantic('#0369A1'),
            'success' => self::semantic('#047857'),
            'warning' => self::semantic('#B45309'),
            'gray' => [
                50 => '243, 245, 248',
                100 => '232, 236, 241',
                200 => '220, 226, 232',
                300 => '184, 196, 209',
                400 => '133, 147, 163',
                500 => '100, 116, 139',
                600 => '71, 85, 105',
                700 => '51, 65, 85',
                800 => '30, 41, 59',
                900 => '15, 23, 42',
                950 => '8, 15, 28',
            ],
        ];
    }

    /** @return array<int, string> */
    private static function semantic(string $hex): array
    {
        $shades = Color::hex($hex);
        // Native Filament actions use shade 600; keep that exact brand token.
        $shades[600] = $shades[500];

        return $shades;
    }
}
