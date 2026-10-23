<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Last-read marker of one user in the Captain 5 channel. The 300 channel keeps its original
 * `command_communication_read_states` table so the 300 page is untouched.
 *
 * @property int $user_id
 * @property string $channel
 * @property \Illuminate\Support\Carbon $last_read_at
 */
final class CommandCommunicationChannelRead extends Model
{
    public const CREATED_AT = null;

    protected $table = 'command_communication_channel_reads';

    protected $fillable = ['user_id', 'channel', 'last_read_at'];

    // Sub-second precision keeps unread ordering exact for same-second posts.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['last_read_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
