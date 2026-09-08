<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OidcAccountLink extends Model
{
    protected $guarded = [];

    protected $casts = ['user_id' => 'integer', 'employee_profile_id' => 'integer'];
}
