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

    protected $table = 'training_crew_plan_publications';

    protected $guarded = ['id'];

    protected $casts = ['sent_at' => 'immutable_datetime', 'class_date' => 'immutable_date', 'member_count' => 'integer', 'attempts' => 'integer'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Crew plan publications are permanent.'));
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
