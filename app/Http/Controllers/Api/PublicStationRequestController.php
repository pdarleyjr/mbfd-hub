<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\StationRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStationRequestRequest;
use App\Http\Resources\Public\PublicStationRequestResource;
use App\Models\Station;
use App\Models\StationRequest;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\StationRequestSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicStationRequestController extends Controller
{
    public function store(
        StoreStationRequestRequest $request,
        StationRequestSubmissionService $submissions,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
        $actor = $memberContextResolver->resolve($request)->actor();
        $actor->requireEmployee();
        $result = $submissions->submit($request->validated(), $actor);

        return (new PublicStationRequestResource($result->request))
            ->response()
            ->setStatusCode($result->created ? 201 : 200);
    }

    public function index(Request $request, Station $station): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(StationRequestStatus::values())],
            'request_type' => ['nullable', 'in:repair_service,equipment'],
            'room_id' => ['nullable', 'integer'],
            'scope' => ['nullable', 'in:open,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $station->stationRequests()
            ->with([
                'room:id,station_id,name',
                'items:id,station_request_id,room_asset_id,item_name,category,quantity,reason,requested_action,condition',
                'updates' => fn ($query) => $query->select('id', 'station_request_id', 'status', 'public_note', 'created_at')
                    ->where(fn ($nested) => $nested->whereNotNull('public_note')->orWhereNotNull('status')),
            ])
            ->when(($validated['scope'] ?? 'open') === 'open', fn ($query) => $query->whereIn('status', StationRequestStatus::openValues()))
            ->when(filled($validated['status'] ?? null), fn ($query) => $query->where('status', $validated['status']))
            ->when(filled($validated['request_type'] ?? null), fn ($query) => $query->where('request_type', $validated['request_type']))
            ->when(filled($validated['room_id'] ?? null), fn ($query) => $query->where('room_id', $validated['room_id']))
            ->latest('created_at');

        $records = $query->paginate((int) ($validated['per_page'] ?? 50));

        return PublicStationRequestResource::collection($records)->response();
    }

    public function show(StationRequest $stationRequest): PublicStationRequestResource
    {
        $stationRequest->load([
            'room:id,station_id,name',
            'items:id,station_request_id,room_asset_id,item_name,category,quantity,reason,requested_action,condition',
            'updates' => fn ($query) => $query->select('id', 'station_request_id', 'status', 'public_note', 'created_at'),
        ]);

        return new PublicStationRequestResource($stationRequest);
    }
}
