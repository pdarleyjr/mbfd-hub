<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One equipment line of a handoff: what a named person said happened to one checklist item. It is a statement,
 * not a custody ledger, and it never edits templates, overlays, inspections or defects.
 *
 * @property int $id
 * @property int $apparatus_changeout_id
 * @property string $item_key
 * @property string|null $equipment_key
 * @property string|null $compartment_id
 * @property string|null $compartment_name
 * @property string $item_name
 * @property int|null $expected_quantity
 * @property string $handling
 * @property int|null $quantity_moved
 * @property string|null $condition
 * @property string|null $note
 * @property string $item_class
 * @property string $custody_status
 * @property int|null $verified_by_user_id
 * @property CarbonImmutable|null $verified_at
 * @property string|null $verification_note
 * @property int|null $apparatus_defect_id
 */
class ApparatusChangeoutItem extends Model
{
    public const HANDLING_TRANSFER = 'transfer';

    public const HANDLING_LEAVE = 'leave';

    public const HANDLING_REVIEW = 'review';

    public const HANDLINGS = [self::HANDLING_TRANSFER, self::HANDLING_LEAVE, self::HANDLING_REVIEW];

    public const CONDITION_OK = 'ok';

    public const CONDITION_MISSING = 'missing';

    public const CONDITION_DAMAGED = 'damaged';

    public const CONDITIONS = [self::CONDITION_OK, self::CONDITION_MISSING, self::CONDITION_DAMAGED];

    public const CLASS_STANDARD = 'standard';

    public const CLASS_CONTROLLED = 'controlled';

    public const CUSTODY_MOVED = 'moved';

    public const CUSTODY_LEFT_BEHIND = 'left_behind';

    public const CUSTODY_NEEDS_REVIEW = 'needs_review';

    public const CUSTODY_PENDING_VERIFICATION = 'pending_verification';

    public const CUSTODY_VERIFIED = 'verified';

    /** Lines that keep a handoff open. */
    public const OPEN_CUSTODY = [self::CUSTODY_NEEDS_REVIEW, self::CUSTODY_PENDING_VERIFICATION];

    protected $fillable = [
        'apparatus_changeout_id',
        'item_key',
        'equipment_key',
        'compartment_id',
        'compartment_name',
        'item_name',
        'expected_quantity',
        'handling',
        'quantity_moved',
        'condition',
        'note',
        'item_class',
        'custody_status',
        'verified_by_user_id',
        'verified_at',
        'verification_note',
        'apparatus_defect_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'integer',
            'quantity_moved' => 'integer',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->custody_status, self::OPEN_CUSTODY, true);
    }

    public function isControlled(): bool
    {
        return $this->item_class === self::CLASS_CONTROLLED;
    }

    /** @return BelongsTo<ApparatusChangeout, $this> */
    public function changeout(): BelongsTo
    {
        return $this->belongsTo(ApparatusChangeout::class, 'apparatus_changeout_id');
    }

    /** @return BelongsTo<ApparatusDefect, $this> */
    public function defect(): BelongsTo
    {
        return $this->belongsTo(ApparatusDefect::class, 'apparatus_defect_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
