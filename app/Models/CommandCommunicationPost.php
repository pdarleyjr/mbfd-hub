<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry in the shared 300 Command conversation. Immutable record: no edit
 * or delete path exists. Replies are one level deep (parent_id of a top-level post).
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $parent_id
 * @property int $author_user_id
 * @property string $author_context
 * @property string|null $subject
 * @property string $body
 * @property string $client_submission_id
 * @property \Illuminate\Support\Carbon|null $pinned_at
 * @property int|null $pinned_by_user_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class CommandCommunicationPost extends Model
{
    public const CONTEXT_COMMAND = 'command';

    public const CONTEXT_ADMINISTRATION = 'administration';

    protected $table = 'command_communication_posts';

    protected $fillable = [
        'public_id', 'parent_id', 'author_user_id', 'author_context', 'subject', 'body',
        'client_submission_id', 'pinned_at', 'pinned_by_user_id',
    ];

    // Sub-second precision keeps unread ordering exact for same-second posts.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['pinned_at' => 'datetime'];
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

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }
}
