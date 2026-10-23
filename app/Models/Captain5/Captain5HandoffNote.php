<?php

declare(strict_types=1);

namespace App\Models\Captain5;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Shift handoff note between Captain 5 duty days. Append-only; acknowledgement is set once.
 *
 * @property int $id
 * @property \Carbon\CarbonImmutable $duty_date
 * @property int $author_user_id
 * @property string $author_authority
 * @property string $body
 * @property int|null $acknowledged_by_user_id
 * @property \Carbon\CarbonImmutable|null $acknowledged_at
 */
final class Captain5HandoffNote extends Model
{
    protected $table = 'captain5_handoff_notes';

    protected $guarded = ['id'];

    private const MUTABLE = ['acknowledged_by_user_id', 'acknowledged_at', 'updated_at'];

    protected $casts = [
        'duty_date' => 'immutable_date',
        'acknowledged_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $note): void {
            if ($note->getOriginal('acknowledged_at') !== null
                || array_diff(array_keys($note->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Handoff notes are append-only.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Handoff notes are never deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }
}
