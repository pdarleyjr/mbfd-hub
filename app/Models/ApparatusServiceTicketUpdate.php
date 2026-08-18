<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ApparatusServiceTicketUpdate extends Model
{
    protected $fillable = [
        'apparatus_service_ticket_id',
        'status',
        'previous_status',
        'public_note',
        'internal_note',
        'scheduled_for',
        'changed_by_user_id',
        'changed_by_employee_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Apparatus service ticket updates are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Apparatus service ticket updates are append-only.'));
    }

    /** @return BelongsTo<ApparatusServiceTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ApparatusServiceTicket::class, 'apparatus_service_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function changedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by_employee_id');
    }
}
