<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A physical count, a calculated reconciliation or a baseline. A `final` snapshot never changes.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $version
 * @property string $kind
 * @property string $status
 * @property string|null $scope_location_code
 * @property int|null $started_by_user_id
 * @property \Illuminate\Support\Carbon $started_at
 * @property int|null $finalized_by_user_id
 * @property \Illuminate\Support\Carbon|null $finalized_at
 * @property string|null $finalized_name
 * @property \Illuminate\Support\Carbon|null $cutoff_at
 * @property int|null $through_movement_id
 * @property int|null $previous_snapshot_id
 * @property string|null $idempotency_key
 * @property string|null $notes
 * @property string|null $origin
 * @property int|null $counted_items
 * @property int|null $uncounted_items
 * @property int|null $order_items
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class InventorySnapshot extends Model
{
    public const KIND_COUNT = 'physical_count';

    public const KIND_RECONCILIATION = 'reconciliation';

    public const KIND_BASELINE = 'baseline';

    public const DRAFT = 'draft';

    public const FINAL = 'final';

    /** The Monday count form. */
    public const ORIGIN_COUNT_FORM = 'count_form';

    /** One item's on-hand number typed directly on the master inventory. */
    public const ORIGIN_DIRECT_EDIT = 'direct_edit';

    /** The starting counts from the supply room workbook. */
    public const ORIGIN_WORKBOOK = 'workbook';

    protected $table = 'ems_inventory_snapshots';

    protected $fillable = [
        'uuid', 'version', 'kind', 'origin', 'status', 'scope_location_code', 'started_by_user_id', 'started_at', 'finalized_by_user_id', 'finalized_at',
        'finalized_name', 'cutoff_at', 'through_movement_id', 'previous_snapshot_id', 'idempotency_key', 'notes',
        'counted_items', 'uncounted_items', 'order_items',
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
            'counted_items' => 'integer',
            'uncounted_items' => 'integer',
            'order_items' => 'integer',
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
