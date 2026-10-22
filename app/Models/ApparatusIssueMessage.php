<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only conversation entry; corrections are new rows pointing at the original. */
final class ApparatusIssueMessage extends Model
{
    public const VISIBILITY_MEMBER = 'member';

    public const VISIBILITY_INTERNAL = 'internal';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Issue messages are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Issue messages are append-only.'));
    }

    public function scopeMemberVisible(Builder $query): Builder
    {
        return $query->where('visibility', self::VISIBILITY_MEMBER);
    }

    /** @return BelongsTo<ApparatusInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
    }

    /** @return BelongsTo<ApparatusDefect, $this> */
    public function defect(): BelongsTo
    {
        return $this->belongsTo(ApparatusDefect::class, 'apparatus_defect_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<self, $this> */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_message_id');
    }
}
