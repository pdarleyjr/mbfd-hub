<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Models\Concerns\HasArchive;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property int|null $archived_by
 * @property string|null $archive_reason
 * @property bool|null $over_allowance
 * @property string|null $allowance_justification
 */
class PersonnelRequest extends Model
{
    use HasArchive;
    use SoftDeletes;

    protected $guarded = [];

    private bool $trashLifecycleAuthorized = false;

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
        'over_allowance' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(static function (self $request): void {
            if ($request->isForceDeleting() || ! $request->trashLifecycleAuthorized) {
                throw new LogicException('Use the audited personnel request Trash action. Permanent deletion is not supported.');
            }
        });
        static::restoring(static function (self $request): void {
            if (! $request->trashLifecycleAuthorized) {
                throw new LogicException('Use the audited personnel request restore action.');
            }
        });
    }

    /** @internal Called by PersonnelRequestLifecycleService inside its locked audit transaction. */
    public function deleteToTrash(User $actor, string $reason): void
    {
        Gate::forUser($actor)->authorize('delete', $this);
        $reason = trim($reason);
        validator(['deletion_reason' => $reason], ['deletion_reason' => ['required', 'string', 'max:2000']])->validate();
        if ($this->type !== PersonnelRequestType::Uniform) {
            throw ValidationException::withMessages(['deletion_reason' => 'Only uniform orders can be moved to Trash with this action.']);
        }
        if ($this->items()->withCount('assignments')->get()->contains(fn (PersonnelRequestItem $item): bool => $item->arrived_quantity > 0 || $item->fulfilled_quantity > 0
            || in_array($item->fulfillment_status, ['arrived', 'partially_arrived', 'fulfilled', 'partially_fulfilled'], true)
            || (int) $item->getAttribute('assignments_count') > 0)) {
            throw ValidationException::withMessages(['deletion_reason' => 'This order has arrived or issued goods. Resolve the inventory and fulfillment record with Support Services before deletion.']);
        }
        if (! in_array($this->status, [PersonnelRequestStatus::Cancelled, PersonnelRequestStatus::Denied], true)) {
            throw ValidationException::withMessages(['deletion_reason' => 'Cancel or deny this unissued order before deleting it. Orders awaiting pickup or already completed must be resolved with Support Services.']);
        }
        $this->applyTrashLifecycle(function () use ($actor, $reason): void {
            $this->forceFill(['deleted_by' => $actor->id, 'deletion_reason' => $reason])->save();
            $this->delete();
        });
    }

    /** @internal Called by PersonnelRequestLifecycleService inside its locked audit transaction. */
    public function restoreFromTrash(User $actor): void
    {
        Gate::forUser($actor)->authorize('restore', $this);
        if ($this->type !== PersonnelRequestType::Uniform) {
            throw ValidationException::withMessages(['deletion_reason' => 'Only uniform orders can be restored with this action.']);
        }
        $this->applyTrashLifecycle(function (): void {
            $this->restore();
            $this->forceFill(['deleted_by' => null, 'deletion_reason' => null])->save();
        });
    }

    private function applyTrashLifecycle(Closure $operation): void
    {
        $this->trashLifecycleAuthorized = true;
        try {
            $operation();
        } finally {
            $this->trashLifecycleAuthorized = false;
        }
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Employee, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'beneficiary_employee_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_employee_id');
    }

    /** @return BelongsTo<Station, $this> */
    public function originatingStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'originating_station_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    /** @return HasMany<PersonnelRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PersonnelRequestItem::class);
    }

    /** @return HasMany<PersonnelRequestUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(PersonnelRequestUpdate::class)->oldest();
    }

    /** @return HasMany<PersonnelRequestAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(PersonnelRequestAttachment::class);
    }
}
