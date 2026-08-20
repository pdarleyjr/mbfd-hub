<?php

namespace App\Services\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class ConferenceLineupReadinessService
{
    public function __construct(private readonly ConferenceLaunchContextService $launches) {}

    /** @return array<string, mixed> */
    public function markReady(
        ConferenceJoinRole $station,
        string $launchContext,
        bool $cameraReady,
        bool $microphoneReady,
        int|string|null $employeeId = null,
    ): array {
        abort_unless($station->isStation(), 422);
        abort_unless($cameraReady || $microphoneReady, 422, 'At least one media device must be ready.');
        $now = CarbonImmutable::now('UTC');
        $state = [
            'join_as' => $station->value,
            'label' => $station->label(),
            'ready' => true,
            'camera_ready' => $cameraReady,
            'microphone_ready' => $microphoneReady,
            'ready_at' => $now->toIso8601String(),
            'last_heartbeat_at' => $now->toIso8601String(),
            'launch_fingerprint' => $this->launches->fingerprint($launchContext),
            'employee_id' => $employeeId,
        ];

        $this->mutate(function (array $stations) use ($station, $state): array {
            $stations[$station->value] = $state;

            return $stations;
        });

        return $this->publicState($state);
    }

    /** @return array<string, mixed> */
    public function heartbeat(ConferenceJoinRole $station, string $launchContext): array
    {
        $fingerprint = $this->launches->fingerprint($launchContext);
        $state = null;
        $this->mutate(function (array $stations) use ($station, $fingerprint, &$state): array {
            $candidate = $stations[$station->value] ?? null;
            abort_unless(
                is_array($candidate) && hash_equals((string) ($candidate['launch_fingerprint'] ?? ''), $fingerprint),
                409,
                $station->label().' is no longer standing by from this browser.',
            );
            $candidate['last_heartbeat_at'] = CarbonImmutable::now('UTC')->toIso8601String();
            $stations[$station->value] = $candidate;
            $state = $candidate;

            return $stations;
        });

        return $this->publicState((array) $state);
    }

    /** @return array<string, mixed> */
    public function stationState(ConferenceJoinRole $station, string $launchContext): array
    {
        $state = $this->freshStations()[$station->value] ?? null;
        if (! is_array($state)
            || ! hash_equals((string) ($state['launch_fingerprint'] ?? ''), $this->launches->fingerprint($launchContext))) {
            return [
                'join_as' => $station->value,
                'label' => $station->label(),
                'ready' => false,
                'camera_ready' => false,
                'microphone_ready' => false,
                'ready_at' => null,
                'last_heartbeat_at' => null,
            ];
        }

        return $this->publicState($state);
    }

    public function assertReady(ConferenceJoinRole $station, string $launchContext): void
    {
        abort_unless(
            $this->stationState($station, $launchContext)['ready'] === true,
            409,
            'Complete the camera and microphone check before joining.',
        );
    }

    /** @return list<array<string, mixed>> */
    public function allStations(): array
    {
        $fresh = $this->freshStations();

        return collect(ConferenceJoinRole::stationRoles())
            ->map(fn (ConferenceJoinRole $role): array => isset($fresh[$role->value])
                ? $this->publicState($fresh[$role->value])
                : [
                    'join_as' => $role->value,
                    'label' => $role->label(),
                    'ready' => false,
                    'camera_ready' => false,
                    'microphone_ready' => false,
                    'ready_at' => null,
                    'last_heartbeat_at' => null,
                ])
            ->all();
    }

    public function clear(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function remove(ConferenceJoinRole $station, string $launchContext): void
    {
        $fingerprint = $this->launches->fingerprint($launchContext);
        $this->mutate(function (array $stations) use ($station, $fingerprint): array {
            $candidate = $stations[$station->value] ?? null;
            if (is_array($candidate)
                && hash_equals((string) ($candidate['launch_fingerprint'] ?? ''), $fingerprint)) {
                unset($stations[$station->value]);
            }

            return $stations;
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function freshStations(): array
    {
        $stations = Cache::get($this->cacheKey(), []);
        if (! is_array($stations)) {
            return [];
        }
        $cutoff = CarbonImmutable::now('UTC')->subSeconds(
            max(30, min(180, (int) config('video-conferencing.readiness.stale_after_seconds', 75))),
        );

        return collect($stations)
            ->filter(fn (mixed $state): bool => is_array($state)
                && is_string($state['last_heartbeat_at'] ?? null)
                && CarbonImmutable::parse($state['last_heartbeat_at'])->isAfter($cutoff))
            ->all();
    }

    /** @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $callback */
    private function mutate(callable $callback): void
    {
        Cache::lock($this->cacheKey().':lock', 5)->block(2, function () use ($callback): void {
            $stations = Cache::get($this->cacheKey(), []);
            Cache::put(
                $this->cacheKey(),
                $callback(is_array($stations) ? $stations : []),
                now()->addHours(2),
            );
        });
    }

    private function cacheKey(): string
    {
        $date = CarbonImmutable::now((string) config('video-conferencing.timezone'))->toDateString();

        return 'video-conferencing:lineup:'.$date.':readiness';
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function publicState(array $state): array
    {
        unset($state['launch_fingerprint'], $state['employee_id']);

        return $state;
    }
}
