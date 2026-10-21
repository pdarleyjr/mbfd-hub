<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field-level change to a master vehicle. "applied" rows already reached the
 * master record; "proposed" rows are contradictions held for a Fleet decision.
 */
class UnitMasterVehicleChange extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_FY27 = 'fy27_reconciliation';

    public const SOURCE_TAKE_HOME = 'take_home_inspection';

    public const SOURCE_TAKE_HOME_NEW = 'take_home_new_vehicle';

    protected $fillable = [
        'unit_master_vehicle_id',
        'field',
        'old_value',
        'new_value',
        'status',
        'source',
        'source_reference',
        'take_home_vehicle_inspection_id',
        'user_id',
        'decided_by_user_id',
        'decided_at',
        'note',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    /** @return BelongsTo<UnitMasterVehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(UnitMasterVehicle::class, 'unit_master_vehicle_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** @return BelongsTo<TakeHomeVehicleInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(TakeHomeVehicleInspection::class, 'take_home_vehicle_inspection_id');
    }

    /**
     * @param  Builder<UnitMasterVehicleChange>  $query
     * @return Builder<UnitMasterVehicleChange>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROPOSED);
    }

    public function fieldLabel(): string
    {
        return UnitMasterVehicle::FIELD_LABELS[$this->field] ?? $this->field;
    }
}
