<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StationRequest;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\AiAutomation\AiIntake;
use App\Services\Display\DisplaySnapshotService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\SupportTriage\TriageProjector;
use Illuminate\Support\Facades\Cache;

class StationRequestSideEffectService
{
    public function requestCreated(StationRequest $request): void
    {
        $this->forgetReadModels($request);
        // Staff triage level, computed now (before the alert below is filtered by level). Never breaks the save.
        $this->refreshTriage($request);

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
            )->forTriageSubject('station_request', (int) $request->id),
            eventKey: $eventKey,
        );

        // New rows only (this method is never called for an edit or a replayed submission). Records the intake for
        // the AI Response receipt; it never throws, does nothing while the master switch is OFF, and runs after commit.
        AiIntake::record('station_request', (int) $request->id);
    }

    public function requestChanged(StationRequest $request): void
    {
        $this->forgetReadModels($request);
        $this->refreshTriage($request);
    }

    private function refreshTriage(StationRequest $request): void
    {
        try {
            app(TriageProjector::class)->refreshStationRequest($request->loadMissing('items:id,station_request_id,item_name'));
        } catch (\Throwable $exception) {
            report($exception);
        }
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
