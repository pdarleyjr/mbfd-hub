<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail of controlled changes to the shared conversation: a post removed,
 * pinned or unpinned, with the actor and (for removals) the required reason.
 *
 * @property int $id
 * @property int $post_id
 * @property int $actor_user_id
 * @property string $action
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon $created_at
 */
final class CommandCommunicationAuditEvent extends Model
{
    public const ACTION_DELETED = 'deleted';

    public const ACTION_PINNED = 'pinned';

    public const ACTION_UNPINNED = 'unpinned';

    public const UPDATED_AT = null;

    protected $table = 'command_communication_audit_events';

    protected $fillable = ['post_id', 'actor_user_id', 'action', 'reason'];

    // Sub-second precision keeps the audit order exact for same-second events.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Communication audit events are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Communication audit events are append-only.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<CommandCommunicationPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(CommandCommunicationPost::class, 'post_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
