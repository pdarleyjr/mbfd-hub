<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Notifications\StationRequestMemberResolvedNotification;
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

    /**
     * A member marked the request resolved. Runs after the write commits and never undoes it. The normal read-model
     * refresh happens first; then the requester is told (when someone else resolved it) and station staff are told.
     * Each alert carries an event key built from the history row, so a retried or replayed resolve cannot send twice.
     */
    public function memberResolved(StationRequest $request, StationRequestUpdate $update, User $resolver, bool $resolverIsRequester, string $outcomeLabel): void
    {
        $this->requestChanged($request);

        try {
            $this->notifyStaffOfMemberResolution($request, $update, $resolver, $outcomeLabel);
            if (! $resolverIsRequester) {
                $this->notifyRequesterOfMemberResolution($request, $update, $resolver, $outcomeLabel);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function notifyStaffOfMemberResolution(StationRequest $request, StationRequestUpdate $update, User $resolver, string $outcomeLabel): void
    {
        $eventKey = NotificationDispatchService::eventKey(User::NOTIFICATION_PREFERENCE_STATION_REQUESTS, 'station_request', $request->id, 'member_resolved:'.$update->id);

        app(NotificationDispatchService::class)->dispatch(
            User::NOTIFICATION_PREFERENCE_STATION_REQUESTS,
            new NewSubmissionNotification(
                submissionType: 'station_request',
                title: 'Station Request Resolved',
                body: "{$request->request_number}: {$request->title} was marked resolved ({$outcomeLabel}).",
                actionUrl: '/admin/station-requests/'.$request->id,
                icon: 'heroicon-o-check-circle',
                eventKey: $eventKey,
                pushSubject: (string) $request->request_number,
            )->forTriageSubject('station_request', (int) $request->id),
            eventKey: $eventKey,
            exceptUserId: (int) $resolver->getKey(),
        );
    }

    private function notifyRequesterOfMemberResolution(StationRequest $request, StationRequestUpdate $update, User $resolver, string $outcomeLabel): void
    {
        $requester = $request->actor_user_id !== null
            ? User::query()->find($request->actor_user_id)
            : $request->requestedByEmployee?->user;
        if (! $requester instanceof User || (int) $requester->getKey() === (int) $resolver->getKey()) {
            return;
        }

        $eventKey = NotificationDispatchService::eventKey(User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES, 'station_request', $request->id, 'member_resolved:'.$update->id);
        $profile = $resolver->getRelationValue('employeeProfile');
        $profileName = $profile instanceof Employee ? $profile->name : null;
        $name = trim((string) ($profileName ?? $resolver->name));

        app(NotificationDispatchService::class)->dispatch(
            User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES,
            new StationRequestMemberResolvedNotification(
                requestNumber: (string) $request->request_number,
                requestTitle: (string) $request->title,
                resolverName: $name !== '' ? $name : 'A member',
                outcomeLabel: $outcomeLabel,
                url: '/daily/stations/'.(int) $request->station_id,
                eventKey: $eventKey,
            ),
            [$requester],
            $eventKey,
        );
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
