<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VehicleDocumentEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document audit events are immutable.'));
        static::deleting(fn () => throw new LogicException('Document audit events must be retained.'));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(VehicleDocument::class, 'document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
