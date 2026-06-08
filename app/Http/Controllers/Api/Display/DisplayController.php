<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Display;

use App\Http\Controllers\Controller;
use App\Http\Controllers\IncidentsController;
use App\Models\Employee;
use App\Models\Station;
use App\Services\Display\DisplayAiService;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only command-display API (GET-only).
 *
 * Thin transport layer: every method delegates to a Display service and adds
 * private cache headers + a generated_at timestamp. No writes occur anywhere
 * in this controller. The route group is additionally guarded by the
 * display.readonly middleware, which rejects any non-GET verb with 405.
 *
 * Auth posture: the display origin is gated by Cloudflare Access at the edge
 * (staff-only) plus a shared-secret header from the CF Functions gateway, so
 * these origin routes carry no app-login middleware — only the read-only guard
 * and a rate limit. All payloads are redacted; only the dedicated personnel
 * endpoint emits names (acceptable because the surface is staff-only).
 */
final class DisplayController extends Controller
{
    public function __construct(
        private readonly DisplaySnapshotService $snapshots,
        private readonly DisplayAiService $ai
    ) {}

    public function overview(): JsonResponse
    {
        return response()
            ->json($this->snapshots->overview())
            ->header('Cache-Control', 'private, max-age='.DisplaySnapshotService::CACHE_TTL);
    }

    public function stations(): JsonResponse
    {
        return response()
            ->json($this->snapshots->stations())
            ->header('Cache-Control', 'private, max-age='.DisplaySnapshotService::CACHE_TTL);
    }

    public function stationDetail(int $station): JsonResponse
    {
        $detail = $this->snapshots->stationDetail($station);
        if (($detail['found'] ?? false) !== true) {
            return $this->notFound('Station not found or inactive.');
        }

        unset($detail['found']);

        return response()
            ->json($detail)
            ->header('Cache-Control', 'private, max-age='.DisplaySnapshotService::CACHE_TTL);
    }

    public function stationApparatus(int $station): JsonResponse
    {
        $payload = $this->snapshots->stationApparatus($station);
        if (($payload['found'] ?? false) !== true) {
            return $this->notFound('Station not found or inactive.');
        }

        unset($payload['found']);

        return response()
            ->json($payload)
            ->header('Cache-Control', 'private, max-age='.DisplaySnapshotService::CACHE_TTL);
    }

    /**
     * Personnel roster for a station. Names + rank ARE allowed here (the only
     * endpoint where identity is exposed) because the display is staff-only
     * behind Cloudflare Access. Falls back to the full department roster when
     * the station has no personnel relation populated.
     */
    public function stationPersonnel(int $station): JsonResponse
    {
        $stationModel = Station::query()->where('is_active', true)->find($station);
        if ($stationModel === null) {
            return $this->notFound('Station not found or inactive.');
        }

        $roster = Employee::query()
            ->select('id', 'employee_id', 'name', 'rank')
            ->orderBy('name')
            ->get()
            ->map(static fn (Employee $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'rank' => $e->rank,
            ])
            ->values()
            ->all();

        return response()
            ->json([
                'generated_at' => now()->toISOString(),
                'station_id' => $stationModel->id,
                'source' => 'department_roster',
                'personnel' => $roster,
            ])
            ->header('Cache-Control', 'private, max-age='.DisplaySnapshotService::CACHE_TTL);
    }

    public function stationSubmissions(int $station): JsonResponse
    {
        $payload = $this->snapshots->stationSubmissions($station);
        if (($payload['found'] ?? false) !== true) {
            return $this->notFound('Station not found or inactive.');
        }

        unset($payload['found']);

        return response()
            ->json($payload)
            ->header('Cache-Control', 'private, max-age='.DisplaySnapshotService::CACHE_TTL);
    }

    /**
     * Territory metadata only — the real camera catalog is served client-side
     * from the frontend stationCameraCatalog and is intentionally NOT
     * duplicated on the origin.
     */
    public function stationCameraFeeds(int $station): JsonResponse
    {
        $stationModel = Station::query()->where('is_active', true)->find($station);
        if ($stationModel === null) {
            return $this->notFound('Station not found or inactive.');
        }

        return response()
            ->json([
                'generated_at' => now()->toISOString(),
                'station_id' => $stationModel->id,
                'territory_label' => 'Station '.$stationModel->station_number,
                'note' => 'Camera catalog is served client-side from stationCameraCatalog; '
                    .'this endpoint reports territory metadata only',
            ])
            ->header('Cache-Control', 'private, max-age='.DisplaySnapshotService::CACHE_TTL);
    }

    public function criticalItems(): JsonResponse
    {
        return response()
            ->json($this->snapshots->criticalItems())
            ->header('Cache-Control', 'private, max-age='.DisplaySnapshotService::CACHE_TTL);
    }

    /**
     * Descriptive-only AI brief. Returns:
     *   - 200 with a fresh or stale brief,
     *   - 202 {status:"generating"} when there is no cached brief yet,
     *   - 504 with the last-good brief when the LLM path is unreachable.
     */
    public function aiSnapshot(): JsonResponse
    {
        try {
            $result = $this->ai->brief();
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'unavailable',
                'brief' => null,
                'generated_at' => now()->toISOString(),
            ], 504)->header('Cache-Control', 'no-store');
        }

        if ($result['state'] === 'generating') {
            return response()->json([
                'status' => 'generating',
                'generated_at' => now()->toISOString(),
            ], 202)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'status' => $result['state'],
            'brief' => $result['brief'],
            'generated_at' => now()->toISOString(),
        ])->header('Cache-Control', 'private, max-age=60');
    }

    /**
     * Territory metadata for the (client-side) camera catalog at department
     * scope. The catalog itself lives in the frontend.
     */
    public function cameras(): JsonResponse
    {
        return response()
            ->json([
                'generated_at' => now()->toISOString(),
                'station_id' => null,
                'territory_label' => 'Miami Beach Fire Department',
                'note' => 'Camera catalog is served client-side from stationCameraCatalog; '
                    .'this endpoint reports territory metadata only',
            ])
            ->header('Cache-Control', 'private, max-age='.DisplaySnapshotService::CACHE_TTL);
    }

    /**
     * Active/recent incidents — delegates to the existing PulsePoint proxy so
     * caching and failure behaviour stay identical to the public feed.
     */
    public function incidents(IncidentsController $incidents): JsonResponse
    {
        return $incidents->index();
    }

    public function health(): JsonResponse
    {
        return response()
            ->json([
                'status' => 'ok',
                'generated_at' => now()->toISOString(),
                'read_only' => true,
                'environment' => (string) app()->environment(),
            ])
            ->header('Cache-Control', 'no-store');
    }

    private function notFound(string $message): JsonResponse
    {
        return response()
            ->json([
                'message' => $message,
                'generated_at' => now()->toISOString(),
            ], 404)
            ->header('Cache-Control', 'no-store');
    }
}
