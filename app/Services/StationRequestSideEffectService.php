<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StationRequest;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class StationRequestSideEffectService
{
    public function requestCreated(StationRequest $request): void
    {
        $this->forgetReadModels($request);

        $recipients = User::query()
            ->whereHas('notificationSubscriptions', fn ($query) => $query
                ->where('event_key', User::NOTIFICATION_PREFERENCE_STATION_REQUESTS)
                ->where(fn ($channels) => $channels
                    ->where('database_enabled', true)
                    ->orWhere('webpush_enabled', true)
                    ->orWhere('email_enabled', true)))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new NewSubmissionNotification(
                submissionType: 'station_request',
                title: 'New Station Request',
                body: "{$request->request_number}: {$request->title}",
                actionUrl: '/admin/station-requests/'.$request->id,
            ));
        }
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
