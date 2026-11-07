<?php

declare(strict_types=1);

namespace App\Models;

use App\Filament\Resources\InventoryCorrectionResource\Pages\ListInventoryCorrections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One approved operation on one apparatus's effective checklist. Only `status`,
 * `superseded_by_change_id` and `status_changed_at` may change after creation; the
 * operation itself is immutable evidence.
 */
final class ApparatusInventoryChange extends Model
{
    public const OP_REMOVE = 'remove';

    public const OP_MODIFY = 'modify';

    public const OP_ADD = 'add';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_REVERSED = 'reversed';

    /**
     * Retired by Logistics because it no longer applied to its unit's checklist (an overlay
     * conflict, for example after a template change). Retiring has no effect on the checklist.
     */
    public const STATUS_RETIRED = 'retired';

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_ACTIVE => 'In effect',
            self::STATUS_SUPERSEDED => 'Replaced by a later change',
            self::STATUS_REVERSED => 'Reversed',
            self::STATUS_RETIRED => 'Retired (no longer applied)',
        ];
    }

    private const MUTABLE = ['status', 'superseded_by_change_id', 'status_changed_at', 'updated_at'];

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'before' => 'array',
        'status_changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $change): void {
            if (array_diff(array_keys($change->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('An inventory change operation is immutable; only its status may change.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Inventory changes are never deleted.'));
        // The queue's "not applying" banner caches fleet conflicts; any change write refreshes it after commit.
        self::saved(static fn () => DB::afterCommit(static fn () => rescue(static fn () => Cache::forget(ListInventoryCorrections::CONFLICTS_CACHE_KEY), report: false)));
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** @return BelongsTo<ApparatusInventoryCorrection, $this> */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(ApparatusInventoryCorrection::class, 'apparatus_inventory_correction_id');
    }

    /** @return BelongsTo<ApparatusInventoryChangeSet, $this> */
    public function changeSet(): BelongsTo
    {
        return $this->belongsTo(ApparatusInventoryChangeSet::class, 'apparatus_inventory_change_set_id');
    }
}
