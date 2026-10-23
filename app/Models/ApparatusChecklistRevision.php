<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An effective checklist that was issued to members and then replaced by an approved
 * inventory change. Kept so work started on it (open forms, queued offline
 * submissions) can still be validated against exactly what the member saw.
 */
final class ApparatusChecklistRevision extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'checklist' => 'array',
        'retired_at' => 'datetime',
    ];

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }
}
