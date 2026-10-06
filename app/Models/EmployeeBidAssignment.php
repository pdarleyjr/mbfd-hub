<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable source revision; only the current projection's supersession changes.
 *
 * @property int $payload_version
 * @property int $bid_year
 * @property int|null $source_sequence
 * @property string $bid_session_id
 * @property string $payload_hash
 * @property string $assignment_source
 */
final class EmployeeBidAssignment extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'payload_version' => 'integer',
            'bid_year' => 'integer',
            'source_sequence' => 'integer',
            'is_forced' => 'boolean',
            'picked_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $assignment): void {
            if ($assignment->getOriginal('superseded_at') !== null
                || array_diff(array_keys($assignment->getDirty()), ['superseded_at', 'updated_at']) !== []
                || $assignment->superseded_at === null) {
                throw new \LogicException('Bid assignment revisions are immutable.');
            }
        });
        self::deleting(function (): never {
            throw new \LogicException('Bid assignment history cannot be deleted.');
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_profile_id');
    }
}
