<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of identification answers and what was decided with them.
 *
 * @property int $id
 * @property int $apparatus_id
 * @property string $field
 * @property string $event answered|unavailable|superseded|reopened|matched|master_created|master_filled|conflict|confirmed
 * @property string|null $value
 * @property string|null $reason
 * @property string|null $note
 * @property int|null $actor_user_id
 * @property int|null $apparatus_inspection_id
 * @property string|null $client_submission_id
 * @property array<string, mixed>|null $metadata
 * @property \Carbon\CarbonImmutable $created_at
 */
class ApparatusIdentificationEvent extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_ANSWERED = 'answered';

    public const EVENT_UNAVAILABLE = 'unavailable';

    public const EVENT_SUPERSEDED = 'superseded';

    public const EVENT_REOPENED = 'reopened';

    public const EVENT_MATCHED = 'matched';

    public const EVENT_MASTER_CREATED = 'master_created';

    public const EVENT_MASTER_FILLED = 'master_filled';

    public const EVENT_CONFLICT = 'conflict';

    public const EVENT_CONFIRMED = 'confirmed';

    public const EVENT_LABELS = [
        self::EVENT_ANSWERED => 'Answered',
        self::EVENT_UNAVAILABLE => 'Not available',
        self::EVENT_SUPERSEDED => 'Superseded',
        self::EVENT_REOPENED => 'Asked again',
        self::EVENT_MATCHED => 'Matched to a vehicle record',
        self::EVENT_MASTER_CREATED => 'Vehicle record created',
        self::EVENT_MASTER_FILLED => 'Vehicle record filled in',
        self::EVENT_CONFLICT => 'Needs Fleet review',
        self::EVENT_CONFIRMED => 'Confirmed',
    ];

    protected $fillable = [
        'apparatus_id',
        'field',
        'event',
        'value',
        'reason',
        'note',
        'actor_user_id',
        'apparatus_inspection_id',
        'client_submission_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Identification events are append-only.'));
        static::deleting(fn () => throw new LogicException('Identification events are append-only.'));
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
