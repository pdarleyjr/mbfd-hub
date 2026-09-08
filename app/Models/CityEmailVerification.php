<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Carbon\CarbonImmutable $acknowledged_at
 * @property \Carbon\CarbonImmutable|null $token_expires_at
 * @property \Carbon\CarbonImmutable|null $sent_at
 * @property \Carbon\CarbonImmutable|null $verified_at
 */
final class CityEmailVerification extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'immutable_datetime',
            'token_expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'security_version' => 'integer',
            'user_id' => 'integer',
            'employee_profile_id' => 'integer',
        ];
    }
}
