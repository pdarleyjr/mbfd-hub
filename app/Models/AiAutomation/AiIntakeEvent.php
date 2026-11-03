<?php

declare(strict_types=1);

namespace App\Models\AiAutomation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger of one automated response per source event. The unique (source_type, source_id, event_key)
 * key makes the commit hook, the sweeper and the backlog collapse onto one row.
 */
class AiIntakeEvent extends Model
{
    public const STATE_QUEUED = 'queued';

    public const STATE_CLAIMED = 'claimed';

    public const STATE_RETRY = 'retry';

    public const STATE_SENT = 'sent';

    public const STATE_SKIPPED = 'skipped';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_FAILED = 'failed';

    /** States that may still produce a member-visible write. */
    public const OPEN_STATES = [self::STATE_QUEUED, self::STATE_CLAIMED, self::STATE_RETRY];

    public const ORIGIN_LIVE = 'live';

    public const ORIGIN_BACKLOG = 'backlog';

    protected $table = 'ai_intake_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reason_codes' => 'array',
            'knowledge_refs' => 'array',
            'needs_human' => 'boolean',
            'lease_expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiBacklogRun, $this> */
    public function backlogRun(): BelongsTo
    {
        return $this->belongsTo(AiBacklogRun::class, 'backlog_run_id');
    }

    /** @return BelongsTo<AiModelCall, $this> */
    public function modelCall(): BelongsTo
    {
        return $this->belongsTo(AiModelCall::class, 'model_call_id');
    }
}
