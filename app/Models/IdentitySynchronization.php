<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IdentitySynchronization extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'requested_security_version', 'desired_active',
        'revoke_sessions', 'reset_mfa', 'state', 'attempts', 'last_error',
        'last_attempted_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_security_version' => 'integer',
            'desired_active' => 'boolean',
            'revoke_sessions' => 'boolean',
            'reset_mfa' => 'boolean',
            'attempts' => 'integer',
            'last_attempted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
