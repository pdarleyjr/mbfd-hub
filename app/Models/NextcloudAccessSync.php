<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class NextcloudAccessSync extends Model
{
    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer', 'requested_revision' => 'integer', 'applied_revision' => 'integer',
        'security_version' => 'integer', 'desired_enabled' => 'boolean', 'verified_at' => 'immutable_datetime',
    ];
}
