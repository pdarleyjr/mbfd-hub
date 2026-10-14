<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonnelRequestItem extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'quantity' => 'integer', 'arrived_quantity' => 'integer', 'fulfilled_quantity' => 'integer'];

    /** @return BelongsTo<PersonnelRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PersonnelRequest::class, 'personnel_request_id');
    }

    /** Member-facing progress, derived from the recorded arrival and issue quantities. */
    public function memberProgressLabel(): string
    {
        $arrived = (int) $this->arrived_quantity;
        $issued = (int) $this->fulfilled_quantity;

        return match (true) {
            $issued >= $this->quantity => 'Issued',
            $issued > 0 => 'Partially issued',
            $arrived >= $this->quantity => 'Arrived',
            $arrived > 0 => 'Partially arrived',
            $this->fulfillment_status === 'acknowledged' => 'Acknowledged',
            default => 'Awaiting arrival',
        };
    }

    public function memberProgressTagClass(): string
    {
        return match ($this->memberProgressLabel()) {
            'Issued' => 'hub-tag hub-tag--success',
            'Arrived', 'Partially issued' => 'hub-tag hub-tag--info',
            default => 'hub-tag',
        };
    }

    /** @return HasMany<AssignedEquipment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssignedEquipment::class, 'source_personnel_request_item_id');
    }
}
