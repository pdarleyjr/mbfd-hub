<?php

declare(strict_types=1);

namespace App\Models\Training;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Where one exact version of a plan was sent. One row per version and destination, so retrying can never
 * send twice. Only its delivery state changes; rows are never removed, and they outlive the draft. They
 * name no members; the message or update they point to holds what people actually read.
 */
final class TrainingCrewPlanPublication extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Longer than the publish lock, so a live send is never offered for retry. */
    private const SENDING_GIVES_UP_MINUTES = 5;

    protected $table = 'training_crew_plan_publications';

    protected $guarded = ['id'];

    protected $casts = ['sent_at' => 'immutable_datetime', 'class_date' => 'immutable_date', 'member_count' => 'integer', 'attempts' => 'integer'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Crew plan publications are permanent.'));
    }

    /** It failed, or a send stopped partway and never came back. Sending again cannot duplicate it. */
    public function needsRetry(): bool
    {
        return $this->status === self::STATUS_FAILED
            || ($this->status === self::STATUS_SENDING && $this->updated_at !== null && $this->updated_at->lt(now()->subMinutes(self::SENDING_GIVES_UP_MINUTES)));
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
