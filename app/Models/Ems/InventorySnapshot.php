<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** A physical count, a calculated reconciliation or a baseline. A `final` snapshot never changes. */
class InventorySnapshot extends Model
{
    public const KIND_COUNT = 'physical_count';

    public const KIND_RECONCILIATION = 'reconciliation';

    public const KIND_BASELINE = 'baseline';

    public const DRAFT = 'draft';

    public const FINAL = 'final';

    protected $table = 'ems_inventory_snapshots';

    protected $fillable = [
        'uuid', 'version', 'kind', 'status', 'scope_location_code', 'started_by_user_id', 'started_at', 'finalized_by_user_id', 'finalized_at',
        'finalized_name', 'cutoff_at', 'through_movement_id', 'previous_snapshot_id', 'idempotency_key', 'notes',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $snapshot): void {
            if ($snapshot->getOriginal('status') === self::FINAL) {
                throw new LogicException('A final inventory snapshot is immutable.');
            }
        });
        static::deleting(static function (self $snapshot): void {
            if ($snapshot->status === self::FINAL) {
                throw new LogicException('A final inventory snapshot cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'started_at' => 'datetime',
            'finalized_at' => 'datetime',
            'cutoff_at' => 'datetime',
            'through_movement_id' => 'integer',
        ];
    }

    public function isFinal(): bool
    {
        return $this->status === self::FINAL;
    }

    /** @return HasMany<InventorySnapshotLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventorySnapshotLine::class, 'snapshot_id')->orderBy('id');
    }

    /** @return BelongsTo<self, $this> */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_snapshot_id');
    }
}
