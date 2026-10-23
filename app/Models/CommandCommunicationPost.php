<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry in the shared 300 Command / Captain 5 conversation. There is no edit path; the only
 * removal is the controlled soft delete (actor, reason and an append-only audit event), which
 * leaves a tombstone so reply threads stay intact. Replies are one level deep (parent_id of a
 * top-level post) and always carry their parent's audience.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $parent_id
 * @property int $author_user_id
 * @property string $author_context
 * @property string $audience
 * @property string|null $subject
 * @property string $body
 * @property string $client_submission_id
 * @property \Illuminate\Support\Carbon|null $pinned_at
 * @property int|null $pinned_by_user_id
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property int|null $deleted_by_user_id
 * @property string|null $deletion_reason
 * @property \Illuminate\Support\Carbon $created_at
 */
final class CommandCommunicationPost extends Model
{
    public const CONTEXT_COMMAND = 'command';

    public const CONTEXT_ADMINISTRATION = 'administration';

    public const CONTEXT_CAPTAIN5 = 'captain5';

    public const CHANNEL_COMMAND_300 = 'command_300';

    public const CHANNEL_CAPTAIN_5 = 'captain_5';

    public const AUDIENCE_COMMAND_300 = 'command_300';

    public const AUDIENCE_CAPTAIN_5 = 'captain_5';

    public const AUDIENCE_BOTH = 'both';

    public const CHANNELS = [self::CHANNEL_COMMAND_300, self::CHANNEL_CAPTAIN_5];

    public const AUDIENCES = [self::AUDIENCE_COMMAND_300, self::AUDIENCE_CAPTAIN_5, self::AUDIENCE_BOTH];

    protected $table = 'command_communication_posts';

    protected $fillable = [
        'public_id', 'parent_id', 'author_user_id', 'author_context', 'audience', 'subject', 'body',
        'client_submission_id', 'pinned_at', 'pinned_by_user_id',
        'deleted_at', 'deleted_by_user_id', 'deletion_reason',
    ];

    // Sub-second precision keeps unread ordering exact for same-second posts.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['pinned_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    /** Audiences whose posts appear in the given channel's feed. @return list<string> */
    public static function audiencesFor(string $channel): array
    {
        return match ($channel) {
            self::CHANNEL_COMMAND_300 => [self::AUDIENCE_COMMAND_300, self::AUDIENCE_BOTH],
            self::CHANNEL_CAPTAIN_5 => [self::AUDIENCE_CAPTAIN_5, self::AUDIENCE_BOTH],
            default => throw new \InvalidArgumentException('Unknown communications channel.'),
        };
    }

    /** Channels an audience is shown in. @return list<string> */
    public static function channelsFor(string $audience): array
    {
        return match ($audience) {
            self::AUDIENCE_COMMAND_300 => [self::CHANNEL_COMMAND_300],
            self::AUDIENCE_CAPTAIN_5 => [self::CHANNEL_CAPTAIN_5],
            self::AUDIENCE_BOTH => self::CHANNELS,
            default => throw new \InvalidArgumentException('Unknown audience.'),
        };
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<CommandCommunicationAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(CommandCommunicationAttachment::class, 'post_id')->orderBy('id');
    }

    /** @return HasMany<CommandCommunicationAuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(CommandCommunicationAuditEvent::class, 'post_id')->orderBy('id');
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function isShownIn(string $channel): bool
    {
        return in_array($this->audience, self::audiencesFor($channel), true);
    }
}
