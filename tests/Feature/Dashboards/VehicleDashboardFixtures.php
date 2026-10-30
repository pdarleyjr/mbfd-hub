<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\IssueSource;
use App\Support\OperationalDisplayWindow;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin personas as production grants them (database/migrations/2026_09_04_231000 backfill):
 * administrator and Logistics administrator roles carry the direct admin.access + Fleet
 * capabilities; a Fleet reviewer (Fleet Operations Manager) holds only the capabilities.
 */
trait VehicleDashboardFixtures
{
    protected function setUpVehicleDashboard(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<string, User> */
    protected function personas(): array
    {
        return [
            'super admin' => $this->persona('super_admin', []),
            'admin' => $this->persona('admin', ['admin.access', 'admin.fleet.view', 'admin.fleet.manage']),
            'logistics admin' => $this->persona('logistics_admin', ['admin.access', 'admin.fleet.view', 'admin.fleet.manage']),
            'fleet reviewer' => $this->persona(null, ['admin.access', 'admin.fleet.view', 'admin.fleet.manage']),
        ];
    }

    /** An active account with no administrative role or capability. */
    protected function plainMember(): User
    {
        return $this->persona(null, []);
    }

    /** Sees Fleet records but may not manage them (admin.fleet.view only). */
    protected function fleetViewer(): User
    {
        return $this->persona(null, ['admin.access', 'admin.fleet.view']);
    }

    /** @param list<string> $permissions */
    protected function persona(?string $role, array $permissions): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        if ($role !== null) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    protected function boardQuery(string $now = '2026-10-30 10:00:00', bool $display = false, array $tags = [], ?string $stage = null, ?string $severity = null): DashboardQuery
    {
        return new DashboardQuery(OperationalDisplayWindow::forNow($now.' America/New_York'), $display, $tags, $stage, $severity);
    }

    /** @return list<DashboardIssue> */
    protected function allRows(IssueSource $source, User $user, DashboardQuery $query): array
    {
        return $source->rows($user, $query, 1000);
    }

    /**
     * Recomputes every count from the uncapped rows with the documented definitions.
     *
     * @param  list<DashboardIssue>  $rows
     * @param  list<string>  $tags
     * @return array<string, int>
     */
    protected function countsFromRows(array $rows, array $tags): array
    {
        $open = array_filter($rows, static fn (DashboardIssue $row): bool => in_array($row->stage, ['awaiting_response', 'in_progress'], true));
        $counts = [
            'open' => count($open),
            'awaiting_response' => count(array_filter($rows, static fn (DashboardIssue $row): bool => $row->stage === 'awaiting_response')),
            'high' => count(array_filter($open, static fn (DashboardIssue $row): bool => $row->severity === 'high')),
            'resolved_today' => count(array_filter($rows, static fn (DashboardIssue $row): bool => $row->stage === 'resolved')),
        ];
        foreach ($tags as $tag) {
            $counts[$tag] = count(array_filter($open, static fn (DashboardIssue $row): bool => in_array($tag, $row->tags, true)));
        }

        return $counts;
    }

    /** Number of SQL statements $work runs. */
    protected function queriesDuring(callable $work): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $work();

        return $count;
    }
}
