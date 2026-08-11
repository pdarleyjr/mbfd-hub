<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Employee model — completely separate from the users table.
 * Used exclusively for the Employee Portal (/employee) panel.
 * Authentication: employee_id (as "username") + password.
 *
 * This model is NEVER used for Admin, Training, or Workgroup panels.
 */
class Employee extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $table = 'employees';

    protected $fillable = [
        'employee_id',
        'name',
        'rank',
        'password',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'must_change_password' => 'boolean',
    ];

    /**
     * NOTE: Do NOT override getAuthIdentifierName() here.
     * The default returns 'id' (auto-increment primary key) which is required
     * for session-based auth (retrieveById uses Model::find($id)).
     *
     * Employee ID-based credential matching is handled by EmployeeLogin::getCredentialsFromFormData()
     * which passes ['employee_id' => '...'] to EloquentUserProvider::retrieveByCredentials().
     */

    /**
     * Required for Filament user menu display.
     */
    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * Allow all authenticated Employees to access the employee panel.
     * Admin/Workgroup/Training panels will use the users table guard — never this guard.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'employee';
    }

    // Relationships
    public function assignedEquipment(): HasMany
    {
        return $this->hasMany(AssignedEquipment::class, 'employee_portal_id');
    }

    public function equipmentRequests(): HasMany
    {
        return $this->hasMany(EmployeeEquipmentRequest::class, 'employee_portal_id');
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
}
