<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EmployeeProfileEvent extends Model
{
    protected $fillable = [
        'employee_id', 'actor_user_id', 'target_user_id', 'action', 'result', 'reason', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
