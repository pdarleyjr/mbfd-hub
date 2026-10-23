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

    /** Who the author spoke as, snapshotted at write time (never recomputed from current roles). */
    public const CONTEXT_LOGISTICS = 'logistics';

    public const CONTEXT_MEMBER = 'member';

    /** Captain 5 EMS Operations Center (docs/captain5-ems-operations-center.md). */
    public const CONTEXT_CAPTAIN5 = 'captain5';

    /** Contexts that speak for the department (Logistics or Captain 5), not for a crew member. */
    public const STAFF_CONTEXTS = [self::CONTEXT_LOGISTICS, self::CONTEXT_CAPTAIN5];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Issue messages are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Issue messages are append-only.'));
    }

    public function scopeFromLogistics(Builder $query): Builder
    {
        return $query->where('author_context', self::CONTEXT_LOGISTICS);
    }

    public function isFromLogistics(): bool
    {
        return $this->author_context === self::CONTEXT_LOGISTICS;
    }

    /** Logistics or Captain 5 (the department speaking to members). */
    public function scopeFromStaff(Builder $query): Builder
    {
        return $query->whereIn('author_context', self::STAFF_CONTEXTS);
    }

    public function isFromCaptain5(): bool
    {
        return $this->author_context === self::CONTEXT_CAPTAIN5;
    }

    public function isFromStaff(): bool
    {
        return in_array($this->author_context, self::STAFF_CONTEXTS, true);
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
