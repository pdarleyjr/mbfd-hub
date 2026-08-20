<?php

namespace App\Services\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Models\Employee;
use App\Models\VideoConferenceParticipation;

class ConferenceParticipationService
{
    /** @param array{downstream_bytes: int, packets_received: int, packets_lost: int, jitter_ms: int} $stats */
    public function recordStats(VideoConferenceParticipation $participation, array $stats): void
    {
        abort_if($participation->left_at !== null, 410, 'This conference participation has ended.');
        $participation->forceFill([
            'joined_at' => $participation->joined_at ?? now(),
            'downstream_bytes' => max((int) $participation->downstream_bytes, $stats['downstream_bytes']),
            'packets_received' => max((int) $participation->packets_received, $stats['packets_received']),
            'packets_lost' => max((int) $participation->packets_lost, $stats['packets_lost']),
            'jitter_ms' => $stats['jitter_ms'],
            'stats_sampled_at' => now(),
        ])->save();
    }

    public function leave(VideoConferenceParticipation $participation): void
    {
        if ($participation->left_at !== null) {
            return;
        }
        $participation->forceFill([
            'active_identity_key' => null,
            'left_at' => now(),
        ])->save();
    }

    public function assertEmployee(VideoConferenceParticipation $participation, Employee $employee): void
    {
        abort_unless($participation->employee_id === $employee->getKey(), 403);
    }

    public function assertStation(
        VideoConferenceParticipation $participation,
        ConferenceJoinRole $station,
        string $launchContext,
    ): void {
        $expected = hash_hmac('sha256', $launchContext, (string) config('app.key'));
        abort_unless(
            $station->isStation()
                && $participation->join_as === $station
                && is_string($participation->launch_context_hash)
                && hash_equals($participation->launch_context_hash, $expected),
            403,
        );
    }
}
