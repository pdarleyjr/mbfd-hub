<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** @property array<string, mixed>|null $metadata */
final class ApparatusInspectionException extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'submitted_value' => 'decimal:1', 'baseline_value' => 'decimal:1', 'authoritative_value' => 'decimal:1'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $exception): void {
            if ($exception->isDirty(['apparatus_inspection_id', 'apparatus_id', 'field', 'reason', 'submitted_value', 'baseline_value', 'authoritative_value', 'metadata'])) {
                throw new LogicException('Original inspection exception evidence is immutable.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Inspection exception evidence cannot be deleted.'));
    }

    /** @return BelongsTo<ApparatusInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }
}
