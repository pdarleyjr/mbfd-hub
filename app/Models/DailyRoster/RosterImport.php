<?php

declare(strict_types=1);

namespace App\Models\DailyRoster;

use App\Models\InboundEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ledger row for one TeleStaff email. It outlives the email itself, so it holds
 * provenance and counts only, never member names.
 */
final class RosterImport extends Model
{
    public const STATE_CLASSIFIED = 'classified';

    public const STATE_QUEUED = 'queued';

    public const STATE_PARSING = 'parsing';

    public const STATE_VALIDATED = 'validated';

    public const STATE_SHADOWED = 'shadowed';

    public const STATE_PUBLISHED = 'published';

    public const STATE_VERIFIED = 'verified';

    public const STATE_CLEANED = 'cleaned';

    public const STATE_DUPLICATE = 'duplicate';

    public const STATE_SUPERSEDED = 'superseded';

    public const STATE_REJECTED = 'rejected';

    public const STATE_NEEDS_REVIEW = 'needs_review';

    public const STATE_RETRYABLE_FAILED = 'retryable_failed';

    public const STATE_PERMANENT_FAILED = 'permanent_failed';

    public const OUTCOME_PUBLISHED = 'published';

    public const OUTCOME_CONFIRMED = 'confirmed';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_SUPERSEDED = 'superseded';

    public const CLEANUP_NONE = 'none';

    public const CLEANUP_PENDING = 'pending';

    public const CLEANUP_ROW_DELETED = 'row_deleted';

    public const CLEANUP_DONE = 'done';

    public const CLEANUP_KEPT_REPLIED = 'kept_replied';

    public const CLEANUP_FAILED = 'failed';

    protected $guarded = ['id'];

    protected $casts = [
        'state_changed_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'classification' => 'array',
        'duty_date' => 'immutable_date',
        'source_generated_at' => 'immutable_datetime',
        'other_blocks' => 'array',
        'warnings' => 'array',
        'next_attempt_at' => 'immutable_datetime',
        'lease_expires_at' => 'immutable_datetime',
        'last_dispatched_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
        'cleaned_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<InboundEmail, $this> */
    public function inboundEmail(): BelongsTo
    {
        return $this->belongsTo(InboundEmail::class);
    }

    /** @return BelongsTo<RosterSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(RosterSnapshot::class, 'roster_snapshot_id');
    }

    /** @return BelongsTo<RosterImport, $this> */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_import_id');
    }

    /** @return HasMany<RosterEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(RosterEvent::class);
    }
}
