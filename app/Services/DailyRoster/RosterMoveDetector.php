<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterMoveBaseline;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\User;
use App\Notifications\RosterMoveNotification;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDispatchService;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tells members on duty when TeleStaff changes the rest of their shift (docs/superpowers/specs/2026-11-01-roster-move-alerts-design.md).
 *
 * Compares the roster now showing with the one last compared for the current duty date, then moves that
 * baseline, all with its row locked. So a repeat run sends nothing, exports handled out of order cannot
 * report an old move, and a change undone before the check runs reaches nobody. The first roster of a
 * duty day only sets the baseline, and other dates are never compared, so nothing goes out beforehand.
 */
final class RosterMoveDetector
{
    private const MANUAL_REASON = 'manual_transcription';

    private const BASELINE_KEEP_DAYS = 30;

    /** A verified roster reads back whole. Anything earlier could still be held and never reach members. */
    private const READ_BACK = [RosterImport::STATE_VERIFIED, RosterImport::STATE_CLEANED];

    public function __construct(
        private readonly DutyCalendar $calendar,
        private readonly RosterMoveComparer $comparer,
        private readonly NotificationDispatchService $notifications,
        private readonly RosterStateRecorder $recorder,
        private readonly RosterAlerts $alerts,
    ) {}

    /** @return int How many members were alerted */
    public function run(): int
    {
        if (config('daily_roster.mode') !== 'publish') {
            return 0;
        }

        $now = CarbonImmutable::now('UTC');
        $dutyDate = $this->calendar->dutyDate($now)->format('Y-m-d');
        $pointer = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->first();

        // Runs every minute, so the usual answer has to be two small queries.
        if ($pointer?->roster_snapshot_id === null
            || (int) RosterMoveBaseline::query()->whereDate('duty_date', $dutyDate)->value('roster_snapshot_id') === (int) $pointer->roster_snapshot_id
            || ! $this->hasBeenReadBack($pointer)) {
            return 0;
        }

        [$alerted, $tooMany] = DB::transaction(fn (): array => $this->advance($dutyDate, $now));

        if ($tooMany) {
            $this->alerts->send('move_alerts_held', $dutyDate);
        }

        return $alerted;
    }

    /** @return array{0: int, 1: bool} Members alerted, and whether too many changed at once */
    private function advance(string $dutyDate, CarbonImmutable $now): array
    {
        if (RosterMoveBaseline::query()->insertOrIgnore(['duty_date' => $dutyDate, 'created_at' => $now, 'updated_at' => $now]) > 0) {
            RosterMoveBaseline::query()->whereDate('duty_date', '<', $now->subDays(self::BASELINE_KEEP_DAYS)->format('Y-m-d'))->delete();
        }
        $baseline = RosterMoveBaseline::query()->whereDate('duty_date', $dutyDate)->lockForUpdate()->firstOrFail();
        $pointer = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->firstOrFail();
        $fromId = $baseline->roster_snapshot_id === null ? null : (int) $baseline->roster_snapshot_id;
        $toId = (int) $pointer->roster_snapshot_id;

        if ($fromId === $toId) {
            return [0, false];
        }

        RosterMoveBaseline::query()->whereDate('duty_date', $dutyDate)->update(['roster_snapshot_id' => $toId, 'updated_at' => $now]);

        $from = $fromId === null ? null : RosterSnapshot::query()->find($fromId);
        $to = RosterSnapshot::query()->find($toId);
        if ($from === null || $to === null || ! config('daily_roster.move_alerts.enabled', true) || $this->involvesManualRoster($from, $to)) {
            return [0, false];
        }

        $moves = $this->comparer->compare($from, $to, $now);
        if ($moves === []) {
            return [0, false];
        }

        $context = ['from_snapshot_id' => $fromId, 'to_snapshot_id' => $toId, 'changed' => count($moves)];
        if (count($moves) > (int) config('daily_roster.move_alerts.max_members', 20)) {
            $this->recorder->appendEvent(null, RosterStateRecorder::ACTION_MOVE_ALERTS_HELD, context: $context, snapshotId: $toId, dutyDate: $dutyDate);

            return [0, true];
        }

        $alerted = $this->notify($moves, $dutyDate, $fromId, $toId, (string) ($pointer->content_changed_at ?? $pointer->updated_at)?->getTimestamp());
        $this->recorder->appendEvent(null, RosterStateRecorder::ACTION_MOVE_ALERTS_SENT, context: $context + ['alerted' => $alerted], snapshotId: $toId, dutyDate: $dutyDate);

        return [$alerted, false];
    }

    /**
     * Queued after commit. A person moved back and forth is told each time, so the key names this change.
     *
     * @param  array<string, RosterMove>  $moves
     */
    private function notify(array $moves, string $dutyDate, int $fromId, int $toId, string $changedAt): int
    {
        $shiftEndsAt = $this->calendar->window($dutyDate)[1]->getTimestamp();
        $alerted = 0;
        foreach (User::query()->whereIn('employee_id', array_map('strval', array_keys($moves)))->whereNotNull('employee_profile_id')->get() as $user) {
            $move = $moves[(string) $user->employee_id] ?? null;
            if ($move === null) {
                continue;
            }
            $eventKey = NotificationDispatchService::eventKey(NotificationCategoryRegistry::ROSTER_MOVES, 'duty_date', $dutyDate, "{$user->employee_id}:{$fromId}:{$toId}:{$changedAt}");
            $this->notifications->dispatch(NotificationCategoryRegistry::ROSTER_MOVES, new RosterMoveNotification($move->title(), $move->body(), $eventKey, $shiftEndsAt), [$user], $eventKey);
            $alerted++;
        }

        return $alerted;
    }

    private function hasBeenReadBack(RosterActiveVersion $pointer): bool
    {
        if ($pointer->pinned_at !== null) {
            return true; // An administrator chose this version.
        }

        $state = RosterSnapshot::query()->whereKey($pointer->roster_snapshot_id)->first()?->import?->state;

        return in_array($state, self::READ_BACK, true);
    }

    /**
     * A roster typed from screenshots can differ from TeleStaff's by a slip of the keyboard, and telling
     * a member they were moved because of one would teach them to ignore the real thing.
     */
    private function involvesManualRoster(RosterSnapshot $from, RosterSnapshot $to): bool
    {
        foreach ([$from, $to] as $snapshot) {
            if (in_array(self::MANUAL_REASON, (array) ($snapshot->import?->classification['reasons'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }
}
