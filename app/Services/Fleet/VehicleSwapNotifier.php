<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\ApparatusChangeout;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Support\Fleet\ChangeoutAlertText;
use Throwable;

/**
 * Tells admins and Fleet managers a crew changed trucks, through the Hub's existing alert pipeline, so the in-app inbox,
 * web push and city email follow each member's own "Truck changed" preferences. There is no separate preference store.
 */
class VehicleSwapNotifier
{
    /** Stored on notifications already sent, and how User::preferenceKeyForSubmissionType finds the preference. It never changes. */
    public const SUBMISSION_TYPE = 'vehicle_swap_reported';

    /**
     * A crew changed trucks. It already happened and nobody needs to approve it: this tells Fleet, once. Best effort: the
     * send happens after commit, and nothing here can fail the swap.
     */
    public function changeoutCommitted(ApparatusChangeout $changeout): void
    {
        try {
            $category = User::NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED;
            $eventKey = NotificationDispatchService::eventKey($category, 'apparatus_changeout', (int) $changeout->getKey(), 'committed');

            app(NotificationDispatchService::class)->dispatch($category, new NewSubmissionNotification(
                submissionType: self::SUBMISSION_TYPE,
                title: $changeout->headline(),
                body: ChangeoutAlertText::body($changeout),
                actionUrl: '/admin/apparatus-changeouts/'.$changeout->getKey(),
                icon: 'heroicon-o-arrows-right-left',
                eventKey: $eventKey,
            ), eventKey: $eventKey);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
