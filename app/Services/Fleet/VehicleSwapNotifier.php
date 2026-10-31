<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells Fleet managers about a new pending vehicle swap through the Hub's
 * existing alert pipeline (NewSubmissionNotification), so the in-app inbox,
 * web push and city email follow each member's own "Vehicle swap reported"
 * preferences on the Notification Settings page. There is no separate
 * preference store.
 */
class VehicleSwapNotifier
{
    public const SUBMISSION_TYPE = 'vehicle_swap_reported';

    public const QUEUE_PATH = '/admin/vehicle-swap-requests';

    public function reported(int $requestId): void
    {
        $request = ApparatusVehicleSwapRequest::query()->find($requestId);
        if ($request === null || ! $request->isPending()) {
            return;
        }

        $unit = $request->unit_designation ?: 'A unit';
        $assigned = $request->assigned_vehicle_number ?: 'no vehicle recorded';
        $lines = [
            "{$unit} is assigned vehicle {$assigned}, but {$request->reporter_name} reported vehicle {$request->reported_vehicle_number} at Daily Checkout.",
            'Answer: '.ApparatusVehicleSwapRequest::answerLabel($request->answer).'.',
        ];
        if ($request->note !== null) {
            $lines[] = 'Note: '.$request->note;
        }
        $lines[] = 'The checkout is saved and its findings are on hold until you decide.';

        foreach ($this->recipients() as $recipient) {
            // One unreachable inbox, mailbox or push endpoint must never keep
            // the others from being told, or fail the checkout that raised it.
            try {
                $recipient->notify(new NewSubmissionNotification(
                    submissionType: self::SUBMISSION_TYPE,
                    title: "Vehicle swap reported on {$unit}",
                    body: implode(' ', $lines),
                    actionUrl: self::QUEUE_PATH,
                    icon: 'heroicon-o-arrows-right-left',
                ));
            } catch (Throwable $exception) {
                Log::error('Vehicle swap notification could not be delivered to one recipient', [
                    'vehicle_swap_request_id' => $requestId,
                    'recipient_id' => $recipient->getKey(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * A changeout is waiting on Fleet's decision: same category and pipeline as a swap report, told once. Best effort: the
     * send happens after commit, and nothing here can fail the submission.
     */
    public function changeoutWaiting(ApparatusChangeout $changeout): void
    {
        try {
            $category = User::NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED;
            $eventKey = NotificationDispatchService::eventKey($category, 'apparatus_changeout', (int) $changeout->getKey(), 'pending_fleet');
            $from = $changeout->from_vehicle_number ?: 'no vehicle recorded';

            app(NotificationDispatchService::class)->dispatch($category, new NewSubmissionNotification(
                submissionType: self::SUBMISSION_TYPE,
                title: "Vehicle change needs a decision on {$changeout->unit_designation}",
                body: "{$changeout->submitted_by_name} is moving {$changeout->unit_designation} from truck {$from} to truck {$changeout->to_vehicle_number}. The equipment choices are saved. No truck has changed until you decide.",
                actionUrl: '/admin/apparatus-changeouts/'.$changeout->getKey(),
                icon: 'heroicon-o-arrows-right-left',
                eventKey: $eventKey,
            ), eventKey: $eventKey);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Fleet administrators and Fleet Operations Managers: whoever may decide.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        return app(NotificationRecipientEvaluator::class)->recipients(User::NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED);
    }
}
