<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The finite set of tracked Daily Checkout checklist sources an authorized
 * apparatus administrator may approve for an apparatus.
 */
enum DailyCheckoutChecklistTemplate: string
{
    case Pending = 'pending';
    case Default = 'default';
    case Engine = 'engine';
    case Engine2 = 'engine2';
    case Rescue = 'rescue';
    case Ladder1 = 'ladder1';
    case Ladder3 = 'ladder3';

    public function isConfigured(): bool
    {
        return $this !== self::Pending;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Pending->value => 'Pending - no specialty template approved',
            self::Default->value => 'Default checklist - explicit operational approval required',
            self::Engine->value => 'Engine checklist',
            self::Engine2->value => 'Engine 2 checklist',
            self::Rescue->value => 'Rescue checklist',
            self::Ladder1->value => 'Ladder 1 checklist',
            self::Ladder3->value => 'Ladder 3 checklist',
        ];
    }
}
