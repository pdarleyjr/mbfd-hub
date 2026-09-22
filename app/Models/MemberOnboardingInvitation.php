<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Carbon\CarbonImmutable|null $sent_at
 * @property \Carbon\CarbonImmutable|null $redeemed_at
 * @property \Carbon\CarbonImmutable|null $consumed_at
 */
final class MemberOnboardingInvitation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'redeemed_binding_hash'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'employee_profile_id' => 'integer',
            'security_version' => 'integer',
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
