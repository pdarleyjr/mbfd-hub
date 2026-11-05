<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a member last opened one item from "My activity" (key such as "inspection:123"). It only decides
 * whether a newer update from someone else shows as unread; it is never shown to staff.
 */
final class MemberActivityRead extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['seen_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
