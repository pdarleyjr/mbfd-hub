<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A member accountable for / following an issue. Updated in place, never deleted by app code. */
final class ApparatusDefectParticipant extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'first_participated_at' => 'datetime',
        'last_participated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Defect participants are never deleted by application code.'));
    }

    /** @return BelongsTo<ApparatusDefect, $this> */
    public function defect(): BelongsTo
    {
        return $this->belongsTo(ApparatusDefect::class, 'apparatus_defect_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
