<?php

declare(strict_types=1);

namespace App\Models\UnitStatus;

use Illuminate\Database\Eloquent\Model;

/**
 * The single sync-state row (id = 1).
 *
 * @property \Carbon\CarbonImmutable $tracking_starts_at
 * @property \Carbon\CarbonImmutable|null $first_synced_at
 * @property \Carbon\CarbonImmutable|null $cursor_updated_since
 * @property string|null $cursor_after_id
 * @property \Carbon\CarbonImmutable|null $last_attempt_at
 * @property \Carbon\CarbonImmutable|null $last_success_at
 * @property string|null $last_error_class
 * @property int $consecutive_failures
 * @property \Carbon\CarbonImmutable|null $circuit_open_until
 * @property string|null $source_state
 * @property array<string, mixed>|null $source_health
 * @property \Carbon\CarbonImmutable|null $source_checked_at
 * @property \Carbon\CarbonImmutable|null $last_merge_check_at
 * @property int $rows_skipped_total
 */
final class UnitStatusSyncState extends Model
{
    public const ROW_ID = 1;

    protected $table = 'unit_status_sync_state';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'tracking_starts_at' => 'immutable_datetime',
        'first_synced_at' => 'immutable_datetime',
        'cursor_updated_since' => 'immutable_datetime',
        'last_attempt_at' => 'immutable_datetime',
        'last_success_at' => 'immutable_datetime',
        'circuit_open_until' => 'immutable_datetime',
        'source_checked_at' => 'immutable_datetime',
        'last_merge_check_at' => 'immutable_datetime',
        'source_health' => 'array',
        'consecutive_failures' => 'integer',
        'rows_skipped_total' => 'integer',
    ];

    public static function current(): ?self
    {
        return self::query()->find(self::ROW_ID);
    }
}
