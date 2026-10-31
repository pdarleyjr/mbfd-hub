<?php

declare(strict_types=1);

namespace App\Services\Fleet;

/**
 * Checklist templates have no controlled flag, so controlled equipment is recognised by name
 * (config/fleet_changeouts.php). A controlled line is never marked moved by a handoff.
 */
final class ControlledEquipment
{
    public static function matches(?string $name): bool
    {
        $label = mb_strtolower(trim((string) $name));
        if ($label === '') {
            return false;
        }
        foreach ((array) config('fleet_changeouts.controlled_name_patterns', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && str_contains($label, mb_strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
