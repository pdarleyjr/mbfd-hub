<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonnelRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'type' => PersonnelRequestType::class,
        'status' => PersonnelRequestStatus::class,
        'information_requested' => 'array',
        'metadata' => 'array',
        'acknowledged_at' => 'datetime',
        'completed_at' => 'datetime',
        'denied_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'beneficiary_employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_employee_id');
    }

    public function originatingStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'originating_station_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PersonnelRequestItem::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(PersonnelRequestUpdate::class)->oldest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PersonnelRequestAttachment::class);
    }
}
