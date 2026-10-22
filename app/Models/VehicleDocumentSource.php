<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VehicleDocumentSource extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (self $source): void {
            if ($source->isDirty(['document_id', 'source_relative_path', 'source_hash', 'import_batch'])) {
                throw new LogicException('Original document source references cannot be replaced.');
            }
        });
        static::deleting(fn () => throw new LogicException('Document source provenance must be retained.'));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(VehicleDocument::class, 'document_id');
    }
}
