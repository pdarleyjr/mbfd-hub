<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, HasPushSubscriptions;

    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password',
        'rank',
        'station_assignment',
        'shift',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Normalize email to lowercase on set to prevent case-sensitive duplicates.
     */
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = strtolower($value);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * Get workgroup sessions this user is assigned to.
     */
    public function workgroupSessions()
    {
        return $this->belongsToMany(WorkgroupSession::class, 'session_user', 'user_id', 'workgroup_session_id')
            ->withPivot('is_official_evaluator')
            ->withTimestamps();
    }

    /**
     * Get workgroup memberships.
     */
    public function workgroupMemberships()
    {
        return $this->hasMany(WorkgroupMember::class);
    }
}
