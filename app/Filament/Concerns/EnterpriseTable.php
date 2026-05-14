<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Tables\Table;

/**
 * EnterpriseTable applies desktop-density defaults to any Filament table
 * resource that wants the "enterprise software" treatment.
 *
 * The defaults:
 *   - Striped rows (easier eye-tracking on wide tables)
 *   - Default page size 50 (vs Filament's 10) for power users
 *   - Pagination options up to 250 for bulk admin workflows
 *   - extremePaginationLinks() shows first/prev/next/last buttons
 *   - persistFiltersInSession() keeps filter state across navigations
 *   - deferLoading() prevents N+1 on table-heavy dashboards
 *
 * Usage in a Resource:
 *
 *   use App\Filament\Concerns\EnterpriseTable;
 *
 *   class ApparatusResource extends Resource
 *   {
 *       use EnterpriseTable;
 *
 *       public static function table(Table $table): Table
 *       {
 *           return self::applyEnterpriseDefaults($table)
 *               ->columns([ ... ])
 *               ->filters([ ... ]);
 *       }
 *   }
 *
 * This trait is intentionally additive. Removing it from any resource
 * restores the previous Filament defaults — no behavioral lock-in.
 */
trait EnterpriseTable
{
    public static function applyEnterpriseDefaults(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(50)
            ->paginationPageOptions([25, 50, 100, 250])
            ->extremePaginationLinks()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->deferLoading();
    }
}
