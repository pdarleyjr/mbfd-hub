<?php

declare(strict_types=1);

namespace App\Models\UnitStatus;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The credit ledger. A row is created once per (unit, call) and never deleted; a credit that stops counting is
 * voided with a reason. `duty_date` is a plain Y-m-d string on purpose (no date cast).
 *
 * @property string $unit_code
 * @property string $source_call_id
 * @property string|null $external_id
 * @property \Carbon\CarbonImmutable $attributed_at
 * @property string $attribution_basis
 * @property string $duty_date
 * @property bool $confirmed
 * @property string $provenance
 * @property \Carbon\CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 */
final class UnitStatusRun extends Model
{
    public const BASIS_RECEIVED = 'received';

    public const BASIS_FIRST_SEEN = 'first_seen';

    public const BASIS_UNIT_ADDED = 'unit_added';

    public const PROVENANCE_LIVE = 'live';

    public const PROVENANCE_BACKFILL = 'backfill';

    public const VOID_DUPLICATE = 'duplicate';

    public const VOID_MERGED = 'merged';

    protected $table = 'unit_status_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'confirmed' => 'boolean',
        'attributed_at' => 'immutable_datetime',
        'voided_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Unit Status runs are never deleted; void them instead.'));
    }
}
