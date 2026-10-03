<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonnelRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PersonnelRequestUpdate extends Model
{
    protected $guarded = [];

    protected $casts = ['status' => PersonnelRequestStatus::class, 'metadata' => 'array'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Personnel request updates are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Personnel request updates are append-only.'));
    }

    /** @return BelongsTo<PersonnelRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PersonnelRequest::class, 'personnel_request_id');
    }
}
