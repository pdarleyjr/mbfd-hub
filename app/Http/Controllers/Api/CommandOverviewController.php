<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Services\DailyCheckoutComplianceService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Read-only operational overview of every active station for the 300 Command
 * profile. Aggregates are batched (a fixed number of queries regardless of the
 * station count) and the payload is allowlisted server-side: no personnel,
 * pricing, VIN, notes or admin-only fields are ever selected into the response.
 */
final class CommandOverviewController extends Controller
{
    public function __invoke(DailyCheckoutComplianceService $compliance): JsonResponse
    {
        $stations = Station::query()
            ->where('is_active', true)
            ->with('apparatuses')
            ->orderBy('station_number')
            ->orderBy('id')
            ->get();

        $apparatuses = $stations->flatMap(fn (Station $station): Collection => $this->apparatusesOf($station))->values();
        $apparatusIds = $apparatuses->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $stationIds = $stations->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $defectCounts = $apparatusIds === [] ? collect() : ApparatusDefect::query()
            ->unresolved()
            ->whereIn('apparatus_id', $apparatusIds)
            ->selectRaw('apparatus_id, count(*) as aggregate')
            ->groupBy('apparatus_id')
            ->pluck('aggregate', 'apparatus_id');

        $ticketCounts = $apparatusIds === [] ? collect() : ApparatusServiceTicket::query()
            ->open()
            ->whereIn('apparatus_id', $apparatusIds)
            ->selectRaw('apparatus_id, count(*) as aggregate')
            ->groupBy('apparatus_id')
            ->pluck('aggregate', 'apparatus_id');

        // Station-level count also includes tickets not tied to one apparatus.
        $stationTicketCounts = $stationIds === [] ? collect() : ApparatusServiceTicket::query()
            ->open()
            ->whereIn('station_id', $stationIds)
            ->selectRaw('station_id, count(*) as aggregate')
            ->groupBy('station_id')
            ->pluck('aggregate', 'station_id');

        $lastCheckouts = $apparatusIds === [] ? collect() : ApparatusInspection::query()
            ->whereIn('apparatus_id', $apparatusIds)
            ->whereNotNull('client_submission_id')
            ->whereNotNull('completed_at')
            ->where('review_status', 'approved')
            ->selectRaw('apparatus_id, max(completed_at) as last_completed_at')
            ->groupBy('apparatus_id')
            ->pluck('last_completed_at', 'apparatus_id');

        $summaries = $compliance->summariesForStations($stations);

        $payload = $stations->map(function (Station $station) use ($defectCounts, $ticketCounts, $stationTicketCounts, $lastCheckouts, $summaries): array {
            $summary = $summaries[(int) $station->id] ?? [];
            $states = collect($summary['matrix'] ?? [])->pluck('state', 'apparatus_id');

            $rows = $this->apparatusesOf($station)
                ->sortBy(fn (Apparatus $apparatus): string => (string) $apparatus->vehicle_number)
                ->map(fn (Apparatus $apparatus): array => [
                    'id' => (int) $apparatus->id,
                    'name' => $apparatus->designation ?: $apparatus->name ?: $apparatus->getAttribute('unit_id'),
                    'unit_id' => $apparatus->getAttribute('unit_id'),
                    'type' => strtolower((string) $apparatus->type),
                    'status' => $apparatus->getAttribute('status'),
                    'service_state' => $this->serviceState($apparatus->getAttribute('status')),
                    'open_defects' => (int) ($defectCounts[$apparatus->id] ?? 0),
                    'open_service_tickets' => (int) ($ticketCounts[$apparatus->id] ?? 0),
                    'daily_checkout_state' => $states[(int) $apparatus->id] ?? null,
                    'last_checkout_at' => $this->iso($lastCheckouts[$apparatus->id] ?? null),
                ])
                ->values();

            return [
                'id' => (int) $station->id,
                'station_number' => $station->station_number,
                'name' => $station->getRawOriginal('name') ?: $station->name,
                'is_active' => (bool) $station->is_active,
                'apparatus_count' => $rows->count(),
                'open_defects' => $rows->sum('open_defects'),
                'open_service_tickets' => (int) ($stationTicketCounts[$station->id] ?? 0),
                'last_checkout_at' => $rows->pluck('last_checkout_at')->filter()->max(),
                'daily_checkout' => [
                    'required_total' => $summary['required_total'] ?? 0,
                    'completed' => $summary['completed'] ?? 0,
                    'not_checked' => $summary['not_checked'] ?? 0,
                    'review_pending' => $summary['review_pending'] ?? 0,
                    'out_of_service' => $summary['out_of_service'] ?? 0,
                    'completion_percent' => $summary['completion_percent'] ?? null,
                ],
                'apparatuses' => $rows->all(),
            ];
        })->values();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'stations' => $payload->all(),
            'total' => $payload->count(),
        ]);
    }

    /** in_service | out_of_service | unknown (never inferred from a missing status). */
    /** @return Collection<int, Apparatus> */
    private function apparatusesOf(Station $station): Collection
    {
        $apparatuses = [];
        foreach ($station->apparatuses as $apparatus) {
            if ($apparatus instanceof Apparatus) {
                $apparatuses[] = $apparatus;
            }
        }

        return collect($apparatuses);
    }

    private function serviceState(mixed $status): string
    {
        $normalized = str_replace([' ', '-'], '_', strtolower(trim((string) $status)));

        return match (true) {
            in_array($normalized, ['out_of_service', 'oos', 'down', 'retired'], true) => 'out_of_service',
            in_array($normalized, ['in_service', 'active', 'available', 'ready'], true) => 'in_service',
            default => 'unknown',
        };
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof CarbonInterface ? $value : \Illuminate\Support\Carbon::parse($value, 'UTC'))->utc()->toIso8601String();
    }
}
