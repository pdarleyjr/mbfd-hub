<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Services\Fleet\ApparatusChangeoutPlanner;
use App\Services\Fleet\ApparatusChangeoutPresenter;
use App\Support\HubTimezone;

/** How a changed truck reads wherever Fleet is told about it: the dashboard, the apparatus pages and the notification. */
final class ChangeoutAlertText
{
    /** "R 44 changed trucks: 17503 to 1033, Sunday 3:12 PM by Pat Member" */
    public static function bannerLine(ApparatusChangeout $changeout): string
    {
        return $changeout->headline().', '.self::when($changeout).' by '.$changeout->submitted_by_name;
    }

    /** "Sunday 3:12 PM", Eastern. */
    public static function when(ApparatusChangeout $changeout): string
    {
        return ($changeout->effective_at ?? $changeout->created_at)?->setTimezone(HubTimezone::NAME)->format('l g:i A') ?? 'time not recorded';
    }

    public static function oldTruckSentence(ApparatusChangeout $changeout): string
    {
        return ($changeout->move_audit['mode'] ?? null) === ApparatusChangeoutPlanner::MODE_HANDOFF_ONLY
            ? 'The unit had already moved, so no truck status changed here.'
            : ApparatusChangeoutPresenter::oldTruckSentence($changeout);
    }

    /** "Left behind: Halligan, Axe." or nothing. */
    public static function leftBehindSentence(ApparatusChangeout $changeout): ?string
    {
        $names = $changeout->leftBehindItems->map(static fn (ApparatusChangeoutItem $item): string => $item->item_name)->all();

        return $names === [] ? null : 'Left behind: '.implode(', ', $names).'.';
    }

    /** Who, when, the old truck's status, what stayed, and anything Fleet should look at. */
    public static function body(ApparatusChangeout $changeout, bool $withName = true): string
    {
        $from = $changeout->from_vehicle_number ?: 'not recorded';

        return implode(' ', array_filter([
            ($withName ? $changeout->submitted_by_name.' moved ' : 'Moved ').$changeout->unit_designation." from truck {$from} to truck {$changeout->to_vehicle_number}, ".self::when($changeout).'.',
            self::oldTruckSentence($changeout),
            self::leftBehindSentence($changeout),
            filled($changeout->note) && $withName ? 'Crew note: '.$changeout->note : null,
            ...$changeout->reviewNotes(),
        ]));
    }
}
