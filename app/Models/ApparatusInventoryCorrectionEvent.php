<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail and conversation of one inventory correction.
 * `visibility=internal` rows are Logistics-only and never reach a member projection.
 */
final class ApparatusInventoryCorrectionEvent extends Model
{
    public const VISIBILITY_MEMBER = 'member';

    public const VISIBILITY_INTERNAL = 'internal';

    public const SUBMITTED = 'submitted';

    public const CLARIFICATION_REQUESTED = 'clarification_requested';

    public const MEMBER_REPLIED = 'member_replied';

    public const LOGISTICS_REPLIED = 'logistics_replied';

    public const INTERNAL_NOTE = 'internal_note';

    public const APPROVED = 'approved';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const REVERSED = 'reversed';

    public const LINKED_TO_INSPECTION = 'linked_to_inspection';

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Inventory correction events are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Inventory correction events are append-only.'));
    }

    /** @param Builder<self> $query */
    public function scopeMemberVisible(Builder $query): void
    {
        $query->where('visibility', self::VISIBILITY_MEMBER);
    }

    /** @return BelongsTo<ApparatusInventoryCorrection, $this> */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(ApparatusInventoryCorrection::class, 'apparatus_inventory_correction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
