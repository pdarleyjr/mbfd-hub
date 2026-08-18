<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonnelRequestItem extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'quantity' => 'integer', 'fulfilled_quantity' => 'integer'];

    /** @return BelongsTo<PersonnelRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PersonnelRequest::class, 'personnel_request_id');
    }

    /** @return HasMany<AssignedEquipment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssignedEquipment::class, 'source_personnel_request_item_id');
    }
}
