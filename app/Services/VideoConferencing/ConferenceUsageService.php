<?php

namespace App\Services\VideoConferencing;

use App\Models\VideoConferenceParticipation;
use Carbon\CarbonImmutable;

class ConferenceUsageService
{
    /** @return array<string, int|float|string> */
    public function monthlyEstimate(): array
    {
        $month = CarbonImmutable::now('UTC')->startOfMonth();
        $participations = VideoConferenceParticipation::query()
            ->where('token_issued_at', '>=', $month)
            ->get();
        $now = CarbonImmutable::now('UTC');
        $participantSeconds = $participations->sum(function (VideoConferenceParticipation $participation) use ($now): int {
            if ($participation->joined_at === null) {
                return 0;
            }
            $ended = $participation->left_at === null
                ? $now
                : CarbonImmutable::instance($participation->left_at);

            return max(0, CarbonImmutable::instance($participation->joined_at)->diffInSeconds($ended));
        });
        $downstreamBytes = (int) $participations->sum('downstream_bytes');
        $downstreamGb = round($downstreamBytes / 1_000_000_000, 3);
        $thresholds = [
            'information' => (int) config('video-conferencing.usage.information_gb', 30),
            'warning' => (int) config('video-conferencing.usage.warning_gb', 35),
            'conservation' => (int) config('video-conferencing.usage.conservation_gb', 40),
            'aggressive' => (int) config('video-conferencing.usage.aggressive_gb', 45),
        ];
        if ($thresholds !== collect($thresholds)->sort()->all() || $thresholds['information'] < 1) {
            throw new \LogicException('Conference usage thresholds must be positive and ascending.');
        }
        $band = 'normal';
        foreach ($thresholds as $candidate => $threshold) {
            if ($downstreamGb >= $threshold) {
                $band = $candidate;
            }
        }

        return [
            'month' => $month->format('Y-m'),
            'participant_minutes_estimated' => round($participantSeconds / 60, 1),
            'downstream_bytes_estimated' => $downstreamBytes,
            'downstream_gb_estimated' => $downstreamGb,
            'downstream_allowance_gb' => 50,
            'band' => $band,
            'estimate_label' => 'Estimated from MBFD participation and browser RTC stats; the LiveKit dashboard is authoritative.',
        ];
    }
}
