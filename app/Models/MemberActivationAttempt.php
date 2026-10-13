<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $employee_profile_id
 * @property int $failed_attempts
 * @property \Carbon\CarbonImmutable|null $locked_at
 * @property \Carbon\CarbonImmutable|null $activated_at
 */
final class MemberActivationAttempt extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['failed_attempts' => 0];

    protected function casts(): array
    {
        return [
            'employee_profile_id' => 'integer',
            'failed_attempts' => 'integer',
            'last_failed_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
        ];
    }
}
