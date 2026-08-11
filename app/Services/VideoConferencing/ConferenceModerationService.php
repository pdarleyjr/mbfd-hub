<?php

namespace App\Services\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Models\Employee;
use App\Models\VideoConferenceParticipation;
use App\Models\VideoConferenceSession;

class ConferenceModerationService
{
    public function __construct(private readonly ConferenceProvider $provider) {}

    /** @return list<string> */
    public function muteAllStations(VideoConferenceSession $session, Employee $employee): array
    {
        $this->authorizeCommand($session, $employee);
        $muted = [];
        foreach (ConferenceJoinRole::stationRoles() as $role) {
            $this->provider->muteMicrophone($session->livekit_room_name, $role->fixedIdentity());
            $muted[] = $role->value;
        }

        return $muted;
    }

    /** @return array{identity: string, rpc_required: bool, method: string, payload: array{enabled: bool}} */
    public function setStationMicrophone(
        VideoConferenceSession $session,
        Employee $employee,
        ConferenceJoinRole $station,
        bool $enabled,
    ): array {
        abort_unless($station->isStation(), 422, 'A supported station is required.');
        $this->authorizeCommand($session, $employee);

        if (! $enabled) {
            $this->provider->muteMicrophone($session->livekit_room_name, $station->fixedIdentity());
        }

        return [
            'identity' => $station->fixedIdentity(),
            // The server enforces mute first. RPC then lets the station apply
            // and clearly display the same state on its own device. Enabling
            // is RPC-only because remote unmute remains disabled in LiveKit.
            'rpc_required' => true,
            'method' => 'mbfd.stationMic',
            'payload' => ['enabled' => $enabled],
        ];
    }

    private function authorizeCommand(VideoConferenceSession $session, Employee $employee): void
    {
        $activeCommand = VideoConferenceParticipation::query()
            ->where('session_id', $session->id)
            ->where('employee_id', $employee->getKey())
            ->where('join_as', ConferenceJoinRole::Command->value)
            ->whereNotNull('active_identity_key')
            ->exists();

        abort_unless($activeCommand, 403, 'Join as 300 before using conference controls.');
        abort_unless(
            $this->provider->participantExists($session->livekit_room_name, ConferenceJoinRole::Command->fixedIdentity()),
            403,
            'The 300 endpoint is not connected.',
        );
    }
}
