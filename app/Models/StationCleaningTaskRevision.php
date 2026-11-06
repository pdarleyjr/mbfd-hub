<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit record of one admin change to a cleaning task: who, when, and the audited fields before and
 * after. Never updated or deleted.
 *
 * @property int $id
 * @property int $station_cleaning_task_id
 * @property string $action
 * @property array<string, mixed>|null $before
 * @property array<string, mixed> $after
 * @property int|null $changed_by_user_id
 * @property string|null $changed_by_name
 */
final class StationCleaningTaskRevision extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const UPDATED_AT = null;

    protected $fillable = [
        'station_cleaning_task_id',
        'action',
        'before',
        'after',
        'changed_by_user_id',
        'changed_by_name',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Cleaning schedule revisions are append-only.');
        });
        self::deleting(static function (): void {
            throw new LogicException('Cleaning schedule revisions are append-only.');
        });
    }

    /** @return BelongsTo<StationCleaningTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(StationCleaningTask::class, 'station_cleaning_task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
