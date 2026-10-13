<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employee_profile_id
 * @property string $employee_id
 * @property string|null $dob_digest
 * @property string|null $phone_digest
 * @property int $failed_attempts
 * @property \Carbon\CarbonImmutable|null $locked_at
 * @property \Carbon\CarbonImmutable|null $consumed_at
 */
final class MemberActivationVerifier extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['dob_digest', 'phone_digest'];

    protected function casts(): array
    {
        return [
            'employee_profile_id' => 'integer',
            'failed_attempts' => 'integer',
            'approved_at' => 'immutable_datetime',
            'last_failed_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->locked_at === null
            && is_string($this->dob_digest)
            && is_string($this->phone_digest);
    }
}
