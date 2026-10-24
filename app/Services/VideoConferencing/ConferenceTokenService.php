<?php

namespace App\Services\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Data\VideoConferencing\IssuedConferenceToken;
use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Exceptions\VideoConferencing\ConferenceRoomFullException;
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
        private readonly ConferenceSeatService $seats,
        private readonly LineupTimeLimit $timeLimit,
    ) {}

    /** @return array{issued: IssuedConferenceToken, participation: VideoConferenceParticipation} */
    public function issue(
        VideoConferenceSession $session,
        ?Employee $employee,
        ConferenceJoinRole $role,
        bool $confirmedTakeover = false,
        ?string $launchContext = null,
    ): array {
        abort_if($session->active_key === null || $session->ended_at !== null, 410, 'This conference has ended.');
        abort_if($session->provisioned_at === null, 503, 'The conference room is not ready.');
        abort_if(
            in_array($role, [ConferenceJoinRole::Self, ConferenceJoinRole::Command], true) && $employee === null,
            401,
            'Employee authentication is required for this conference role.',
        );
        $this->authorizeRoleForSession($session, $role);
        $identity = $this->identities->identity($role, $employee);
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

        $displayName = match ($role) {
            ConferenceJoinRole::Self => $this->identities->displayName($employee),
            ConferenceJoinRole::Command => $this->identities->displayName($employee).' — 300',
            default => $role->label(),
        };
        if ($role->isStation() && $employee !== null) {
            $displayName = $this->identities->displayName($employee).' — '.$role->label();
        }

        try {
            $participation = DB::transaction(function () use ($session, $employee, $role, $identity, $activeKey, $displayName, $launchContext): VideoConferenceParticipation {
                if ($role === ConferenceJoinRole::Self) {
                    // Seats are counted under a row lock on the session: two members racing
                    // for the last seat serialize here, so the second one sees the first one's
                    // participation and is refused before any row or token exists. (SQLite, used
                    // by tests, serializes writers itself and ignores the lock hint.)
                    VideoConferenceSession::query()->whereKey($session->getKey())->lockForUpdate()->first();
                    if ($this->seats->isFull($session, $identity)) {
                        throw new ConferenceRoomFullException('Morning Lineup is full right now. You can watch for a seat to open; try again in a moment.');
                    }
                }

                return VideoConferenceParticipation::query()->create([
                    'session_id' => $session->id,
                    'employee_id' => $employee?->getKey(),
                    'participant_identity' => $identity,
                    'active_identity_key' => $activeKey,
                    'join_as' => $role,
                    'display_name' => $displayName,
                    'launch_context_hash' => $launchContext === null
                        ? null
                        : hash_hmac('sha256', $launchContext, (string) config('app.key')),
                    'token_issued_at' => now(),
                ]);
            });
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
            // A lineup belongs to the Eastern day it started on and stays joinable until its own
            // time limit, even past midnight, so today's and yesterday's keys are both valid; the
            // time limit (not the date) is what ends it.
            $now = CarbonImmutable::now((string) config('video-conferencing.timezone'));
            abort_unless(
                in_array($session->logical_key, ['lineup:'.$now->toDateString(), 'lineup:'.$now->subDay()->toDateString()], true)
                    && ! $this->timeLimit->hasExpired($session),
                410,
                'This Morning Lineup has ended.',
            );

            return;
        }

        abort_unless(
            in_array($role->value, ['300', $session->target_station], true),
            403,
            'Direct calls are restricted to 300 and the selected station.',
        );
    }
}
