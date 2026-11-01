<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One equipment line of a changeout: a named person said it was taken to the new truck, or left on the old one. It is a
 * statement, not a custody ledger, and it never edits templates, overlays, inspections or defects.
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
 * @property string|null $note
 * @property string $custody_status
 */
class ApparatusChangeoutItem extends Model
{
    public const HANDLING_TRANSFER = 'transfer';

    public const HANDLING_LEAVE = 'leave';

    public const HANDLINGS = [self::HANDLING_TRANSFER, self::HANDLING_LEAVE];

    public const CUSTODY_MOVED = 'moved';

    public const CUSTODY_LEFT_BEHIND = 'left_behind';

    protected $fillable = [
        'apparatus_changeout_id',
        'item_key',
        'equipment_key',
        'compartment_id',
        'compartment_name',
        'item_name',
        'expected_quantity',
        'handling',
        'note',
        'custody_status',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'integer',
        ];
    }

    public function isLeftBehind(): bool
    {
        return $this->custody_status === self::CUSTODY_LEFT_BEHIND;
    }

    /** @return BelongsTo<ApparatusChangeout, $this> */
    public function changeout(): BelongsTo
    {
        return $this->belongsTo(ApparatusChangeout::class, 'apparatus_changeout_id');
    }
}
