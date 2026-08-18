<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApparatusServiceTicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ApparatusServiceTicket extends Model
{
    protected $fillable = [
        'client_submission_id',
        'ticket_number',
        'apparatus_id',
        'station_id',
        'unit_designation_snapshot',
        'requested_by_employee_id',
        'created_by_user_id',
        'requester_name_snapshot',
        'origin',
        'category',
        'title',
        'description',
        'priority',
        'status',
        'service_type',
        'scheduled_for',
        'scheduled_location',
        'expected_return_at',
        'acknowledged_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'assigned_to_user_id',
        'assigned_vendor',
        'current_public_response',
        'status_detail',
        'service_engine_hours',
        'service_mileage',
        'opened_engine_hours',
        'opened_miles',
        'completed_engine_hours',
        'completed_miles',
        'resolution_summary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'expected_return_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'service_engine_hours' => 'decimal:1',
            'service_mileage' => 'integer',
            'opened_engine_hours' => 'decimal:1',
            'opened_miles' => 'integer',
            'completed_engine_hours' => 'decimal:1',
            'completed_miles' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $ticket): void {
            if ($ticket->ticket_number !== null) {
                return;
            }

            $ticket->timestamps = false;
            try {
                $ticket->forceFill([
                    'ticket_number' => sprintf(
                        'AST-%s-%06d',
                        Carbon::parse($ticket->getRawOriginal('created_at') ?? now())->format('Y'),
                        $ticket->getKey(),
                    ),
                ])->saveQuietly();
            } finally {
                $ticket->timestamps = true;
            }
        });
    }

    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function requestedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by_employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ApparatusServiceTicketUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ApparatusServiceTicketStatus::openValues());
    }

    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', ApparatusServiceTicketStatus::terminalValues());
    }

    public function getIsOpenAttribute(): bool
    {
        return in_array($this->status, ApparatusServiceTicketStatus::openValues(), true);
    }
}
