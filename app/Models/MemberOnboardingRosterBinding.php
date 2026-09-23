<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MemberOnboardingRosterBinding extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'employee_profile_id' => 'integer',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
