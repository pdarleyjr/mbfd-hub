<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One identification question for one apparatus and field. Rows are created the first time a
 * question is asked. "answered" and "not_applicable" questions are never asked again until Fleet
 * reopens them; "open" ones (including temporarily unavailable ones) are asked at the next checkout.
 *
 * @property int $id
 * @property int $apparatus_id
 * @property string $field
 * @property string $state open|answered|not_applicable
 * @property string|null $last_reason
 * @property string|null $answered_value
 * @property \Carbon\CarbonImmutable|null $resolved_at
 * @property int|null $resolved_by_user_id
 */
class ApparatusIdentificationQuestion extends Model
{
    public const STATE_OPEN = 'open';

    public const STATE_ANSWERED = 'answered';

    public const STATE_NOT_APPLICABLE = 'not_applicable';

    public const STATE_LABELS = [
        self::STATE_OPEN => 'Open',
        self::STATE_ANSWERED => 'Answered',
        self::STATE_NOT_APPLICABLE => 'Not applicable',
    ];

    protected $fillable = [
        'apparatus_id',
        'field',
        'state',
        'last_reason',
        'answered_value',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function isClosed(): bool
    {
        return $this->state !== self::STATE_OPEN;
    }
}
