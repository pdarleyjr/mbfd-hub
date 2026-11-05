<?php

declare(strict_types=1);

namespace App\Services\Dashboards;

use App\Models\User;
use App\Services\Security\ApplicationAccessService;
use App\Support\Dashboards\DashboardView;

/**
 * Who may open the admin dashboards. Entry is the existing admin panel entitlement; the vehicle,
 * station and logistics boards are department-wide operations views, so an account whose only
 * administration is the uniform & PPE set (ApplicationAccessService::isUniformManagerOnly) does
 * not get them. What each person sees inside a board is still decided per source (visibleTo()).
 *
 * The Member Requests board is for the people who act on uniform, PPE and equipment requests:
 * admin.personnel.view, the same gate as the Personnel Requests list. Uniform-only managers get it;
 * it is their board. Medical supply requests and narcotics checks are not on the admin dashboards
 * (they stay with Captain 5), so EMS or narcotics access alone never opens this board.
 */
final class DashboardAccess
{
    public static function canEnterPanel(mixed $user): bool
    {
        return $user instanceof User && $user->hasCurrentAdminPanelEntitlement();
    }

    public static function canViewOperationsBoards(mixed $user): bool
    {
        return self::canEnterPanel($user) && ! ApplicationAccessService::isUniformManagerOnly($user);
    }

    public static function canViewMemberRequests(mixed $user): bool
    {
        return self::canEnterPanel($user) && $user instanceof User && $user->can('admin.personnel.view');
    }

    public static function canView(mixed $user, DashboardView $view): bool
    {
        return match ($view) {
            DashboardView::Overview => self::canEnterPanel($user),
            DashboardView::MemberRequests => self::canViewMemberRequests($user),
            default => self::canViewOperationsBoards($user),
        };
    }
}
