<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for human actions on a thread. Metadata holds ids and internal names only: never an address,
 * subject or body.
 *
 * @property array<string, mixed>|null $metadata
 */
final class MailThreadEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['thread_id', 'user_id', 'type', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
