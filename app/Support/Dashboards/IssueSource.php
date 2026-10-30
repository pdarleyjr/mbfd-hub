<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

use App\Models\User;

/**
 * Adapter between one existing domain workflow and the admin dashboards.
 *
 * Rules (release-blocking):
 * - Read through the domain's existing models/queries with eager loading and hard caps; no N+1.
 * - Never create a parallel issues table or synthetic status; status/stage come from the source.
 * - visibleTo()/rows()/count() honour the same policies the domain's Filament resource uses.
 * - detail() and perform() re-authorize on every call (rights may change mid-session).
 * - perform() delegates to the domain's existing service/model workflow inside a transaction,
 *   compares $expectedVersion with the locked record first (conflict → nothing written), is
 *   idempotent for an identical repeat (e.g. acknowledging twice is a no-op `done`), and lets the
 *   domain fire its own notifications/audit events. It never marks anything read implicitly.
 * - In display (wall) mode, rows() must omit records naming members or carrying personal
 *   messages, exactly as the existing Dashboard::getWidgets() display rules do.
 */
interface IssueSource
{
    /** Stable key, e.g. "apparatus_defect", "service_ticket", "station_request", "support_message". */
    public function type(): string;

    /** Which dashboards show this source. @return list<DashboardView> */
    public function views(): array;

    public function visibleTo(User $user, bool $displayMode): bool;

    /**
     * Open/recent rows for the dashboards, newest/most severe first, at most $limit.
     *
     * @return list<DashboardIssue>
     */
    public function rows(User $user, DashboardQuery $query, int $limit): array;

    /**
     * Exact counts for the stat cards and filter chips, computed with the same filters as rows().
     *
     * @return array<string, int> keys: "open", "awaiting_response", "high", "resolved_today", plus any tag key.
     */
    public function counts(User $user, DashboardQuery $query): array;

    /** Null when the record is gone or outside the user's scope. */
    public function detail(User $user, string $id): ?IssueDetail;

    /** @param  array<string, mixed>  $input */
    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult;
}
