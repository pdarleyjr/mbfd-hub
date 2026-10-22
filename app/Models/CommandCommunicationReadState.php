<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $last_read_at
 */
final class CommandCommunicationReadState extends Model
{
    public const CREATED_AT = null;

    protected $table = 'command_communication_read_states';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'last_read_at'];

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
