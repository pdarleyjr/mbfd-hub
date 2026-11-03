<?php

declare(strict_types=1);

namespace App\Support\UnitStatus;

/**
 * The board's own color for one unit row and its chip (contract section 11). It follows PulsePoint's colors except
 * where they would collide on this board: green means Available here, so En Route is amber, and Transporting is violet
 * so it stays distinct from En Route. The colors themselves live in resources/css/unit-status.css.
 */
final class BoardTone
{
    private const CALL_TONES = ['dispatched', 'enroute', 'on_scene', 'transport', 'arrived'];

    /** @param  array<string, mixed>  $availability */
    public static function for(array $availability): string
    {
        $key = $availability['key'] ?? null;
        if ($key === 'available') {
            return 'available';
        }
        if ($key === 'on_call') {
            $family = $availability['family'] ?? null;

            return in_array($family, self::CALL_TONES, true) ? $family : 'assigned';
        }

        return $key === 'unknown' ? 'not_confirmed' : 'idle';
    }
}
