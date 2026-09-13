<?php

declare(strict_types=1);

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
            ->where(function ($query) use ($month): void {
                $query->where('token_issued_at', '>=', $month)
                    ->orWhere(function ($ongoing) use ($month): void {
                        $ongoing->whereNotNull('joined_at')->where(function ($ended) use ($month): void {
                            $ended->whereNull('left_at')->orWhere('left_at', '>=', $month);
                        });
                    });
            })
            ->get();
        $now = CarbonImmutable::now('UTC');
        $participantSeconds = $participations->sum(function (VideoConferenceParticipation $participation) use ($now, $month): int {
            if ($participation->joined_at === null) {
                return 0;
            }
            $ended = $participation->left_at === null
                ? $now
                : CarbonImmutable::instance($participation->left_at);

            $started = CarbonImmutable::instance($participation->joined_at)->max($month);

            return (int) max(0, $started->diffInSeconds($ended));
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

        $minutes = round($participantSeconds / 60, 1);
        $minutesAllowance = max(0, (int) config('video-conferencing.usage.webrtc_minutes_allowance', 5000));
        $downstreamAllowance = max(0, (int) config('video-conferencing.usage.downstream_allowance_gb', 50));

        return [
            'month' => $month->format('Y-m'),
            'participant_minutes_estimated' => $minutes,
            'participant_minutes_allowance' => $minutesAllowance,
            'participant_minutes_remaining' => max(0, $minutesAllowance - $minutes),
            'resets_at' => $month->addMonth()->toIso8601String(),
            'downstream_bytes_estimated' => $downstreamBytes,
            'downstream_gb_estimated' => $downstreamGb,
            'downstream_allowance_gb' => $downstreamAllowance,
            'downstream_gb_remaining' => max(0, round($downstreamAllowance - $downstreamGb, 3)),
            'band' => $band,
            'estimate_label' => 'Estimated from MBFD participation and browser RTC stats; the LiveKit dashboard is authoritative.',
        ];
    }
}
