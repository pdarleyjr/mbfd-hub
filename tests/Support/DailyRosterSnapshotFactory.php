<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Builds a published roster directly in the database with synthetic people, for read-side tests.
 * Pipeline tests must use the real publisher instead.
 *
 * @phpstan-type Row array{employee_id?: ?string, name?: ?string, code?: ?string, availability?: string, kind?: string, rank?: ?string, seat?: int, from?: string, through?: string, hours?: float, certifications?: list<string>, label?: ?string, category?: ?string}
 */
final class DailyRosterSnapshotFactory
{
    /**
     * @param  array<string, list<Row>>  $sections  Raw section title => rows, in order
     * @param  array<string, mixed>  $options  shift, generated_at, activate (default true), version
     */
    public static function publish(string $dutyDate, array $sections, array $options = []): RosterSnapshot
    {
        $calendar = app(DutyCalendar::class);
        [$start, $end] = $calendar->window($dutyDate);
        $generatedAt = CarbonImmutable::parse($options['generated_at'] ?? $start->addMinutes(74));
        $shift = (string) ($options['shift'] ?? $calendar->shiftFor($dutyDate) ?? 'B');
        $hash = hash('sha256', $dutyDate.Str::uuid());

        $import = RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'state' => RosterImport::STATE_VERIFIED,
            'state_changed_at' => $generatedAt,
            'outcome' => RosterImport::OUTCOME_PUBLISHED,
            'message_id_sha256' => hash('sha256', 'message-'.Str::uuid()),
            'sender' => (string) config('daily_roster.classifier.sender'),
            'subject' => (string) config('daily_roster.classifier.subject'),
            'filename' => (string) config('daily_roster.classifier.filename'),
            'received_at' => $generatedAt,
            'duty_date' => $dutyDate,
            'shift_letter' => $shift,
            'source_generated_at' => $generatedAt,
            'parser_version' => 'test',
            'layout_fingerprint' => str_repeat('a', 64),
            'normalized_sha256' => $hash,
            'published_at' => $generatedAt,
            'verified_at' => $generatedAt,
        ]);

        $assignmentCount = array_sum(array_map('count', $sections));
        $snapshot = RosterSnapshot::query()->create([
            'roster_import_id' => $import->id,
            'duty_date' => $dutyDate,
            'version' => (int) ($options['version'] ?? ((int) RosterSnapshot::query()->whereDate('duty_date', $dutyDate)->max('version') + 1)),
            'shift_letter' => $shift,
            'starts_at' => $start,
            'ends_at' => $end,
            'source_generated_at' => $generatedAt,
            'division' => 'Miami Beach Fire Department / Suppression/Rescue / '.$shift.' Shift',
            'parser_version' => 'test',
            'layout_fingerprint' => str_repeat('a', 64),
            'normalized_sha256' => $hash,
            'section_count' => count($sections),
            'assignment_count' => $assignmentCount,
            'unique_employee_count' => count(array_unique(array_filter(array_merge(...array_map(
                static fn (array $rows): array => array_map(static fn (array $row): ?string => $row['employee_id'] ?? null, $rows),
                array_values($sections) ?: [[]],
            ))))),
        ]);

        $sectionPosition = 0;
        $sourceRow = 30;
        foreach ($sections as $title => $rows) {
            [$parent, $unit] = array_pad(array_map('trim', explode(' / ', (string) $title, 2)), 2, (string) $title);
            $section = RosterSection::query()->create([
                'roster_snapshot_id' => $snapshot->id,
                'position' => $sectionPosition++,
                'raw_title' => $title,
                'parent_label' => $parent,
                'unit_label' => $unit,
                'section_role' => match ($unit) {
                    (string) config('daily_roster.section_roles.command_300') => RosterSection::ROLE_COMMAND_300,
                    (string) config('daily_roster.section_roles.captain_5') => RosterSection::ROLE_CAPTAIN_5,
                    default => null,
                },
                'source_row' => $sourceRow++,
                'entry_count' => count($rows),
            ]);

            foreach (array_values($rows) as $position => $row) {
                $kind = $row['kind'] ?? RosterAssignment::KIND_EMPLOYEE;
                [$startsAt, $endsAt] = self::interval($dutyDate, $row['from'] ?? '08:00', $row['through'] ?? '08:00');
                RosterAssignment::query()->create([
                    'roster_snapshot_id' => $snapshot->id,
                    'roster_section_id' => $section->id,
                    'position' => $position,
                    'seat_position' => $row['seat'] ?? $position,
                    'seat_rank' => $row['rank'] ?? 'Firefighter',
                    'rank_label' => $row['rank'] ?? 'Firefighter',
                    'is_continuation' => (bool) ($row['continuation'] ?? false),
                    'kind' => $kind,
                    'employee_id' => $row['employee_id'] ?? null,
                    'raw_name' => $row['name'] ?? ($kind === RosterAssignment::KIND_EMPLOYEE ? 'SAMPLE, MEMBER' : '-,-'),
                    'display_name' => $row['name'] ?? null,
                    'certifications' => $row['certifications'] ?? [],
                    'raw_code' => $row['code'] ?? ($kind === RosterAssignment::KIND_EMPLOYEE ? 'SP' : null),
                    'base_code' => $row['base_code'] ?? $row['code'] ?? ($kind === RosterAssignment::KIND_EMPLOYEE ? 'SP' : null),
                    'availability' => $row['availability'] ?? ($kind === RosterAssignment::KIND_EMPLOYEE ? RosterAssignment::AVAILABILITY_WORKING : RosterAssignment::AVAILABILITY_VACANT),
                    'category' => $row['category'] ?? null,
                    'display_label' => $row['label'] ?? $row['code'] ?? null,
                    'raw_from' => $row['from'] ?? '08:00',
                    'raw_through' => $row['through'] ?? '08:00',
                    'reported_hours' => $row['hours'] ?? 24.0,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'source_row' => $sourceRow++,
                ]);
            }
        }

        if ($options['activate'] ?? true) {
            RosterActiveVersion::query()->updateOrCreate(['duty_date' => $dutyDate], [
                'roster_snapshot_id' => $snapshot->id,
                'effective_generated_at' => $generatedAt,
                'last_confirmed_at' => $generatedAt,
            ]);
        }
        $import->forceFill(['roster_snapshot_id' => $snapshot->id])->save();

        return $snapshot;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function interval(string $dutyDate, string $from, string $through): array
    {
        $zone = app(DutyCalendar::class)->timezone();
        $startsAt = CarbonImmutable::parse("{$dutyDate} {$from}", $zone);
        if ((int) substr($from, 0, 2) < 8) {
            $startsAt = $startsAt->addDay();
        }
        $endsAt = CarbonImmutable::parse($startsAt->format('Y-m-d')." {$through}", $zone);
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $endsAt->addDay();
        }

        return [$startsAt->utc(), $endsAt->utc()];
    }
}
