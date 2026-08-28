<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Immutable trust boundary for Daily Checkout readiness. This is deliberately
 * separate from apparatus operational-status events: it records what was
 * observed when the ledger became authoritative, not a historical transition.
 */
final class DailyCheckoutLedgerCutover extends Model
{
    public const LEDGER = 'daily_checkout';

    public const SOURCE = 'owner_beta_activation';

    protected $fillable = [
        'ledger',
        'release_sha',
        'source',
        'activated_at',
        'apparatus_status_snapshot',
        'snapshot_sha256',
        'apparatus_count',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'immutable_datetime',
            'apparatus_status_snapshot' => 'array',
            'apparatus_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Daily Checkout ledger cutovers are immutable.'));
        self::deleting(static fn (): never => throw new LogicException('Daily Checkout ledger cutovers are immutable.'));
    }
}
