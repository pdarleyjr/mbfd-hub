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
use App\Services\StationRequests\StationRequestMemberResolution;
use App\Services\StationRequests\StationRequestMemberResolveRefused;
use App\Services\StationRequestSubmissionService;
use App\Support\Stations\StationRequestMemberAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicStationRequestController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $station->stationRequests()
            ->with([
                'room:id,station_id,name',
                'items:id,station_request_id,room_asset_id,item_name,category,quantity,reason,requested_action,condition',
                'updates' => fn ($query) => $query->select('id', 'station_request_id', 'status', 'public_note', 'created_at', 'automation_intake_id')
                    ->where(fn ($nested) => $nested->whereNotNull('public_note')->orWhereNotNull('status')),
            ])
            ->when(($validated['scope'] ?? 'open') === 'open', fn ($query) => $query->open())
            ->when(filled($validated['status'] ?? null), fn ($query) => $query->where('status', $validated['status']))
            ->when(filled($validated['request_type'] ?? null), fn ($query) => $query->where('request_type', $validated['request_type']))
            ->when(filled($validated['room_id'] ?? null), fn ($query) => $query->where('room_id', $validated['room_id']))
            ->latest('created_at')
            // Stable order so walking pages never skips or repeats a request.
            ->orderByDesc('id');

        // Paginated (default 50 for existing clients). For complete history,
        // request per_page=200 and follow meta.current_page to meta.last_page.
        $records = $query->paginate((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE));

        // Additive per-row field for the signed-in member. Computed in memory from the rows already loaded.
        // The resource collection replaces the paginator's models, so take them first.
        $models = array_values($records->items());
        $payload = PublicStationRequestResource::collection($records)->response()->getData(true);
        $user = $request->user();
        foreach ($models as $position => $record) {
            $payload['data'][$position]['member_resolution'] = StationRequestMemberAccess::resolutionFor($user, $record);
        }

        return response()->json($payload);
    }

    /**
     * A member marks an open station request resolved. Everything that decides the outcome is read on the server:
     * the signed-in account, its employee profile, and the stored request. See StationRequestMemberResolution.
     */
    public function memberResolve(
        Request $request,
        StationRequest $stationRequest,
        StationRequestMemberResolution $resolution,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
        $actor = $memberContextResolver->resolve($request)->actor();
        $actor->requireEmployee();
        $user = $actor->user();
        abort_unless(StationRequestMemberAccess::isEligibleMember($user), 403);

        $validated = $request->validate([
            'client_action_id' => ['required', 'uuid'],
            'outcome' => ['required', Rule::in(array_keys(StationRequestMemberResolution::OUTCOMES))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $resolution->resolve(
                $stationRequest,
                $user,
                (string) $validated['client_action_id'],
                (string) $validated['outcome'],
                $validated['note'] ?? null,
            );
        } catch (StationRequestMemberResolveRefused $refusal) {
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => $refusal->errorCode,
            ] + $refusal->extra, $refusal->httpStatus);
        }

        return response()->json(['data' => [
            'status' => $result['status'],
            'status_label' => $result['status_label'],
            'message' => $result['message'],
        ]]);
    }

    /**
     * One request by id. The station Requests tab reads the station-wide list above; nothing in the app calls this
     * by-id read, so it is limited to the member who submitted the request and to staff who view station requests.
     * Anyone else gets the same 404 as a request that does not exist, so ids cannot be probed.
     */
    public function show(Request $request, StationRequest $stationRequest): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user !== null
                && ($stationRequest->actor_user_id !== null && (int) $stationRequest->actor_user_id === (int) $user->getKey()
                    || $user->can('admin.stations.view', 'web')),
            404,
        );

        $stationRequest->load([
            'room:id,station_id,name',
            'items:id,station_request_id,room_asset_id,item_name,category,quantity,reason,requested_action,condition',
            'updates' => fn ($query) => $query->select('id', 'station_request_id', 'status', 'public_note', 'created_at', 'automation_intake_id'),
        ]);

        $payload = (new PublicStationRequestResource($stationRequest))->response()->getData(true);
        $payload['data']['member_resolution'] = StationRequestMemberAccess::resolutionFor($user, $stationRequest);

        return response()->json($payload);
    }
}
