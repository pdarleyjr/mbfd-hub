<?php

namespace App\Services\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Models\Employee;

class ConferenceBootstrapFactory
{
    /** @return array<string, mixed> */
    public function make(
        string $entryMode,
        ConferenceJoinRole $role,
        ?string $launchContext = null,
        ?Employee $employee = null,
    ): array {
        if (in_array($role, [ConferenceJoinRole::Self, ConferenceJoinRole::Command], true) && $employee === null) {
            throw new \LogicException('An authenticated employee is required for this conference entry mode.');
        }
        $displayName = match ($role) {
            ConferenceJoinRole::Self => app(ConferenceIdentityService::class)->displayName($employee),
            ConferenceJoinRole::Command => app(ConferenceIdentityService::class)->displayName($employee).' — 300',
            default => $role->label(),
        };
        $reverb = (array) config('broadcasting.connections.reverb', []);
        $realtime = (array) config('video-conferencing.realtime', []);

        return [
            'entry_mode' => $entryMode,
            'join_as' => $role->value,
            'display_name' => $displayName,
            'launch_context' => $launchContext,
            'lineup_time' => config('video-conferencing.lineup_time'),
            'lineup_max_minutes' => max(5, min(30, (int) config('video-conferencing.lineup_max_minutes', 15))),
            'status_poll_ms' => max(3000, min(15000, (int) config('video-conferencing.readiness.poll_seconds', 5) * 1000)),
            'heartbeat_ms' => max(10000, min(60000, (int) config('video-conferencing.readiness.heartbeat_seconds', 20) * 1000)),
            'realtime' => [
                'key' => (string) ($reverb['key'] ?? ''),
                'host' => (string) ($realtime['host'] ?? parse_url((string) config('app.url'), PHP_URL_HOST)),
                'port' => (int) ($realtime['port'] ?? 443),
                'scheme' => (string) ($realtime['scheme'] ?? 'https'),
                'channel' => 'video-conferencing.lineup',
            ],
            'endpoints' => [
                'station_ready' => route('video-conferencing.api.lineup.ready'),
                'station_heartbeat' => route('video-conferencing.api.lineup.heartbeat'),
                'station_stand_down' => route('video-conferencing.api.lineup.stand-down'),
                'station_status' => route('video-conferencing.api.lineup.status'),
                'station_token' => route('video-conferencing.api.lineup.token'),
                'station_participation_base' => url('/video-conferencing/api/participations'),
                'command_authorize' => route('employee.video-conferencing.api.lineup.command.authorize'),
                'command_status' => route('employee.video-conferencing.api.lineup.command.status'),
                'command_start' => route('employee.video-conferencing.api.lineup.start'),
                'command_end' => route('employee.video-conferencing.api.lineup.end'),
                'command_direct' => route('employee.video-conferencing.api.direct.start'),
                'sessions' => route('employee.video-conferencing.api.sessions'),
                'api_base' => url('/employee/video-conferencing/api'),
                'connectivity_failures' => route('employee.video-conferencing.api.connectivity-failures'),
            ],
            'csrf_token' => csrf_token(),
        ];
    }
}
