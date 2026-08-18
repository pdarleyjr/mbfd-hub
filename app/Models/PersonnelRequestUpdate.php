<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonnelRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelRequestUpdate extends Model
{
    protected $guarded = [];

    protected $casts = ['status' => PersonnelRequestStatus::class, 'metadata' => 'array'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(PersonnelRequest::class, 'personnel_request_id');
    }
}
