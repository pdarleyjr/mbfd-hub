<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\ApparatusServiceTicket;
use App\Models\AssignedEquipment;
use App\Models\CapitalProject;
use App\Models\EvaluationSubmission;
use App\Models\HubSupportTicket;
use App\Models\PersonnelRequest;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\Todo;
use App\Models\User;
use App\Services\Captain5\ActingApprovers;
use App\Services\Security\Captain5Access;
use App\Services\Security\Command300Access;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use App\Support\Fleet\InspectionReviewers;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The single list of alert categories. Every category states, in plain words,
 * what it is for, who may receive it (evaluated from current roles and
 * permissions, never stored), which channels it supports and what a member
 * gets before choosing anything.
 *
 * Extension points for later phases:
 *  - `captain5_ems`: wired to the Captain 5 notifier and Captain5Access.
 *  - `command_communications`: reserved for the 300 Command channel.
 */
final class NotificationCategoryRegistry
{
    public const GROUP_OWN = 'About things I submitted';

    public const GROUP_DEPARTMENT = 'Department';

    public const GROUP_FLEET = 'Daily Checkout and fleet';

    public const GROUP_STATIONS = 'Stations';

    public const GROUP_SUPPORT_SERVICES = 'Support Services';

    public const GROUP_TRAINING = 'Training and workgroups';

    public const GROUP_ADMIN = 'Administration and system';

    public const GROUP_COMMAND = 'Command';

    public const CAPTAIN5_EMS = 'captain5_ems';

    public const CAPTAIN5_ASSIGNMENT_REQUESTS = 'captain5_assignment_requests';

    public const PROJECT_ALERTS = 'project_alerts';

    public const EQUIPMENT_EXPIRATIONS = 'equipment_expirations';

    public const TODO_ASSIGNMENTS = 'todo_assignments';

    public const SUPPORT_SERVICES_MESSAGES = 'support_services_messages';

    /** @var array<string, NotificationCategory>|null */
    private ?array $categories = null;

    /** @return array<string, NotificationCategory> */
    public function all(): array
    {
        return $this->categories ??= $this->build();
    }

    public function get(string $key): ?NotificationCategory
    {
        return $this->all()[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * The categories this member is eligible for right now and that have a
     * wired sender, in display order. Nothing is stored: a role change shows
     * or hides a category on the next page load.
     *
     * @return Collection<string, NotificationCategory>
     */
    public function forUser(User $user): Collection
    {
        $context = new EligibilityContext($user);
        if (! $context->isActive()) {
            return collect();
        }

        return collect($this->all())
            ->filter(fn (NotificationCategory $category): bool => $category->senderWired
                && $category->matchedRule($context) !== null);
    }

    /**
     * Legacy shape used by User::notificationPreferenceDefinitions().
     *
     * @return array<string, array{label: string, description: string, essential_in_app?: bool}>
     */
    public function legacyDefinitions(): array
    {
        return collect($this->all())->map(fn (NotificationCategory $category): array => array_filter([
            'label' => $category->label,
            'description' => $category->description,
            'essential_in_app' => $category->isLocked(NotificationCategory::CHANNEL_DATABASE) ?: null,
        ], static fn (mixed $value): bool => $value !== null))->all();
    }

    /** @return array<string, NotificationCategory> */
    private function build(): array
    {
        $db = NotificationCategory::CHANNEL_DATABASE;
        $push = NotificationCategory::CHANNEL_WEBPUSH;
        $mail = NotificationCategory::CHANNEL_EMAIL;
        $all = [$db, $push, $mail];
        // Cheap prefilter per category: only accounts that hold a role or permission which could make the
        // category's rule true (directly or through a role), plus super_admin. It must stay a SUPERSET of the
        // eligible people; the rule itself is still asked of each one, live, so nothing is granted by it.
        $audience = static fn (array $roles, array $permissions): Closure => static fn (): Builder => User::query()
            ->where('account_status', 'active')
            ->where(function (Builder $query) use ($roles, $permissions): void {
                $query->whereHas('roles', fn (Builder $role) => $role->whereIn('name', $roles))
                    ->orWhereHas('permissions', fn (Builder $permission) => $permission->whereIn('name', $permissions))
                    ->orWhereHas('roles.permissions', fn (Builder $permission) => $permission->whereIn('name', $permissions));
            });
        $fleetReviewers = $audience([...InspectionReviewers::ROLES], ['admin.fleet.manage']);
        $stationViewers = $audience(['super_admin'], ['admin.stations.view']);
        $personnelReviewers = $audience(['super_admin', 'admin', 'logistics_admin'], ['admin.personnel.view', 'admin.personnel.manage']);

        $definitions = [
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES,
                label: 'Updates on things I submitted',
                description: 'Answers and status changes on your requests, reports and Daily Checkouts. In-app alerts for these are always on.',
                group: self::GROUP_OWN,
                channels: $all,
                locked: [$db],
                significance: 'important',
                eligibility: static fn (EligibilityContext $c): ?string => $c->isActive() ? 'active_member' : null,
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES,
                label: 'Department updates',
                description: 'Notices the department publishes to you.',
                group: self::GROUP_DEPARTMENT,
                channels: [$db, $push],
                locked: [],
                significance: 'important',
                eligibility: static fn (EligibilityContext $c): ?string => $c->isActive() ? 'active_member' : null,
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS,
                label: 'Daily Checkout reviews',
                description: 'A Daily Checkout needs your approval, significant damage was reported, or a member replied and is waiting on you. A routine checkout that needs no review does not alert anyone. In-app alerts for these are always on.',
                group: self::GROUP_FLEET,
                channels: $all,
                locked: [$db],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => InspectionReviewers::canReview($c->user)
                    ? 'inspection_reviewer' : null,
                candidates: $fleetReviewers,
            ),
            // Restores the old "tell me when a vehicle inspection is submitted" choice. The bell is on by default;
            // phone alerts stay off (one per apparatus checkout a day would bury real alerts, see
            // NotificationPresetPolicy). A saved choice never grants access: a person who stops being a
            // reviewer stops receiving it at once.
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_DAILY_CHECKOUT_EVERY_SUBMISSION,
                label: "Every Daily Checkout as it's submitted",
                description: 'A message each time a crew submits a Daily Checkout, even when nothing is wrong. It shows in the bell; phone alerts for it are off unless you turn them on.',
                group: self::GROUP_FLEET,
                channels: $all,
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => InspectionReviewers::canReview($c->user)
                    ? 'inspection_reviewer' : null,
                candidates: $fleetReviewers,
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED,
                label: 'Vehicle swap reported',
                description: 'A crew reported a different truck than the one assigned to their unit, and Fleet needs to decide.',
                group: self::GROUP_FLEET,
                channels: $all,
                locked: [],
                significance: 'important',
                eligibility: static fn (EligibilityContext $c): ?string => $c->user->canManageFleetSwaps()
                    ? 'fleet_swap_manager' : null,
                candidates: $audience(['super_admin'], ['admin.fleet.manage']),
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_VEHICLE_IDENTIFICATION,
                label: 'Vehicle identification for Fleet',
                description: 'A crew answer created a new vehicle record to verify, or does not match the vehicle record we hold, and Fleet needs to look.',
                group: self::GROUP_FLEET,
                channels: $all,
                locked: [],
                significance: 'important',
                eligibility: static fn (EligibilityContext $c): ?string => $c->user->canManageFleetSwaps()
                    ? 'fleet_swap_manager' : null,
                candidates: $audience(['super_admin'], ['admin.fleet.manage']),
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS,
                label: 'Apparatus service tickets',
                description: 'A member opened a repair, maintenance or service ticket for an apparatus.',
                group: self::GROUP_FLEET,
                channels: $all,
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->can('viewAny', ApparatusServiceTicket::class)
                    ? 'can_view_service_tickets' : null,
                candidates: $audience(['super_admin', 'admin', 'logistics_admin'], ['admin.fleet.view']),
            ),
            new NotificationCategory(
                key: 'vehicle_maintenance_alerts',
                label: 'Vehicle maintenance due',
                description: 'An apparatus is due or overdue for preventive maintenance.',
                group: self::GROUP_FLEET,
                channels: [$db],
                locked: [],
                significance: 'important',
                eligibility: static fn (EligibilityContext $c): ?string => InspectionReviewers::canReview($c->user)
                    ? 'inspection_reviewer' : null,
                candidates: $fleetReviewers,
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_STATION_INSPECTIONS,
                label: 'Station inspections',
                description: 'A station inspection was submitted.',
                group: self::GROUP_STATIONS,
                channels: $all,
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->can('viewAny', StationInspection::class)
                    ? 'can_view_station_inspections' : null,
                candidates: $stationViewers,
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_STATION_REQUESTS,
                label: 'Station requests',
                description: 'A station repair, service or equipment request was submitted.',
                group: self::GROUP_STATIONS,
                channels: $all,
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->can('admin.stations.view')
                    ? 'permission:admin.stations.view' : null,
                candidates: $stationViewers,
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_STATION_INVENTORY_ALERTS,
                label: 'Station inventory',
                description: 'A station sent in an inventory alert.',
                group: self::GROUP_STATIONS,
                channels: $all,
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->can('viewAny', Station::class)
                    ? 'can_view_stations' : null,
                candidates: $audience(['super_admin'], ['admin.stations.view', 'view_any_station']),
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_UNIFORM_REQUESTS,
                label: 'Uniform orders',
                description: 'A new uniform order or a member reply needs Support Services review.',
                group: self::GROUP_SUPPORT_SERVICES,
                channels: $all,
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->can('viewAny', PersonnelRequest::class)
                    ? 'can_review_personnel_requests' : null,
                candidates: $personnelReviewers,
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_PERSONNEL_EQUIPMENT_REQUESTS,
                label: 'Personal equipment requests',
                description: 'A new personal equipment request or a member reply needs Support Services review.',
                group: self::GROUP_SUPPORT_SERVICES,
                channels: $all,
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->can('viewAny', PersonnelRequest::class)
                    ? 'can_review_personnel_requests' : null,
                candidates: $personnelReviewers,
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_WORKGROUP_EVALUATIONS,
                label: 'Workgroup evaluations',
                description: 'A workgroup member submitted a product evaluation.',
                group: self::GROUP_TRAINING,
                channels: $all,
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->can('viewAny', EvaluationSubmission::class)
                    ? 'can_view_evaluations' : null,
                candidates: $audience(['super_admin'], ['admin.workgroups.view', 'view_any_evaluation::submission']),
            ),
            new NotificationCategory(
                key: 'training_assignments',
                label: 'Training tasks assigned to me',
                description: 'A training to-do was assigned to you.',
                group: self::GROUP_TRAINING,
                channels: [$db, $push],
                locked: [],
                significance: 'important',
                eligibility: static fn (EligibilityContext $c): ?string => self::canEnterTrainingPanel($c)
                    ? 'training_panel_access' : null,
                candidates: $audience(['super_admin', 'training_admin', 'training_viewer'], ['training.access']),
            ),
            new NotificationCategory(
                key: User::NOTIFICATION_PREFERENCE_HUB_SUPPORT_TICKETS,
                label: 'Website and app problem reports',
                description: 'Members reported a problem with MBFD Hub.',
                group: self::GROUP_ADMIN,
                channels: $all,
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->user->hasCurrentAdminPanelEntitlement()
                    && $c->can('viewAny', HubSupportTicket::class)
                    ? 'can_view_support_reports' : null,
                candidates: $audience(['super_admin'], ['admin.support.view', 'admin.support.manage']),
            ),
            new NotificationCategory(
                key: 'system_alerts',
                label: 'Backup and system alerts',
                description: 'A backup or system check failed and an administrator needs to look. In-app alerts for these are always on.',
                group: self::GROUP_ADMIN,
                channels: [$db, $push],
                locked: [$db],
                significance: 'critical',
                eligibility: static function (EligibilityContext $c): ?string {
                    if ($c->hasAnyRole('super_admin')) {
                        return 'role:super_admin';
                    }
                    if ($c->hasAnyRole('admin')) {
                        return 'role:admin';
                    }

                    return $c->user->hasCurrentAdminPanelEntitlement() && $c->hasDirectPermission('admin.system.view')
                        ? 'permission:admin.system.view' : null;
                },
                candidates: $audience(['super_admin', 'admin'], ['admin.system.view']),
            ),
            new NotificationCategory(
                key: self::PROJECT_ALERTS,
                label: 'Project alerts',
                description: 'An overdue project or milestone, an upcoming milestone, a high-priority project and the weekly project summary.',
                group: self::GROUP_ADMIN,
                channels: [$db],
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->hasAnyRole('super_admin', 'admin', 'project-manager')
                    && $c->can('viewAny', CapitalProject::class)
                    ? 'project_manager' : null,
                candidates: $audience(['super_admin', 'admin', 'project-manager'], []),
            ),
            // Whoever the messaging access class lets into the Support Services inbox (never a Gate ability: the global
            // Gate::before would answer true for every super_admin). The prefilter is a superset; the rule is asked live.
            new NotificationCategory(
                key: self::SUPPORT_SERVICES_MESSAGES,
                label: 'Support Services messages',
                description: 'A member started a conversation with Support Services or replied to one. Push and email only say there is a new message; the text stays in the Hub. In-app alerts for these are always on.',
                group: self::GROUP_SUPPORT_SERVICES,
                channels: $all,
                locked: [$db],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => app(EmployeeLogisticsMessagingAccess::class)->canViewLogisticsInbox($c->user)
                    ? 'support_services_inbox' : null,
                candidates: $audience(['super_admin', EmployeeLogisticsMessagingAccess::LOGISTICS_ROLE], [EmployeeLogisticsMessagingAccess::PERMISSION_VIEW, EmployeeLogisticsMessagingAccess::PERMISSION_REPLY]),
            ),
            new NotificationCategory(
                key: self::EQUIPMENT_EXPIRATIONS,
                label: 'Assigned equipment expiring',
                description: 'Personal equipment assigned to a member is expiring or has expired and Support Services needs to replace it.',
                group: self::GROUP_SUPPORT_SERVICES,
                channels: [$db],
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->can('viewAny', AssignedEquipment::class)
                    ? 'can_view_assigned_equipment' : null,
                candidates: $audience(['super_admin', 'admin', 'logistics_admin'], ['admin.equipment.view', 'admin.equipment.manage']),
            ),
            new NotificationCategory(
                key: self::TODO_ASSIGNMENTS,
                label: 'To-dos assigned to me',
                description: 'Someone assigned an administration to-do to you.',
                group: self::GROUP_ADMIN,
                channels: [$db],
                locked: [],
                significance: 'routine',
                eligibility: static fn (EligibilityContext $c): ?string => $c->user->hasCurrentAdminPanelEntitlement()
                    && $c->can('viewAny', Todo::class)
                    ? 'admin_panel_access' : null,
                candidates: $audience(['super_admin'], ['admin.access']),
            ),
            // Eligibility is the one Captain 5 decision (Captain5Access), asked again every
            // time, so a person whose access ends stops receiving these alerts at once.
            new NotificationCategory(
                key: self::CAPTAIN5_EMS,
                label: 'Captain 5 EMS alerts',
                description: 'EMS equipment findings, messages and updates for the on-duty Captain 5 and the officers working an issue. In-app alerts for these are always on.',
                group: self::GROUP_COMMAND,
                channels: $all,
                locked: [$db],
                significance: 'important',
                eligibility: static fn (EligibilityContext $c): ?string => self::captain5Allows($c->user)
                    ? 'captain5_duty_access' : null,
            ),
            // Whoever may approve an acting Captain 5 or acting 300 (not the same people as the workspace audience).
            new NotificationCategory(
                key: self::CAPTAIN5_ASSIGNMENT_REQUESTS,
                label: 'Acting Captain 5 and 300 requests',
                description: 'A member asked to be shown as acting Captain 5 or acting 300 and an administrator needs to decide. In-app alerts for these are always on.',
                group: self::GROUP_COMMAND,
                channels: $all,
                locked: [$db],
                significance: 'important',
                eligibility: static fn (EligibilityContext $c): ?string => self::captain5ManagesAssignments($c->user)
                    ? 'captain5_assignment_manager' : null,
            ),
            new NotificationCategory(
                key: 'command_communications',
                label: '300 Command messages',
                description: 'Messages sent to the 300 Command channel.',
                group: self::GROUP_COMMAND,
                channels: $all,
                locked: [],
                significance: 'important',
                eligibility: static fn (EligibilityContext $c): ?string => app(Command300Access::class)->allows($c->user)
                    ? 'command_300_access' : null,
                senderWired: false,
            ),
        ];

        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }

        return $byKey;
    }

    private static function canEnterTrainingPanel(EligibilityContext $context): bool
    {
        try {
            return $context->user->canAccessPanel(Filament::getPanel('training'));
        } catch (Throwable) {
            return false;
        }
    }

    private static function captain5Allows(User $user): bool
    {
        try {
            return app(Captain5Access::class)->decide($user)->canViewData;
        } catch (Throwable) {
            return false;
        }
    }

    /** Who decides acting Captain 5 and acting 300 requests: any administrator (ActingApprovers), the same people Captain5Notifier asks. */
    private static function captain5ManagesAssignments(User $user): bool
    {
        try {
            return app(ActingApprovers::class)->canApproveAny($user);
        } catch (Throwable) {
            return false;
        }
    }
}
