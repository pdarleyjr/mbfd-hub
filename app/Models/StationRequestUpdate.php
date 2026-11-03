<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StationRequestUpdate extends Model
{
    /** The one sender name the member and staff see on an assistant receipt. */
    public const AUTO_RECEIPT_SENDER = 'AI Response';

    public const AUTO_RECEIPT_EVENT = 'auto_receipt';

    protected $fillable = [
        'station_request_id',
        'status',
        'public_note',
        'internal_note',
        'changed_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Station request updates are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Station request updates are append-only.'));
    }

    /** @return BelongsTo<StationRequest, $this> */
    public function stationRequest(): BelongsTo
    {
        return $this->belongsTo(StationRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /** The assistant's "received" receipt: never a staff action and never a status change. */
    public function isAutoReceipt(): bool
    {
        // The column alone decides: it is set only by the AI Response writer and is not mass-assignable.
        return $this->getAttribute('automation_intake_id') !== null;
    }

    /** Sender shown beside the update, or null for a normal staff/system row (callers keep their own wording). */
    public function senderLabel(): ?string
    {
        return $this->isAutoReceipt() ? self::AUTO_RECEIPT_SENDER : null;
    }

    /**
     * Rows that carry a human decision or the original submission. Concurrency tokens and "has staff answered"
     * checks read this, so an assistant receipt can never cause a false conflict.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutAutoReceipts(Builder $query): Builder
    {
        return $query->whereNull('automation_intake_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAutoReceipts(Builder $query): Builder
    {
        return $query->whereNotNull('automation_intake_id');
    }
}
