<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserIdentityLink extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'subject', 'provider_user_id', 'status',
        'security_state', 'last_synced_at', 'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'security_state' => 'encrypted:array',
            'last_synced_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
