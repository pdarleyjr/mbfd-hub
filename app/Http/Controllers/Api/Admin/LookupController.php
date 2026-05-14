<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\Employee;
use App\Models\Station;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin Lookup endpoints.
 *
 * Powers the desktop-PWA Dexie prefetch (resources/js/admin-pwa/prefetch.ts)
 * and any future admin-side typeahead UIs. Returns slim, read-only arrays
 * of records with a stable {id, label, ...extras} shape so the client never
 * needs to call back to the full Filament resource APIs for filter/typeahead
 * work.
 *
 * Each method:
 *   - Requires auth (admin panel cookie via 'web', NOT auth:sanctum, because
 *     the prefetch fires from the installed PWA which uses session cookies
 *     identical to the admin browser session)
 *   - Requires the caller to have super_admin or admin role
 *   - Caches the payload for 5 minutes per role per query — admins assigning
 *     fleet/stations don't need second-by-second freshness
 *   - Supports ?q=<term> for cheap server-side substring filtering
 *   - Caps the result to LOOKUP_LIMIT rows so the IndexedDB cache stays small
 */
class LookupController extends Controller
{
    private const LOOKUP_LIMIT = 500;
    private const CACHE_TTL_SECONDS = 300;

    public function stations(Request $request): JsonResponse
    {
        return $this->respond('stations', $request, function (?string $q) {
            $query = Station::query()
                ->select(['id', 'station_number', 'name', 'address', 'is_active'])
                ->orderBy('station_number');

            if ($q !== null && $q !== '') {
                $query->where(function (Builder $b) use ($q) {
                    $b->where('station_number', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('address', 'like', "%{$q}%");
                });
            }

            return $query
                ->limit(self::LOOKUP_LIMIT)
                ->get()
                ->map(fn (Station $s): array => [
                    'id' => (string) $s->id,
                    'label' => trim(
                        ($s->station_number ? "Station {$s->station_number}" : '')
                        .($s->name ? ' — '.$s->name : '')
                    ) ?: "Station #{$s->id}",
                    'station_number' => $s->station_number,
                    'name' => $s->name,
                    'address' => $s->address,
                    'is_active' => (bool) $s->is_active,
                ])
                ->all();
        });
    }

    public function apparatus(Request $request): JsonResponse
    {
        return $this->respond('apparatus', $request, function (?string $q) {
            $query = Apparatus::query()
                ->select(['id', 'unit_id', 'name', 'vehicle_number', 'designation', 'type', 'status', 'station_id'])
                ->orderBy('unit_id');

            if ($q !== null && $q !== '') {
                $query->where(function (Builder $b) use ($q) {
                    $b->where('unit_id', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('vehicle_number', 'like', "%{$q}%")
                        ->orWhere('designation', 'like', "%{$q}%");
                });
            }

            return $query
                ->limit(self::LOOKUP_LIMIT)
                ->get()
                ->map(fn (Apparatus $a): array => [
                    'id' => (string) $a->id,
                    'label' => trim(
                        ($a->unit_id ?: $a->vehicle_number ?: "Unit #{$a->id}")
                        .($a->name ? ' — '.$a->name : '')
                    ),
                    'unit_id' => $a->unit_id,
                    'vehicle_number' => $a->vehicle_number,
                    'designation' => $a->designation,
                    'type' => $a->type,
                    'status' => $a->status,
                    'station_id' => $a->station_id,
                ])
                ->all();
        });
    }

    public function personnel(Request $request): JsonResponse
    {
        return $this->respond('personnel', $request, function (?string $q) {
            // The 'personnel' table is loaded externally (Snipe-IT integration)
            // and may not exist in fresh installs. We fall back to the
            // Employee model (the in-codebase "people who do the work" entity).
            // If neither is populated, we return an empty array (200) rather
            // than 404 so the prefetch fails silently.
            if (Schema::hasTable('personnel')) {
                return $this->queryPersonnelTable($q);
            }

            $query = Employee::query()
                ->select(['id', 'employee_id', 'name', 'rank'])
                ->orderBy('name');

            if ($q !== null && $q !== '') {
                $query->where(function (Builder $b) use ($q) {
                    $b->where('employee_id', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('rank', 'like', "%{$q}%");
                });
            }

            return $query
                ->limit(self::LOOKUP_LIMIT)
                ->get()
                ->map(fn (Employee $e): array => [
                    'id' => (string) $e->id,
                    'label' => trim(($e->rank ? "{$e->rank} " : '').($e->name ?: $e->employee_id ?: "#{$e->id}")),
                    'employee_id' => $e->employee_id,
                    'name' => $e->name,
                    'rank' => $e->rank,
                ])
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryPersonnelTable(?string $q): array
    {
        $builder = DB::table('personnel')
            ->select(['id', 'name', 'rank', 'assignment', 'status'])
            ->orderBy('name');

        if ($q !== null && $q !== '') {
            $builder->where(function ($b) use ($q) {
                $b->where('name', 'like', "%{$q}%")
                    ->orWhere('rank', 'like', "%{$q}%")
                    ->orWhere('assignment', 'like', "%{$q}%");
            });
        }

        return $builder
            ->limit(self::LOOKUP_LIMIT)
            ->get()
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'label' => trim((($row->rank ?? '') ? "{$row->rank} " : '').($row->name ?? "#{$row->id}")),
                'name' => $row->name ?? null,
                'rank' => $row->rank ?? null,
                'assignment' => $row->assignment ?? null,
                'status' => $row->status ?? null,
            ])
            ->all();
    }

    /**
     * Common envelope + caching + auth gate.
     */
    private function respond(string $key, Request $request, \Closure $resolver): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        if (! ($user->hasAnyRole(['super_admin', 'admin']) ?? false)) {
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        $q = $request->query('q');
        if (is_array($q)) {
            $q = null;
        }
        $q = is_string($q) ? trim($q) : null;

        // Cache key: lookup + role-suffixed (admins see same data, super_admin may diverge later)
        // Keep cache short so role grants propagate within 5 min.
        $cacheKey = sprintf('admin.lookups.%s.%s', $key, md5((string) $q));

        $rows = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): array => $resolver($q)
        );

        return response()->json([
            'data' => $rows,
            'meta' => [
                'count' => count($rows),
                'limit' => self::LOOKUP_LIMIT,
                'cache_ttl' => self::CACHE_TTL_SECONDS,
                'truncated' => count($rows) >= self::LOOKUP_LIMIT,
            ],
        ]);
    }
}
