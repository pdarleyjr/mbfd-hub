<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Support\Workgroups\WorkgroupAccess;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, HasRoles, Notifiable;

    public const NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS = 'vehicle_inspections';

    public const NOTIFICATION_PREFERENCE_STATION_INSPECTIONS = 'station_inspections';

    public const NOTIFICATION_PREFERENCE_FIRE_EQUIPMENT_REQUESTS = 'fire_equipment_requests';

    public const NOTIFICATION_PREFERENCE_STATION_REQUESTS = 'station_requests';

    public const NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS = 'apparatus_service_tickets';

    public const NOTIFICATION_PREFERENCE_WORKGROUP_EVALUATIONS = 'workgroup_evaluations';

    public const NOTIFICATION_PREFERENCE_STATION_INVENTORY_ALERTS = 'station_inventory_alerts';

    public const NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES = 'department_updates';

    /**
     * The current roles that grant access to the Filament admin panel.
     *
     * Bid consumes this same entitlement during canonical code exchange; it
     * must not maintain an independent administrator roster.
     *
     * @var list<string>
     */
    public const ADMIN_PANEL_ACCESS_ROLES = [
        'super_admin',
        'admin',
        'logistics_admin',
        'training_admin',
        'training_viewer',
    ];

    /**
     * Existing canonical roles whose operators manage classroom signage.
     * Training viewers and ordinary authenticated users are intentionally excluded.
     *
     * @var list<string>
     */
    public const MEDIA_CONTROL_ACCESS_ROLES = [
        'super_admin',
        'admin',
        'logistics_admin',
        'training_admin',
    ];

    public const DEFAULT_NOTIFICATION_PREFERENCES = [
        self::NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS => true,
        self::NOTIFICATION_PREFERENCE_STATION_INSPECTIONS => true,
        self::NOTIFICATION_PREFERENCE_FIRE_EQUIPMENT_REQUESTS => true,
        self::NOTIFICATION_PREFERENCE_STATION_REQUESTS => true,
        self::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS => true,
        self::NOTIFICATION_PREFERENCE_WORKGROUP_EVALUATIONS => true,
        self::NOTIFICATION_PREFERENCE_STATION_INVENTORY_ALERTS => true,
        self::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'display_name',
        'rank',
        'station',
        'phone',
        'must_change_password',
        'notification_preferences',
        'employee_id',
        'employee_profile_id',
        'account_status',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_status' => AccountStatus::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'security_version' => 'integer',
            'must_change_password' => 'boolean',
            'notification_preferences' => 'array',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAuthenticationAllowed(): bool
    {
        return $this->getRawOriginal('account_status') === AccountStatus::Active->value;
    }

    /** @return BelongsTo<Employee, $this> */
    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_profile_id');
    }

    /** @return HasMany<AuthenticationSession, $this> */
    public function authenticationSessions(): HasMany
    {
        return $this->hasMany(AuthenticationSession::class);
    }

    /** @return HasMany<PersistentLoginCredential, $this> */
    public function persistentLoginCredentials(): HasMany
    {
        return $this->hasMany(PersistentLoginCredential::class);
    }

    /** @return HasMany<UserNotificationSubscription, $this> */
    public function notificationSubscriptions(): HasMany
    {
        return $this->hasMany(UserNotificationSubscription::class);
    }

    /**
     * Make email case-insensitive by always storing lowercase.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => $value,
            set: fn (string $value) => strtolower($value),
        );
    }

    public static function notificationPreferenceDefinitions(): array
    {
        return [
            self::NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS => [
                'label' => 'Vehicle Inspections',
                'description' => 'Receive alerts when a vehicle inspection is submitted.',
            ],
            self::NOTIFICATION_PREFERENCE_STATION_INSPECTIONS => [
                'label' => 'Station Inspections',
                'description' => 'Receive alerts when a station inspection is submitted.',
            ],
            self::NOTIFICATION_PREFERENCE_FIRE_EQUIPMENT_REQUESTS => [
                'label' => 'Fire Equipment Requests',
                'description' => 'Receive alerts when a fire equipment request is submitted.',
            ],
            self::NOTIFICATION_PREFERENCE_STATION_REQUESTS => [
                'label' => 'Station Requests',
                'description' => 'Receive alerts for station repair, service, and equipment requests.',
            ],
            self::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS => [
                'label' => 'Apparatus Service Tickets',
                'description' => 'Receive alerts for apparatus repair, maintenance, and service tickets.',
            ],
            self::NOTIFICATION_PREFERENCE_WORKGROUP_EVALUATIONS => [
                'label' => 'Workgroup Evaluations',
                'description' => 'Receive alerts when a workgroup evaluation is submitted.',
            ],
            self::NOTIFICATION_PREFERENCE_STATION_INVENTORY_ALERTS => [
                'label' => 'Station Inventory Alerts',
                'description' => 'Receive alerts when a station inventory submission is received.',
            ],
            self::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES => [
                'label' => 'Department Updates',
                'description' => 'Receive published department notices and operational updates.',
            ],
        ];
    }

    public static function preferenceKeyForSubmissionType(string $submissionType): ?string
    {
        return match ($submissionType) {
            'apparatus_inspection' => self::NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS,
            'station_inspection' => self::NOTIFICATION_PREFERENCE_STATION_INSPECTIONS,
            'fire_equipment_request' => self::NOTIFICATION_PREFERENCE_FIRE_EQUIPMENT_REQUESTS,
            'station_request' => self::NOTIFICATION_PREFERENCE_STATION_REQUESTS,
            'apparatus_service_ticket' => self::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS,
            'evaluation_submission' => self::NOTIFICATION_PREFERENCE_WORKGROUP_EVALUATIONS,
            'station_inventory_submission' => self::NOTIFICATION_PREFERENCE_STATION_INVENTORY_ALERTS,
            default => null,
        };
    }

    public function getResolvedNotificationPreferences(): array
    {
        return array_merge(self::DEFAULT_NOTIFICATION_PREFERENCES, $this->notification_preferences ?? []);
    }

    public function wantsNotificationPreference(string $preferenceKey): bool
    {
        return (bool) ($this->getResolvedNotificationPreferences()[$preferenceKey] ?? true);
    }

    public function canManageNotificationSettings(): bool
    {
        return $this->hasAnyRole([
            'super_admin',
            'admin',
            'logistics_admin',
            'training_admin',
            'workgroup_admin',
            'workgroup_facilitator',
        ]);
    }

    /**
     * Determine if the user can access the Filament admin panel.
     * Training users are allowed through admin panel auth check so the
     * RedirectTrainingUsers middleware can redirect them to /training.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'employee') {
            return $this->employeeProfile()->exists();
        }

        if ($panel->getId() === 'training') {
            return $this->hasRole('super_admin')
                || $this->hasRole('training_admin')
                || $this->hasRole('training_viewer')
                || $this->can('training.access');
        }

        // Workgroup access is based on an active membership, never a broad panel role.
        if ($panel->getId() === 'workgroups') {
            return app(WorkgroupAccess::class)->canEnterPanel($this);
        }

        // Admin panel: allow any user with a valid role
        // Training-only users will be redirected by RedirectTrainingUsers middleware
        if ($panel->getId() === 'admin') {
            return $this->hasCurrentAdminPanelEntitlement();
        }

        return false;
    }

    /**
     * Resolve the existing Admin Panel entitlement for a fresh Bid login.
     */
    public function hasCurrentAdminPanelEntitlement(): bool
    {
        return $this->hasRole('super_admin') || $this->hasDirectWebPermission('admin.access');
    }

    public function hasCurrentMediaControlEntitlement(): bool
    {
        return $this->hasRole('super_admin') || $this->hasDirectWebPermission('app.media_control.access');
    }

    public function hasCurrentBidEntitlement(): bool
    {
        return $this->hasRole('super_admin') || $this->hasDirectWebPermission('app.bid.access');
    }

    public function hasDirectWebPermission(string $permission): bool
    {
        return $this->permissions()
            ->where('guard_name', 'web')
            ->where('name', $permission)
            ->exists();
    }

    // Relationships
    public function assignedEquipment(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\AssignedEquipment::class);
    }

    public function equipmentRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\EmployeeEquipmentRequest::class);
    }
}
