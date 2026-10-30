<?php

declare(strict_types=1);

namespace App\Health;

use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\RosterFreshness;
use App\Services\DailyRoster\RosterFreshnessResolver;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/** Surfaces roster import trouble on the System Health page. Counts and states only, never member data. */
final class RosterImportCheck extends Check
{
    public function run(): Result
    {
        $result = Result::make();
        if (config('daily_roster.mode') !== 'publish') {
            return $result->ok('Publishing off')->shortSummary((string) config('daily_roster.mode'));
        }

        $status = app(RosterFreshnessResolver::class)->status();
        $stuck = RosterImport::query()
            ->whereIn('state', [RosterImport::STATE_CLASSIFIED, RosterImport::STATE_QUEUED, RosterImport::STATE_PARSING])
            ->where('state_changed_at', '<', now()->subMinutes(15))
            ->count();
        $cleanupFailed = RosterImport::query()->where('cleanup_state', RosterImport::CLEANUP_FAILED)->count();

        $result->meta(['state' => $status->state, 'stuck' => $stuck, 'cleanup_failed' => $cleanupFailed]);

        if ($stuck > 0 || $cleanupFailed > 0) {
            return $result->failed('Roster imports need attention')->shortSummary("{$stuck} stuck, {$cleanupFailed} cleanup failed");
        }

        return match ($status->state) {
            RosterFreshness::DELAYED => $result->warning('Today\'s roster is late')->shortSummary('Late'),
            RosterFreshness::NEEDS_REVIEW => $result->warning('A roster export is held for review')->shortSummary('Held'),
            default => $result->ok()->shortSummary($status->state),
        };
    }
}
