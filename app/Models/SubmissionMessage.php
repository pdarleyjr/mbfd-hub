<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One message in the conversation on a station request, station supply request or apparatus service ticket
 * (docs/member-replies.md). Append-only. Every row is visible to the participants of the submission and to
 * staff: internal notes are never written here, they stay in each workflow's own `internal_note` / `admin_notes`.
 *
 * @property string $subject_type
 * @property int $subject_id
 * @property string $author_side
 * @property string $body
 */
final class SubmissionMessage extends Model
{
    public const SIDE_MEMBER = 'member';

    public const SIDE_STAFF = 'staff';

    protected $guarded = ['id'];

    protected $casts = [
        'subject_id' => 'integer',
        'author_user_id' => 'integer',
        'author_employee_id' => 'integer',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Submission messages are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Submission messages are append-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForSubject(Builder $query, string $type, int $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    public function isFromStaff(): bool
    {
        return $this->author_side === self::SIDE_STAFF;
    }
}
