<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\PushDeviceHealth;
use App\Support\Workgroups\WorkgroupAccess;
use DateTimeInterface;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

/** @property \Carbon\Carbon|null $email_verified_at */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, HasRoles, Notifiable;

    /**
     * Hub users authenticate through canonical browser sessions or the
     * dedicated federation protocols. General-purpose Sanctum PAT issuance is
     * deliberately unavailable.
     *
     * @param  list<string>  $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): never
    {
        throw new \LogicException('Personal access token issuance is disabled for Hub users.');
    }

    public const NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS = 'vehicle_inspections';

    /** Opt-in: a message for every Daily Checkout as it is submitted (reviewers only, all channels off until chosen). */
    public const NOTIFICATION_PREFERENCE_DAILY_CHECKOUT_EVERY_SUBMISSION = 'daily_checkout_every_submission';

    public const NOTIFICATION_PREFERENCE_STATION_INSPECTIONS = 'station_inspections';

    public const NOTIFICATION_PREFERENCE_FIRE_EQUIPMENT_REQUESTS = 'fire_equipment_requests';

    public const NOTIFICATION_PREFERENCE_STATION_REQUESTS = 'station_requests';

    public const NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS = 'apparatus_service_tickets';

    public const NOTIFICATION_PREFERENCE_WORKGROUP_EVALUATIONS = 'workgroup_evaluations';

    public const NOTIFICATION_PREFERENCE_STATION_INVENTORY_ALERTS = 'station_inventory_alerts';

    public const NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES = 'department_updates';

    public const NOTIFICATION_PREFERENCE_HUB_SUPPORT_TICKETS = 'hub_support_tickets';

    public const NOTIFICATION_PREFERENCE_UNIFORM_REQUESTS = 'uniform_requests';

    public const NOTIFICATION_PREFERENCE_PERSONNEL_EQUIPMENT_REQUESTS = 'personnel_equipment_requests';

    public const NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES = 'member_request_updates';

    public const NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED = 'vehicle_swap_reports';

    public const NOTIFICATION_PREFERENCE_VEHICLE_IDENTIFICATION = 'vehicle_identification_reports';

    public const NOTIFICATION_PREFERENCE_FLEET_OPERATIONAL_ALERTS = 'fleet_operational_alerts';

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
        self::NOTIFICATION_PREFERENCE_DAILY_CHECKOUT_EVERY_SUBMISSION => false,
        self::NOTIFICATION_PREFERENCE_STATION_INSPECTIONS => true,
        self::NOTIFICATION_PREFERENCE_FIRE_EQUIPMENT_REQUESTS => true,
        self::NOTIFICATION_PREFERENCE_STATION_REQUESTS => true,
        self::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS => true,
        self::NOTIFICATION_PREFERENCE_WORKGROUP_EVALUATIONS => true,
        self::NOTIFICATION_PREFERENCE_STATION_INVENTORY_ALERTS => true,
        self::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES => true,
        self::NOTIFICATION_PREFERENCE_HUB_SUPPORT_TICKETS => false,
        self::NOTIFICATION_PREFERENCE_UNIFORM_REQUESTS => true,
        self::NOTIFICATION_PREFERENCE_PERSONNEL_EQUIPMENT_REQUESTS => true,
        self::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES => true,
        self::NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED => true,
        self::NOTIFICATION_PREFERENCE_VEHICLE_IDENTIFICATION => true,
        self::NOTIFICATION_PREFERENCE_FLEET_OPERATIONAL_ALERTS => true,
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
        'bootstrap_onboarding_eligible',
        'bootstrap_onboarding_eligible_at',
        'bootstrap_onboarding_completed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'temporary_credential_fingerprint',
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
            'media_control_security_version' => 'integer',
            'must_change_password' => 'boolean',
            'notification_preferences' => 'array',
            'last_login_at' => 'datetime',
            'bootstrap_onboarding_eligible' => 'boolean',
            'bootstrap_onboarding_eligible_at' => 'datetime',
            'bootstrap_onboarding_completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Accounts cannot be deleted. Use the audited account-disable action.');
        });

        static::saving(function (self $user): void {
            if ($user->employee_profile_id === null
                || ($user->exists && ! $user->isDirty([...Employee::PROFILE_FIELDS, 'employee_profile_id']))) {
                return;
            }
            if ($user->exists && ! $user->isDirty('employee_profile_id')) {
                // Discard direct profile edits instead of issuing a stale snapshot
                // UPDATE that could overwrite a concurrent personnel-service save.
                foreach (Employee::PROFILE_FIELDS as $field) {
                    if ($user->isDirty($field)) {
                        $user->setAttribute($field, $user->getRawOriginal($field));
                    }
                }

                return;
            }
            $employee = Employee::query()->find($user->employee_profile_id);
            if ($employee === null || $employee->employee_id !== $user->employee_id) {
                return;
            }
            foreach (Employee::PROFILE_FIELDS as $field) {
                $user->setAttribute($field, $employee->getAttribute($field));
            }
            if ($user->relationLoaded('employeeProfile')) {
                $user->setRelation('employeeProfile', $employee);
            }
        });

        static::updating(function (self $user): void {
            if ($user->isDirty(['email', 'employee_profile_id'])) {
                $user->email_verified_at = null;
            }
        });
    }

    public function isAuthenticationAllowed(): bool
    {
        if ($this->getRawOriginal('account_status') !== AccountStatus::Active->value) {
            return false;
        }

        if ($this->employee_profile_id === null) {
            return true;
        }

        return $this->hasCurrentPersonnelIdentity();
    }

    public function isBootstrapOnboardingPending(): bool
    {
        if (! $this->bootstrap_onboarding_eligible
            || $this->getRawOriginal('account_status') !== AccountStatus::PendingActivation->value
            || $this->bootstrap_onboarding_completed_at !== null
            || $this->employee_profile_id === null) {
            return false;
        }

        return Employee::query()
            ->whereKey($this->employee_profile_id)
            ->where('employee_id', $this->employee_id)
            ->where('roster_status', 'active')
            ->exists();
    }

    public function isUpstreamIdentityEnabled(): bool
    {
        if (! in_array($this->getRawOriginal('account_status'), [
            AccountStatus::Active->value,
            AccountStatus::PendingActivation->value,
        ], true) || $this->employee_profile_id === null) {
            return false;
        }

        return $this->hasCurrentPersonnelIdentity();
    }

    private function hasCurrentPersonnelIdentity(): bool
    {
        return Employee::query()
            ->whereKey($this->employee_profile_id)
            ->where('employee_id', $this->employee_id)
            ->where(function ($query): void {
                $query->whereNull('roster_status')->orWhere('roster_status', '!=', 'departed');
            })
            ->exists();
    }

    public function getAttribute($key)
    {
        // Never lazy-load per field/user. Eager-loaded personnel is authoritative;
        // other consumers use the synchronized compatibility snapshot.
        if (in_array($key, Employee::PROFILE_FIELDS, true)
            && $this->relationLoaded('employeeProfile')) {
            $employee = $this->getRelation('employeeProfile');
            if ($employee instanceof Employee && array_key_exists($key, $employee->getAttributes())
                && $employee->getKey() === $this->getAttribute('employee_profile_id')
                && $employee->employee_id === $this->getAttribute('employee_id')) {
                return $employee->getAttribute($key);
            }
        }

        return parent::getAttribute($key);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_profile_id');
    }

    /** @return HasOne<MemberOnboardingInvitation, $this> */
    public function memberOnboardingInvitation(): HasOne
    {
        return $this->hasOne(MemberOnboardingInvitation::class);
    }

    /** @return HasMany<AuthenticationSession, $this> */
    public function authenticationSessions(): HasMany
    {
        return $this->hasMany(AuthenticationSession::class);
    }

    /** @return HasMany<UserIdentityLink, $this> */
    public function identityLinks(): HasMany
    {
        return $this->hasMany(UserIdentityLink::class);
    }

    /** @return HasMany<IdentitySynchronization, $this> */
    public function identitySynchronizations(): HasMany
    {
        return $this->hasMany(IdentitySynchronization::class);
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

    /**
     * Every alert category with its plain-language label and description. The
     * list lives in NotificationCategoryRegistry; this keeps the legacy shape.
     *
     * @return array<string, array{label: string, description: string, essential_in_app?: bool}>
     */
    public static function notificationPreferenceDefinitions(): array
    {
        return app(NotificationCategoryRegistry::class)->legacyDefinitions();
    }

    /**
     * The member's push devices. The package trait types this as its own PushSubscription; the
     * Hub's configured model (config/webpush.php) is HubPushSubscription, which carries device health.
     *
     * @return MorphMany<HubPushSubscription, $this>
     */
    public function pushSubscriptions(): MorphMany
    {
        return $this->morphMany(HubPushSubscription::class, 'subscribable');
    }

    /**
     * Push devices an alert may be sent to: healthy ones, or exactly the devices a
     * notification asks for (the "send a test" button targets one of the member's own).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, HubPushSubscription>
     */
    public function routeNotificationForWebPush(mixed $notification = null): \Illuminate\Database\Eloquent\Collection
    {
        if (is_object($notification) && method_exists($notification, 'pushDevicesFor')) {
            return $notification->pushDevicesFor($this);
        }

        return PushDeviceHealth::scopeHealthy($this->pushSubscriptions())->get();
    }

    /**
     * Preference keys this member is eligible for right now, so settings never show alerts
     * they cannot act on. Evaluated from current roles and permissions, never stored.
     *
     * @return list<string>
     */
    public function visibleNotificationPreferenceKeys(): array
    {
        return app(NotificationCategoryRegistry::class)->forUser($this)->keys()->all();
    }

    /** Server-side Fleet manage access: super administrators and the admin.fleet.manage capability. */
    public function canManageFleetSwaps(): bool
    {
        return $this->isAuthenticationAllowed() && ($this->hasRole('super_admin')
            || ($this->hasDirectWebPermission('admin.access') && $this->hasDirectWebPermission('admin.fleet.manage')));
    }

    public static function preferenceKeyForSubmissionType(string $submissionType): ?string
    {
        return match ($submissionType) {
            'apparatus_inspection' => self::NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS,
            'daily_checkout_every_submission' => self::NOTIFICATION_PREFERENCE_DAILY_CHECKOUT_EVERY_SUBMISSION,
            'station_inspection' => self::NOTIFICATION_PREFERENCE_STATION_INSPECTIONS,
            'station_request' => self::NOTIFICATION_PREFERENCE_STATION_REQUESTS,
            'apparatus_service_ticket' => self::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS,
            'evaluation_submission' => self::NOTIFICATION_PREFERENCE_WORKGROUP_EVALUATIONS,
            'hub_support_ticket' => self::NOTIFICATION_PREFERENCE_HUB_SUPPORT_TICKETS,
            'station_inventory_submission' => self::NOTIFICATION_PREFERENCE_STATION_INVENTORY_ALERTS,
            'uniform_request' => self::NOTIFICATION_PREFERENCE_UNIFORM_REQUESTS,
            'personnel_equipment_request' => self::NOTIFICATION_PREFERENCE_PERSONNEL_EQUIPMENT_REQUESTS,
            'member_request_update' => self::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES,
            'vehicle_swap_reported' => self::NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED,
            'vehicle_identification_reported' => self::NOTIFICATION_PREFERENCE_VEHICLE_IDENTIFICATION,
            'fleet_operational_alert' => self::NOTIFICATION_PREFERENCE_FLEET_OPERATIONAL_ALERTS,
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

    /** Every active member has alert settings; which categories appear depends on what they may receive. */
    public function canManageNotificationSettings(): bool
    {
        return $this->isAuthenticationAllowed();
    }

    /**
     * Determine if the user can access the Filament admin panel.
     * Training users are allowed through admin panel auth check so the
     * RedirectTrainingUsers middleware can redirect them to /training.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isAuthenticationAllowed()) {
            return false;
        }

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

    public function canSubmitTrainingRosters(): bool
    {
        return $this->isAuthenticationAllowed()
            && ! $this->must_change_password
            && $this->hasCurrentAdminPanelEntitlement()
            && ($this->hasRole('super_admin') || $this->hasDirectWebPermission('training.rosters.submit'));
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
