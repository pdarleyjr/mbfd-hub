<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelRequestAttachment extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<PersonnelRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PersonnelRequest::class, 'personnel_request_id');
    }
}
