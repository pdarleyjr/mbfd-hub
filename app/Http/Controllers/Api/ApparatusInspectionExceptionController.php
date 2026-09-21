<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApparatusInspectionException;
use App\Services\ApparatusInspectionExceptionService;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApparatusInspectionExceptionController extends Controller
{
    public function __construct(
        private readonly AuthenticatedMemberContextResolver $members,
        private readonly ApparatusInspectionExceptionService $exceptions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $member = $this->members->resolve($request)->user();
        $records = ApparatusInspectionException::query()
            ->whereHas('inspection', fn ($query) => $query->where('actor_user_id', $member->id))
            ->whereIn('status', ['revision_requested', 'revision_submitted'])
            ->with([
                'apparatus:id,vehicle_number,name,unit_id,current_engine_hours,current_miles',
                'inspection:id',
                'inspection.reviewEvents' => fn ($query) => $query
                    ->where('metadata->action', 'request_revision')->latest('id'),
            ])
            ->latest('id')->get()
            ->map(static function (ApparatusInspectionException $exception): array {
                $note = $exception->inspection->reviewEvents->first(
                    fn ($event) => (int) ($event->metadata['exception_id'] ?? 0) === (int) $exception->id,
                );
                $apparatus = $exception->apparatus;

                return [
                    'id' => $exception->id,
                    'status' => $exception->status,
                    'field' => $exception->field,
                    'reason' => $exception->reason,
                    'submitted_value' => $exception->submitted_value,
                    'current_value' => match ($exception->field) {
                        'engine_hours' => $apparatus->current_engine_hours,
                        'miles' => $apparatus->current_miles,
                        default => null,
                    },
                    'reviewer_note' => $note?->internal_note,
                    'apparatus' => [
                        'id' => $apparatus->id,
                        'vehicle_number' => $apparatus->vehicle_number,
                        'name' => $apparatus->name,
                        'unit_id' => $apparatus->getAttribute('unit_id'),
                    ],
                ];
            });

        return response()->json(['data' => $records])->header('Cache-Control', 'no-store, private');
    }

    public function revision(Request $request, ApparatusInspectionException $exception): JsonResponse
    {
        $member = $this->members->resolve($request)->user();
        abort_unless((int) $exception->inspection->actor_user_id === (int) $member->id, 403);
        $result = $this->exceptions->submitRevision($exception->id, $member, $request->only(['reason', 'value']));

        return $this->receipt($result);
    }

    public function reconcile(Request $request, ApparatusInspectionException $exception): JsonResponse
    {
        $reviewer = $this->members->resolve($request)->user();
        $result = $this->exceptions->reconcile($exception->id, $reviewer, $request->only([
            'action', 'reason', 'value', 'expected_current_value', 'operational_impact', 'service_ticket',
        ]));

        return $this->receipt($result);
    }

    private function receipt(ApparatusInspectionException $exception): JsonResponse
    {
        return response()->json(['id' => $exception->id, 'status' => $exception->status])
            ->header('Cache-Control', 'no-store, private');
    }
}
