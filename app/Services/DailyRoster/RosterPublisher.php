<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies the frozen publish decision to one validated export, in one transaction with the date's
 * pointer locked. Readers follow the pointer to an immutable snapshot, so they see the previous whole
 * roster or the new whole roster, and a failure anywhere leaves the pointer where it was.
 */
final class RosterPublisher
{
    public const HOLD_PINNED = 'pinned';

    public const HOLD_GENERATED_CONFLICT = 'generated_time_conflict';

    public const HOLD_GENERATED_MISSING = 'generated_missing';

    private const INSERT_CHUNK = 100;

    private const DEADLOCK_ATTEMPTS = 3;

    private const HELD_SUPERSEDE_LIMIT = 50;

    public function __construct(private readonly RosterStateRecorder $recorder) {}

    public function publish(RosterImport $import, TeleStaffRosterReport $report): void
    {
        $holdCode = DB::transaction(fn (): ?string => $this->apply($import, $report), self::DEADLOCK_ATTEMPTS);

        // A pin is an administrator's own decision, so the exports it holds are not an alert.
        if ($holdCode !== null && $holdCode !== self::HOLD_PINNED) {
            report(new RosterPipelineException($holdCode));
        }
    }

    public static function sectionRole(string $unit): ?string
    {
        $unit = trim($unit);
        foreach ((array) config('daily_roster.section_roles', []) as $role => $label) {
            if (trim((string) $label) === $unit) {
                return (string) $role;
            }
        }

        return null;
    }

    /** @return string|null The hold code when the export was held */
    private function apply(RosterImport $import, TeleStaffRosterReport $report): ?string
    {
        $dutyDate = $report->dutyDate;
        $pointer = $this->lockPointer($dutyDate);
        $current = RosterImport::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail();
        if ($current->state !== RosterImport::STATE_VALIDATED) {
            return null;
        }

        $generatedAt = $report->generatedAt === null ? null : CarbonImmutable::parse($report->generatedAt)->utc();
        $hash = $report->normalizedSha256();
        $active = $pointer->roster_snapshot_id === null ? null : RosterSnapshot::query()->find($pointer->roster_snapshot_id);

        if ($pointer->pinned_at !== null) {
            return $this->hold($current, self::HOLD_PINNED);
        }
        if ($active !== null && hash_equals($active->normalized_sha256, $hash)) {
            $this->confirm($current, $pointer, $active, $generatedAt);

            return null;
        }
        if ($generatedAt === null && $active === null) {
            return $this->hold($current, self::HOLD_GENERATED_MISSING);
        }

        $existing = RosterSnapshot::query()->whereDate('duty_date', $dutyDate)->where('normalized_sha256', $hash)->first();
        $effective = $pointer->effective_generated_at;

        if ($generatedAt !== null && ($effective === null || $generatedAt->greaterThan($effective))) {
            $this->repoint($current, $pointer, $existing ?? $this->storeSnapshot($current, $report), $generatedAt);

            return null;
        }
        if ($generatedAt !== null && $generatedAt->equalTo($effective)) {
            return $this->hold($current, self::HOLD_GENERATED_CONFLICT);
        }

        $this->storeUnpointed($current, $pointer, $existing ?? $this->storeSnapshot($current, $report));

        return null;
    }

    /** The row exists for every date an export reaches, so concurrent first imports queue on one lock. */
    private function lockPointer(string $dutyDate): RosterActiveVersion
    {
        $now = CarbonImmutable::now('UTC');
        RosterActiveVersion::query()->insertOrIgnore([
            'duty_date' => $dutyDate,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->lockForUpdate()->firstOrFail();
    }

    private function hold(RosterImport $import, string $code): string
    {
        $this->recorder->transition($import, RosterImport::STATE_NEEDS_REVIEW, ['error_code' => $code], 'publish_held', ['reasons' => [$code]]);

        return $code;
    }

    /**
     * The same roster again. A newer export of it also moves the effective time forward, or an export
     * generated between the two could wrongly replace what TeleStaff has since re-confirmed.
     */
    private function confirm(RosterImport $import, RosterActiveVersion $pointer, RosterSnapshot $active, ?CarbonImmutable $generatedAt): void
    {
        $pointer->forceFill([
            'effective_generated_at' => self::latest($pointer->effective_generated_at, $generatedAt),
            'last_confirmed_at' => self::latest($pointer->last_confirmed_at, $generatedAt),
        ])->save();

        $this->recorder->transition($import, RosterImport::STATE_DUPLICATE, [
            'outcome' => RosterImport::OUTCOME_CONFIRMED,
            'roster_snapshot_id' => $active->getKey(),
            'duplicate_of_import_id' => (int) $active->roster_import_id === (int) $import->getKey() ? null : $active->roster_import_id,
            'error_code' => null,
        ], 'hash_matches_active_snapshot');
        $this->recorder->appendEvent($import, RosterStateRecorder::ACTION_CONFIRMED, snapshotId: (int) $active->getKey());

        if ($generatedAt !== null) {
            $this->supersedeOlderHeld($import, $generatedAt);
        }
    }

    private function repoint(RosterImport $import, RosterActiveVersion $pointer, RosterSnapshot $snapshot, CarbonImmutable $generatedAt): void
    {
        $fromSnapshotId = $pointer->roster_snapshot_id;
        $pointer->forceFill([
            'roster_snapshot_id' => $snapshot->getKey(),
            'effective_generated_at' => $generatedAt,
            'last_confirmed_at' => $generatedAt,
        ])->save();

        $this->recorder->transition($import, RosterImport::STATE_PUBLISHED, [
            'outcome' => RosterImport::OUTCOME_PUBLISHED,
            'roster_snapshot_id' => $snapshot->getKey(),
            'published_at' => CarbonImmutable::now('UTC'),
            'error_code' => null,
        ], 'pointer_moved');
        $this->recorder->appendEvent($import, RosterStateRecorder::ACTION_POINTER_MOVED, context: [
            'from_snapshot_id' => $fromSnapshotId,
            'to_snapshot_id' => $snapshot->getKey(),
            'version' => $snapshot->version,
        ], snapshotId: (int) $snapshot->getKey());

        $this->supersedeOlderHeld($import, $generatedAt);
    }

    /** An old export arriving late is kept for the record. It never replaces newer data. */
    private function storeUnpointed(RosterImport $import, RosterActiveVersion $pointer, RosterSnapshot $snapshot): void
    {
        $activeImportId = $pointer->roster_snapshot_id === null
            ? null
            : RosterSnapshot::query()->whereKey($pointer->roster_snapshot_id)->value('roster_import_id');

        $this->recorder->transition($import, RosterImport::STATE_SUPERSEDED, [
            'outcome' => RosterImport::OUTCOME_SUPERSEDED,
            'roster_snapshot_id' => $snapshot->getKey(),
            'duplicate_of_import_id' => $activeImportId,
            'error_code' => null,
        ], 'older_than_effective');
    }

    /** Held exports older than one that just passed are moot. Their raw files stay until cleanup rules allow. */
    private function supersedeOlderHeld(RosterImport $winner, CarbonImmutable $generatedAt): void
    {
        $held = RosterImport::query()
            ->where('state', RosterImport::STATE_NEEDS_REVIEW)
            ->whereDate('duty_date', $winner->duty_date->format('Y-m-d'))
            ->where('source_generated_at', '<', $generatedAt)
            ->whereNull('published_at')
            ->whereKeyNot($winner->getKey())
            ->orderBy('id')
            ->limit(self::HELD_SUPERSEDE_LIMIT)
            ->get();

        foreach ($held as $import) {
            $this->recorder->transition($import, RosterImport::STATE_SUPERSEDED, [
                'outcome' => RosterImport::OUTCOME_SUPERSEDED,
                'duplicate_of_import_id' => $winner->getKey(),
            ], 'newer_export_passed');
        }
    }

    private function storeSnapshot(RosterImport $import, TeleStaffRosterReport $report): RosterSnapshot
    {
        $snapshot = RosterSnapshot::query()->create([
            'roster_import_id' => $import->getKey(),
            'duty_date' => $report->dutyDate,
            'version' => (int) RosterSnapshot::query()->whereDate('duty_date', $report->dutyDate)->max('version') + 1,
            'shift_letter' => $report->shift,
            'starts_at' => CarbonImmutable::parse($report->windowStartAt)->utc(),
            'ends_at' => CarbonImmutable::parse($report->windowEndAt)->utc(),
            'source_generated_at' => $report->generatedAt === null ? null : CarbonImmutable::parse($report->generatedAt)->utc(),
            'division' => $report->divisionTitle,
            'parser_version' => $report->parserVersion,
            'layout_fingerprint' => $report->layoutFingerprint,
            'normalized_sha256' => $report->normalizedSha256(),
            'section_count' => count($report->sections),
            'assignment_count' => $report->assignmentCount(),
            'unique_employee_count' => $report->uniqueEmployeeCount(),
            'warnings' => $report->warnings,
        ]);

        $sections = $report->sections;
        foreach (array_chunk($this->sectionRows($snapshot, $sections), self::INSERT_CHUNK) as $rows) {
            RosterSection::query()->insert($rows);
        }

        $sectionIds = RosterSection::query()->where('roster_snapshot_id', $snapshot->getKey())->pluck('id', 'position');
        foreach (array_chunk($this->assignmentRows($snapshot, $sections, $sectionIds->all()), self::INSERT_CHUNK) as $rows) {
            RosterAssignment::query()->insert($rows);
        }

        return $snapshot;
    }

    /**
     * @param  list<\App\Services\DailyRoster\Parsing\TeleStaffRosterSection>  $sections
     * @return list<array<string, mixed>>
     */
    private function sectionRows(RosterSnapshot $snapshot, array $sections): array
    {
        $rows = [];
        foreach ($sections as $position => $section) {
            $entryCount = 0;
            foreach ($section->seats as $seat) {
                $entryCount += count($seat->entries);
            }
            $rows[] = [
                'roster_snapshot_id' => $snapshot->getKey(),
                'position' => $position,
                'raw_title' => $section->rawTitle,
                'parent_label' => $section->parent,
                'unit_label' => $section->unit,
                'section_role' => self::sectionRole($section->unit),
                'source_row' => $section->sourceRow,
                'entry_count' => $entryCount,
            ];
        }

        return $rows;
    }

    /**
     * Raw values go in exactly as printed: the read-back check recomputes the export's hash from them.
     *
     * @param  list<\App\Services\DailyRoster\Parsing\TeleStaffRosterSection>  $sections
     * @param  array<int, int>  $sectionIds  Section ID by position
     * @return list<array<string, mixed>>
     */
    private function assignmentRows(RosterSnapshot $snapshot, array $sections, array $sectionIds): array
    {
        $rows = [];
        foreach ($sections as $sectionPosition => $section) {
            $position = 0;
            foreach ($section->seats as $seatPosition => $seat) {
                foreach ($seat->entries as $entry) {
                    $rows[] = [
                        'roster_snapshot_id' => $snapshot->getKey(),
                        'roster_section_id' => $sectionIds[$sectionPosition],
                        'position' => $position++,
                        'seat_position' => $seatPosition,
                        'seat_rank' => $seat->rawRank,
                        'rank_label' => $entry->isContinuation ? null : $seat->rawRank,
                        'is_continuation' => $entry->isContinuation,
                        'kind' => $entry->kind,
                        'employee_id' => $entry->employeeId,
                        'raw_name' => $entry->rawName,
                        'display_name' => $entry->displayName,
                        'certifications' => json_encode($entry->certifications, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'raw_code' => $entry->rawCode,
                        'base_code' => $entry->baseCode,
                        'code_prefix' => $entry->prefix,
                        'availability' => $entry->availability,
                        'category' => $entry->category,
                        'display_label' => $entry->displayLabel,
                        'raw_from' => $entry->rawFrom,
                        'raw_through' => $entry->rawThrough,
                        'reported_hours' => $entry->reportedHours,
                        'starts_at' => self::storedInstant($entry->startAt),
                        'ends_at' => self::storedInstant($entry->endAt),
                        'review_reasons' => $entry->reviewReasons === [] ? null : json_encode($entry->reviewReasons, JSON_THROW_ON_ERROR),
                        'source_row' => $entry->sourceRow,
                    ];
                }
            }
        }

        return $rows;
    }

    private static function storedInstant(?string $iso): ?string
    {
        return $iso === null ? null : CarbonImmutable::parse($iso)->utc()->format('Y-m-d H:i:s');
    }

    private static function latest(?CarbonImmutable $current, ?CarbonImmutable $candidate): ?CarbonImmutable
    {
        if ($current === null || $candidate === null) {
            return $current ?? $candidate;
        }

        return $candidate->greaterThan($current) ? $candidate : $current;
    }
}
