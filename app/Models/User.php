<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Workgroups\WorkgroupAccess;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    public const DEFAULT_NOTIFICATION_PREFERENCES = [
        self::NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS => true,
        self::NOTIFICATION_PREFERENCE_STATION_INSPECTIONS => true,
        self::NOTIFICATION_PREFERENCE_FIRE_EQUIPMENT_REQUESTS => true,
        self::NOTIFICATION_PREFERENCE_STATION_REQUESTS => true,
        self::NOTIFICATION_PREFERENCE_APPARATUS_SERVICE_TICKETS => true,
        self::NOTIFICATION_PREFERENCE_WORKGROUP_EVALUATIONS => true,
        self::NOTIFICATION_PREFERENCE_STATION_INVENTORY_ALERTS => true,
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'notification_preferences' => 'array',
        ];
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
        // Employee panel uses its own Employee model + employee guard.
        // The User model should NEVER handle the employee panel.

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
            return $this->hasRole('super_admin')
                || $this->hasRole('admin')
                || $this->hasRole('logistics_admin')
                || $this->hasRole('training_admin')
                || $this->hasRole('training_viewer');
        }

        return false;
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
