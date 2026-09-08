<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OidcSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['auth_code_id', 'access_token_id'];

    protected $casts = ['security_version' => 'integer', 'user_id' => 'integer', 'employee_profile_id' => 'integer', 'revoked_at' => 'datetime'];
}
