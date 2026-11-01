<?php

declare(strict_types=1);

namespace App\Support\Fleet;

use App\Filament\Support\InspectionWorkspace;
use App\Models\ApparatusIssueMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every internal note and member message written on Daily Checkouts, for the Logistics Dashboard:
 * issue messages, internal notes on issue updates and internal notes on review decisions, read in
 * one UNION ALL query. Notes on work that is still open come first (any age), then the last week of
 * notes on closed work. Each row opens the inspection's admin view, where the admin resolves it.
 * Staff-only: the dashboard is an admin surface and names the authors.
 */
final class DailyCheckoutNotesFeed
{
    public const LIMIT = 60;

    public const RECENT_DAYS = 7;

    /** Upper bound read from the database before open-first ordering trims the list to LIMIT. */
    private const SCAN = 300;

    private const TIMEZONE = 'America/New_York';

    private const OPEN_INSPECTION_STATES = ['held_for_review'];

    /** @return array{count: int, rows: list<array<string, mixed>>} */
    public static function build(): array
    {
        $since = CarbonImmutable::now()->subDays(self::RECENT_DAYS)->utc()->format('Y-m-d H:i:s');

        $rows = self::messages($since)
            ->unionAll(self::issueUpdateNotes($since))
            ->unionAll(self::reviewNotes($since))
            // Open-first in SQL, before the scan limit, so an old note on open work is never cut off.
            ->orderByDesc('is_open')->orderByDesc('created_at')->orderByDesc('sort_id')
            ->limit(self::SCAN)
            ->get()
            ->filter(static fn (object $row): bool => $row->inspection_id !== null)
            ->map(static fn (object $row): object => self::classify($row))
            ->sort(static fn (object $a, object $b): int => [$b->open, (string) $b->created_at, (int) $b->sort_id] <=> [$a->open, (string) $a->created_at, (int) $a->sort_id])
            ->values();

        return [
            'count' => $rows->where('open', true)->count(),
            'rows' => $rows->take(self::LIMIT)->map(static fn (object $row): array => self::present($row))->values()->all(),
        ];
    }

    /** Internal notes and member-visible messages, on an inspection or on one of its issues. */
    private static function messages(string $since): Builder
    {
        $query = DB::table('apparatus_issue_messages as m')
            ->leftJoin('apparatus_defects as d', 'd.id', '=', 'm.apparatus_defect_id')
            ->leftJoin('apparatus_inspections as i', 'i.id', '=', DB::raw('COALESCE(m.apparatus_inspection_id, d.first_reported_inspection_id, d.apparatus_inspection_id, d.last_observed_inspection_id)'))
            ->leftJoin('users as u', 'u.id', '=', 'm.author_user_id');
        self::scopeOpenOrRecent($query, 'm.created_at', $since, withIssue: true);

        return $query->select([
            DB::raw("'message' as source"), 'm.id as sort_id', 'm.body', 'm.created_at', 'm.apparatus_defect_id',
            'm.visibility', 'm.author_context', DB::raw('NULL as details'),
            ...self::contextColumns(withIssue: true),
            // A message on an issue follows the issue; one on the inspection itself follows the inspection.
            DB::raw('CASE WHEN m.apparatus_defect_id IS NOT NULL THEN '.self::issueOpenSql().' ELSE '.self::inspectionOpenSql().' END as is_open'),
        ]);
    }

    /** Internal notes recorded with an issue's corrective-action update. */
    private static function issueUpdateNotes(string $since): Builder
    {
        $query = DB::table('apparatus_defect_events as e')
            ->join('apparatus_defects as d', 'd.id', '=', 'e.apparatus_defect_id')
            ->leftJoin('apparatus_inspections as i', 'i.id', '=', DB::raw('COALESCE(e.apparatus_inspection_id, d.first_reported_inspection_id, d.apparatus_inspection_id, d.last_observed_inspection_id)'))
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->whereNotNull('e.internal_note')->where('e.internal_note', '<>', '');
        self::scopeOpenOrRecent($query, 'e.created_at', $since, withIssue: true);

        return $query->select([
            DB::raw("'issue_update' as source"), 'e.id as sort_id', 'e.internal_note as body', 'e.created_at', 'e.apparatus_defect_id',
            DB::raw("'internal' as visibility"), DB::raw('NULL as author_context'), 'e.details',
            ...self::contextColumns(withIssue: true),
            DB::raw(self::issueOpenSql().' as is_open'),
        ]);
    }

    /** Internal notes recorded with an approval, hold or rejection decision. */
    private static function reviewNotes(string $since): Builder
    {
        $query = DB::table('apparatus_inspection_review_events as r')
            ->join('apparatus_inspections as i', 'i.id', '=', 'r.apparatus_inspection_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.changed_by_user_id')
            ->whereNotNull('r.internal_note')->where('r.internal_note', '<>', '');
        self::scopeOpenOrRecent($query, 'r.created_at', $since, withIssue: false);

        return $query->select([
            DB::raw("'review' as source"), 'r.id as sort_id', 'r.internal_note as body', 'r.created_at', DB::raw('NULL as apparatus_defect_id'),
            DB::raw("'internal' as visibility"), DB::raw('NULL as author_context'), DB::raw('NULL as details'),
            ...self::contextColumns(withIssue: false),
            DB::raw(self::inspectionOpenSql().' as is_open'),
        ]);
    }

    /** @return list<string|\Illuminate\Contracts\Database\Query\Expression> */
    private static function contextColumns(bool $withIssue): array
    {
        return [
            'u.name as author_name', 'u.display_name as author_display_name',
            'i.id as inspection_id', 'i.designation_at_time', 'i.unit_number', 'i.processing_status', 'i.review_status',
            ...($withIssue
                ? ['d.item as issue_item', 'd.action_status as issue_status']
                : [DB::raw('NULL as issue_item'), DB::raw('NULL as issue_status')]),
        ];
    }

    /** Rows on still-open work at any age, plus everything from the last week. */
    private static function scopeOpenOrRecent(Builder $query, string $createdColumn, string $since, bool $withIssue): void
    {
        $query->where(static function (Builder $scope) use ($createdColumn, $since, $withIssue): void {
            $scope->where($createdColumn, '>=', $since)
                ->when($withIssue, static fn (Builder $issue) => $issue->orWhere(
                    static fn (Builder $open) => $open->whereNotNull('d.id')->whereNotIn('d.action_status', DefectActionStatus::CLOSED),
                ))
                ->orWhereIn('i.processing_status', self::OPEN_INSPECTION_STATES)
                ->orWhere('i.review_status', 'pending_review');
        });
    }

    /** 1 while the issue is unresolved (constants only, so no bindings inside the UNION). */
    private static function issueOpenSql(): string
    {
        $closed = implode(', ', array_map(static fn (string $status): string => "'".$status."'", DefectActionStatus::CLOSED));

        return "(CASE WHEN d.id IS NOT NULL AND d.action_status NOT IN ({$closed}) THEN 1 ELSE 0 END)";
    }

    /** 1 while the inspection still waits on a decision. */
    private static function inspectionOpenSql(): string
    {
        $states = implode(', ', array_map(static fn (string $state): string => "'".$state."'", self::OPEN_INSPECTION_STATES));

        return "(CASE WHEN i.processing_status IN ({$states}) OR i.review_status = 'pending_review' THEN 1 ELSE 0 END)";
    }

    private static function classify(object $row): object
    {
        $row->open = (int) $row->is_open === 1;
        $row->kind = match (true) {
            $row->visibility === ApparatusIssueMessage::VISIBILITY_INTERNAL => 'internal',
            $row->author_context === ApparatusIssueMessage::CONTEXT_MEMBER => 'member_reply',
            default => 'to_member',
        };

        return $row;
    }

    /** @return array<string, mixed> */
    private static function present(object $row): array
    {
        $unit = trim((string) ($row->designation_at_time ?: $row->unit_number)) ?: 'Apparatus';
        $time = CarbonImmutable::parse((string) $row->created_at, 'UTC')->setTimezone(self::TIMEZONE);

        return [
            'id' => $row->source.'-'.$row->sort_id,
            'kind' => $row->kind,
            'kindLabel' => match ($row->kind) {
                'internal' => 'Internal note',
                'member_reply' => 'Member reply',
                default => 'Message to member',
            },
            'subject' => $row->issue_item ? $unit.' · '.$row->issue_item : $unit.' checkout',
            'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', trim((string) $row->body)) ?? '', 160),
            'author' => self::author($row),
            'open' => (bool) $row->open,
            'url' => InspectionWorkspace::url((int) $row->inspection_id, match (true) {
                $row->apparatus_defect_id !== null => 'issue-'.$row->apparatus_defect_id,
                $row->source === 'review' => 'approval',
                default => 'messages',
            }),
            'timestamp' => $time->toIso8601String(),
            'timeLabel' => $time->format('M j, g:i A'),
        ];
    }

    /** The author's name, with the Captain 5 authority recorded at the time ("Captain 5 (acting) · Name"). */
    private static function author(object $row): string
    {
        $name = trim((string) ($row->author_display_name ?: $row->author_name));
        $details = is_string($row->details) ? json_decode($row->details, true) : null;
        $context = is_array($details) ? ($details['actor_context'] ?? null) : null;
        $captain5 = $row->author_context === ApparatusIssueMessage::CONTEXT_CAPTAIN5
            || (is_array($context) && ($context['role'] ?? null) === 'captain5');
        if ($captain5) {
            $label = is_array($context) ? trim((string) ($context['label'] ?? '')) : '';
            $label = $label !== '' ? $label : 'Captain 5';

            return $name !== '' ? $label.' · '.$name : $label;
        }

        return $name !== '' ? $name : ($row->author_context === ApparatusIssueMessage::CONTEXT_MEMBER ? 'Member' : 'User no longer active');
    }
}
