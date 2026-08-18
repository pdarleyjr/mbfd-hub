<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\PublicApparatusServiceTicketResource;
use App\Models\Apparatus;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicApparatusServiceTicketController extends Controller
{
    public function stationIndex(Request $request, Station $station): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'in:open,all'],
            'status' => ['nullable', Rule::in(ApparatusServiceTicketStatus::values())],
            'category' => ['nullable', Rule::in(ApparatusServiceTicketCategory::values())],
            'apparatus_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $records = $station->apparatusServiceTickets()
            ->select([
                'id', 'ticket_number', 'apparatus_id', 'station_id', 'unit_designation_snapshot',
                'origin', 'category', 'service_type', 'title', 'priority', 'status', 'scheduled_for',
                'scheduled_location', 'expected_return_at', 'current_public_response', 'created_at', 'updated_at',
            ])
            ->with(['updates' => fn ($query) => $query
                ->select('id', 'apparatus_service_ticket_id', 'status', 'public_note', 'scheduled_for', 'created_at')])
            ->when(($validated['scope'] ?? 'open') === 'open', fn ($query) => $query->open())
            ->when(filled($validated['status'] ?? null), fn ($query) => $query->where('status', $validated['status']))
            ->when(filled($validated['category'] ?? null), fn ($query) => $query->where('category', $validated['category']))
            ->when(filled($validated['apparatus_id'] ?? null), fn ($query) => $query->where('apparatus_id', $validated['apparatus_id']))
            ->latest('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return PublicApparatusServiceTicketResource::collection($records)
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    public function apparatusNotices(Apparatus $apparatus): JsonResponse
    {
        $records = $apparatus->serviceTickets()
            ->select([
                'id', 'ticket_number', 'apparatus_id', 'station_id', 'unit_designation_snapshot',
                'origin', 'category', 'service_type', 'title', 'priority', 'status', 'scheduled_for',
                'scheduled_location', 'expected_return_at', 'current_public_response', 'created_at', 'updated_at',
            ])
            ->open()
            ->latest('created_at')
            ->limit(10)
            ->get();

        return PublicApparatusServiceTicketResource::collection($records)
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }
}
