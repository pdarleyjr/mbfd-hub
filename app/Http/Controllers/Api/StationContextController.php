<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Public\PublicRoomAssetResource;
use App\Http\Resources\Public\PublicRoomResource;
use App\Http\Resources\Public\PublicStationRequestResource;
use App\Models\Room;
use App\Models\RoomAssetEvent;
use App\Models\Station;
use App\Models\StationRequest;
use App\Services\StationActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationContextController extends Controller
{
    public function activity(Request $request, Station $station, StationActivityService $activity): JsonResponse
    {
        $validated = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json([
            'station_id' => $station->id,
            'activity' => $activity->forStation($station, (int) ($validated['limit'] ?? 50)),
        ]);
    }

    public function roomProfile(Request $request, Station $station, Room $room): JsonResponse
    {
        abort_unless((int) $room->station_id === (int) $station->id, 404);

        $requestRelations = [
            'items:id,station_request_id,room_asset_id,item_name,category,quantity,reason,requested_action,condition',
            'updates:id,station_request_id,status,public_note,created_at',
        ];
        $room->load(['assets' => fn ($query) => $query->active()->orderBy('category')->orderBy('name')]);
        $openRequests = StationRequest::query()
            ->where('room_id', $room->id)
            ->open()
            ->with($requestRelations)
            ->latest('created_at')
            ->limit(50)
            ->get();
        $requestHistory = StationRequest::query()
            ->where('room_id', $room->id)
            ->with($requestRelations)
            ->latest('created_at')
            ->limit(50)
            ->get();
        $events = RoomAssetEvent::query()
            ->whereHas('roomAsset', fn ($query) => $query->where('room_id', $room->id))
            ->with(['stationRequest:id,request_number', 'roomAsset:id,room_id,name'])
            ->latest('event_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (RoomAssetEvent $event): array => [
                'id' => $event->id,
                'room_asset_id' => $event->room_asset_id,
                'asset_name' => $event->roomAsset?->name,
                'request_number' => $event->stationRequest?->request_number,
                'event_type' => $event->event_type,
                'event_at' => $event->event_at,
            ]);

        return response()->json([
            'room' => (new PublicRoomResource($room))->resolve($request),
            'current_assets' => PublicRoomAssetResource::collection($room->assets)->resolve($request),
            'open_requests' => PublicStationRequestResource::collection($openRequests)->resolve($request),
            'request_history' => PublicStationRequestResource::collection($requestHistory)->resolve($request),
            'asset_events' => $events,
        ]);
    }
}
