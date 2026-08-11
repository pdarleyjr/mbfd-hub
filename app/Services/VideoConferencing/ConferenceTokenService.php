<?php

namespace App\Services\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Data\VideoConferencing\IssuedConferenceToken;
use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Exceptions\VideoConferencing\EndpointInUseException;
use App\Models\Employee;
use App\Models\VideoConferenceParticipation;
use App\Models\VideoConferenceSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ConferenceTokenService
{
    public function __construct(
        private readonly ConferenceProvider $provider,
        private readonly ConferenceIdentityService $identities,
    ) {}

    /** @return array{issued: IssuedConferenceToken, participation: VideoConferenceParticipation} */
    public function issue(
        VideoConferenceSession $session,
        Employee $employee,
        ConferenceJoinRole $role,
        bool $confirmedTakeover = false,
    ): array {
        abort_if($session->active_key === null || $session->ended_at !== null, 410, 'This conference has ended.');
        abort_if($session->provisioned_at === null, 503, 'The conference room is not ready.');
        $this->authorizeRoleForSession($session, $role);
        $identity = $this->identities->identity($role);
        $activeKey = $session->id.':'.$identity;

        if ($role->fixedIdentity() !== null && $this->provider->participantExists($session->livekit_room_name, $identity)) {
            if (! $confirmedTakeover) {
                throw new EndpointInUseException($role->label().' is already connected.');
            }
            $this->provider->removeParticipant($session->livekit_room_name, $identity);
        }

        if ($confirmedTakeover) {
            VideoConferenceParticipation::query()
                ->where('active_identity_key', $activeKey)
                ->update(['active_identity_key' => null, 'left_at' => now()]);
        }

        $displayName = $role === ConferenceJoinRole::Self
            ? $this->identities->displayName($employee)
            : $role->label();

        try {
            $participation = DB::transaction(fn (): VideoConferenceParticipation => VideoConferenceParticipation::query()->create([
                'session_id' => $session->id,
                'employee_id' => $employee->getKey(),
                'participant_identity' => $identity,
                'active_identity_key' => $activeKey,
                'join_as' => $role,
                'display_name' => $displayName,
                'token_issued_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            throw new EndpointInUseException($role->label().' is already being connected.', 0, $exception);
        }

        try {
            $issued = $this->provider->issueToken(
                roomName: $session->livekit_room_name,
                identity: $identity,
                displayName: $displayName,
                metadata: json_encode([
                    'session_id' => $session->id,
                    'participation_id' => $participation->id,
                    'join_as' => $role->value,
                ], JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $exception) {
            $participation->delete();
            throw $exception;
        }

        return ['issued' => $issued, 'participation' => $participation];
    }

    public function leave(VideoConferenceParticipation $participation, Employee $employee): void
    {
        abort_unless($participation->employee_id === $employee->getKey(), 403);
        $participation->forceFill([
            'active_identity_key' => null,
            'left_at' => now(),
        ])->save();
    }

    private function authorizeRoleForSession(VideoConferenceSession $session, ConferenceJoinRole $role): void
    {
        if ($session->type->value === 'lineup') {
            $date = CarbonImmutable::now((string) config('video-conferencing.timezone'))->toDateString();
            abort_unless($session->logical_key === 'lineup:'.$date, 410, 'This Morning Lineup has ended.');

            return;
        }

        abort_unless(
            in_array($role->value, ['300', $session->target_station], true),
            403,
            'Direct calls are restricted to 300 and the selected station.',
        );
    }
}
