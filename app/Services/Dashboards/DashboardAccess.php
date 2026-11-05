<?php

declare(strict_types=1);

namespace App\Services\Dashboards;

use App\Models\User;
use App\Services\Ems\EmsAccess;
use App\Services\Narcotics\NarcoticsCheckAccess;
use App\Services\Security\ApplicationAccessService;
use App\Support\Dashboards\DashboardView;
use Throwable;

/**
 * Who may open the admin dashboards. Entry is the existing admin panel entitlement; the vehicle,
 * station and logistics boards are department-wide operations views, so an account whose only
 * administration is the uniform & PPE set (ApplicationAccessService::isUniformManagerOnly) does
 * not get them. What each person sees inside a board is still decided per source (visibleTo()).
 *
 * The Member Requests board is for the people who act on what members ask for: uniform and PPE
 * staff (admin.personnel.view, the same gate as the Personnel Requests list), EMS supply staff
 * (EmsAccess::canView, the Medical Inventory Requests gate) and Captain 5 narcotics reviewers
 * (NarcoticsCheckAccess::canReview). Uniform-only managers get it; it is their board.
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
        if (! self::canEnterPanel($user)) {
            return false;
        }
        assert($user instanceof User);

        return $user->can('admin.personnel.view')
            || self::safely(static fn (): bool => app(EmsAccess::class)->canView($user))
            || self::safely(static fn (): bool => app(NarcoticsCheckAccess::class)->canReview($user));
    }

    public static function canView(mixed $user, DashboardView $view): bool
    {
        return match ($view) {
            DashboardView::Overview => self::canEnterPanel($user),
            DashboardView::MemberRequests => self::canViewMemberRequests($user),
            default => self::canViewOperationsBoards($user),
        };
    }

    /** A failing access lookup (for example a roster read) hides the board rather than breaking the page. */
    private static function safely(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
