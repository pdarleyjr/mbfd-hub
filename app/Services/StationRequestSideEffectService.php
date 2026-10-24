<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StationRequest;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\Display\DisplaySnapshotService;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Support\Facades\Cache;

class StationRequestSideEffectService
{
    public function requestCreated(StationRequest $request): void
    {
        $this->forgetReadModels($request);

        $eventKey = NotificationDispatchService::eventKey(User::NOTIFICATION_PREFERENCE_STATION_REQUESTS, 'station_request', $request->id, 'created');

        // Everyone eligible for station requests right now; saved choices only narrow channels. Queued after the
        // request's write commits, and a failure here can never undo that write.
        app(NotificationDispatchService::class)->dispatch(
            User::NOTIFICATION_PREFERENCE_STATION_REQUESTS,
            new NewSubmissionNotification(
                submissionType: 'station_request',
                title: 'New Station Request',
                body: "{$request->request_number}: {$request->title}",
                actionUrl: '/admin/station-requests/'.$request->id,
                eventKey: $eventKey,
            ),
            eventKey: $eventKey,
        );
    }

    public function requestChanged(StationRequest $request): void
    {
        $this->forgetReadModels($request);
    }

    private function forgetReadModels(StationRequest $request): void
    {
        Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
        Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
        Cache::forget("station.{$request->station_id}.detail");
        Cache::forget("station.{$request->station_id}.activity");
        if ($request->room_id !== null) {
            Cache::forget("room.{$request->room_id}.profile");
        }
    }
}
