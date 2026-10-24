<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Filament\Resources\ApparatusResource;
use App\Models\Apparatus;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells Fleet managers when a crew's identification answers created a vehicle record or could
 * not be matched safely, through the same alert pipeline as "Vehicle swap reported". Who is told,
 * and over which channels, follows each member's own "Vehicle identification for Fleet" choice.
 */
class VehicleIdentificationNotifier
{
    public const SUBMISSION_TYPE = 'vehicle_identification_reported';

    /**
     * @param  list<array{type: string, message: string}>  $alerts
     */
    public function reported(int $apparatusId, array $alerts): void
    {
        $apparatus = Apparatus::query()->find($apparatusId);
        if ($apparatus === null || $alerts === []) {
            return;
        }

        $unit = $apparatus->designation ?: $apparatus->name ?: 'A unit';
        $created = collect($alerts)->contains(fn (array $alert): bool => $alert['type'] === VehicleIdentificationResolver::ALERT_MASTER_CREATED);
        $title = $created ? "New vehicle record to verify for {$unit}" : "Vehicle information to review for {$unit}";
        $body = collect($alerts)->pluck('message')->unique()->implode(' ');
        $eventKey = 'vehicle_identification:'.$apparatusId.':'.substr(sha1($body), 0, 16);

        foreach ($this->recipients() as $recipient) {
            // One unreachable inbox, mailbox or push endpoint must never keep the others from being told.
            try {
                $recipient->notify(new NewSubmissionNotification(
                    submissionType: self::SUBMISSION_TYPE,
                    title: $title,
                    body: $body,
                    actionUrl: $this->actionUrl($apparatusId),
                    icon: 'heroicon-o-identification',
                    eventKey: $eventKey,
                ));
            } catch (Throwable $exception) {
                Log::error('Vehicle identification notification could not be delivered to one recipient', [
                    'apparatus_id' => $apparatusId,
                    'recipient_id' => $recipient->getKey(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Fleet administrators and Fleet Operations Managers: whoever may fix a vehicle record.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        return app(NotificationRecipientEvaluator::class)->recipients(User::NOTIFICATION_PREFERENCE_VEHICLE_IDENTIFICATION);
    }

    private function actionUrl(int $apparatusId): string
    {
        try {
            return ApparatusResource::getUrl('view', ['record' => $apparatusId], isAbsolute: false, panel: 'admin');
        } catch (Throwable) {
            return '/admin/apparatuses/'.$apparatusId;
        }
    }
}
