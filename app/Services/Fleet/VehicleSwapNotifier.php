<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\ApparatusVehicleSwapRequest;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\NewSubmissionNotification;
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
                UserNotificationSubscription::ensureVehicleSwapForUser((int) $recipient->getKey());
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
     * Fleet administrators and Fleet Operations Managers: whoever may decide.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        return User::query()
            ->where(function ($query): void {
                $query->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', 'admin.fleet.manage'));
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $user->canManageFleetSwaps())
            ->values();
    }
}
