<?php

declare(strict_types=1);

namespace App\Support\Personnel;

use App\Models\AccidentReport;
use App\Models\Employee;
use App\Models\PersonnelDocument;
use App\Models\User;

/**
 * Who may see or act on personnel documents and accident reports. Every controller, page and download checks
 * here on the server; hiding a button is never the control.
 *
 * Permissions are direct web permissions granted through Employees & Access, like the rest of the personnel
 * capabilities. Super admins hold every one. Every Hub administrator can open accident reports in full; resolving
 * and correcting them needs personnel.accidents.manage.
 */
final class PersonnelAccess
{
    /** Officer ranks that may complete the supervisor part of an accident report without a separate grant. */
    private const SUPERVISOR_RANK_PATTERN = '/^\s*(lieutenant|lt|captain|capt|cpt|battalion chief|bc|division chief|dc|deputy( fire)? chief|assistant( fire)? chief|fire chief)\.?\s*$/i';

    public static function isSuperAdmin(?User $user): bool
    {
        return $user instanceof User && $user->hasRole('super_admin');
    }

    private static function has(?User $user, string $permission): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }

        return self::isSuperAdmin($user) || $user->hasDirectWebPermission($permission);
    }

    public static function canViewDocuments(?User $user): bool
    {
        return self::has($user, 'personnel.documents.view') || self::canManageDocuments($user);
    }

    public static function canManageDocuments(?User $user): bool
    {
        return self::has($user, 'personnel.documents.manage');
    }

    /**
     * Every Hub administrator (admin panel access) opens accident reports in full, as do accounts granted
     * personnel.accidents.view directly (owner decision 2026-11-05: an accident report is not a medical record).
     */
    public static function canViewAccidents(?User $user): bool
    {
        // Uniform-manager-only accounts are deliberately limited to the Personnel Uniforms & Equipment area.
        if ($user instanceof User && $user->isAuthenticationAllowed() && $user->hasCurrentAdminPanelEntitlement()
            && ! \App\Services\Security\ApplicationAccessService::isUniformManagerOnly($user)) {
            return true;
        }

        return self::has($user, 'personnel.accidents.view') || self::canManageAccidents($user);
    }

    /** Acknowledging the alert records who has seen it; anyone who may open the report may acknowledge it. */
    public static function canAcknowledgeAccidents(?User $user): bool
    {
        return self::canViewAccidents($user);
    }

    public static function canManageAccidents(?User $user): bool
    {
        return self::has($user, 'personnel.accidents.manage');
    }

    /** May this person complete the supervisor's part or a Supervisor's Report of Accident? */
    public static function canSupervise(?User $user): bool
    {
        if (self::has($user, 'personnel.accidents.supervise')) {
            return true;
        }
        $employee = $user?->employeeProfile;

        return $employee instanceof Employee && $user->isAuthenticationAllowed() && self::isSupervisorRank($employee->rank);
    }

    public static function isSupervisorRank(?string $rank): bool
    {
        return $rank !== null && preg_match(self::SUPERVISOR_RANK_PATTERN, $rank) === 1;
    }

    /**
     * A report is visible to the people in it (employee involved, reporter, assigned supervisor, creator) and to
     * holders of the accident permission.
     */
    public static function canViewReport(?User $user, AccidentReport $report): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }
        if (self::canViewAccidents($user) || $report->created_by_user_id === $user->getKey()) {
            return true;
        }
        $employeeId = $user->employee_profile_id;

        return $employeeId !== null && in_array((int) $employeeId, array_map('intval', array_filter([
            $report->subject_employee_id, $report->reporter_employee_id, $report->supervisor_employee_id,
        ])), true) && $report->submitted_at !== null;
    }

    public static function canViewDocument(?User $user, PersonnelDocument $document): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }
        $ownEmployee = $user->employee_profile_id !== null && (int) $user->employee_profile_id === (int) $document->employee_id;
        if ($ownEmployee && $document->visible_to_employee) {
            return true;
        }
        if ($document->access_level === PersonnelDocument::ACCESS_SUPPORT_MESSAGE) {
            // Not a personnel-file document: only the member (above) and Support Services may open it.
            return app(\App\Services\Security\EmployeeLogisticsMessagingAccess::class)->canViewLogisticsInbox($user);
        }
        if ($document->access_level === PersonnelDocument::ACCESS_ACCIDENT) {
            if (self::canViewAccidents($user)) {
                return true;
            }
            $report = $document->source;

            return $report instanceof AccidentReport && self::canViewReport($user, $report);
        }

        return self::canViewDocuments($user);
    }
}
