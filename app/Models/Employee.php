<?php

namespace App\Models;

use App\Services\Identity\EmployeeBootstrapCredentialProvisioner;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Operational personnel profile linked from the canonical User model.
 *
 * Employee records retain historical domain data and the transitional
 * first-login credential used to create or claim a canonical User.
 *
 * @property string|null $city_email
 * @property string|null $roster_status
 */
class Employee extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'employees';

    protected $fillable = [
        'employee_id',
        'name',
        'rank',
        'password',
        'must_change_password',
        'city_email',
        'roster_status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'must_change_password' => 'boolean',
    ];

    protected function cityEmail(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value,
            set: fn (?string $value): ?string => blank($value) ? null : strtolower(trim($value)),
        );
    }

    protected static function booted(): void
    {
        static::creating(function (self $employee): void {
            if (($employee->getAttributes()['password'] ?? null) !== null) {
                return;
            }

            $employee->forceFill(
                app(EmployeeBootstrapCredentialProvisioner::class)->attributesForNewEmployee(),
            );
        });
    }

    // Relationships
    /** @return HasMany<AssignedEquipment, $this> */
    public function assignedEquipment(): HasMany
    {
        return $this->hasMany(AssignedEquipment::class, 'employee_portal_id');
    }

    /** @return HasMany<EmployeeEquipmentRequest, $this> */
    public function equipmentRequests(): HasMany
    {
        return $this->hasMany(EmployeeEquipmentRequest::class, 'employee_portal_id');
    }

    /** @return HasMany<PersonnelRequest, $this> */
    public function personnelRequests(): HasMany
    {
        return $this->hasMany(PersonnelRequest::class, 'beneficiary_employee_id');
    }

    /** @return HasMany<PersonnelRequest, $this> */
    public function submittedPersonnelRequests(): HasMany
    {
        return $this->hasMany(PersonnelRequest::class, 'requester_employee_id');
    }

    /** Station-scoped requests; intentionally separate from personal equipment requests. */
    public function stationRequests(): HasMany
    {
        return $this->hasMany(StationRequest::class, 'requested_by_employee_id');
    }

    /** @return HasMany<ApparatusServiceTicket, $this> */
    public function apparatusServiceTickets(): HasMany
    {
        return $this->hasMany(ApparatusServiceTicket::class, 'requested_by_employee_id');
    }

    public function operationalFormRecords(): HasMany
    {
        return $this->hasMany(OperationalFormRecord::class);
    }

    public function operationalFormDocuments(): HasMany
    {
        return $this->hasMany(OperationalFormDocument::class, 'created_by_employee_id');
    }

    public function videoConferenceParticipations(): HasMany
    {
        return $this->hasMany(VideoConferenceParticipation::class);
    }

    public function createdVideoConferenceSessions(): HasMany
    {
        return $this->hasMany(VideoConferenceSession::class, 'created_by_employee_id');
    }

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'employee_profile_id');
    }
}
